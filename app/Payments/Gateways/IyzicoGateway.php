<?php

namespace App\Payments\Gateways;

use App\Models\PaymentOrder;
use App\Models\Payout;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\Data\Checkout;
use App\Payments\Data\RefundResult;
use App\Payments\Data\TransferResult;
use App\Payments\Data\WebhookResult;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * iyzico Ödeme Formu (Checkout Form) adaptörü.
 *  - Ödeme: initialize → kullanıcı iyzico sayfasına yönlendirilir → iyzico kullanıcıyı callbackUrl'e
 *    token ile POST eder → token sunucudan sorgulanır (retrieve) → sipariş "paid".
 *  - Pazaryeri ürünü açıksa (iyzico_marketplace) navlun sepet kalemi şoförün alt üye işyerine bağlanır,
 *    teslimat onayında kalem onayı (item approve) ile tutar şoföre aktarılır.
 * Anahtarlar panelden (iyzico_api_key / iyzico_secret_key) ya da .env'den okunur.
 */
final class IyzicoGateway implements PaymentGateway
{
    public const LIVE_URL = 'https://api.iyzipay.com';

    public const SANDBOX_URL = 'https://sandbox-api.iyzipay.com';

    public const PATH_INITIALIZE = '/payment/iyzipos/checkoutform/initialize/auth/ecom';

    public const PATH_RETRIEVE = '/payment/iyzipos/checkoutform/auth/ecom/detail';

    public const PATH_REFUND = '/payment/refund';

    public const PATH_SUBMERCHANT = '/onboarding/submerchant';

    public const PATH_SUBMERCHANT_RETRIEVE = '/onboarding/submerchant/retrieve';

    public const PATH_BIN_CHECK = '/payment/bin/check';

    public const PATH_APPROVE = '/payment/iyzipos/item/approve';

    public function id(): string
    {
        return 'iyzico';
    }

    public function label(): string
    {
        return 'iyzico (Ödeme Formu)';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '' && $this->secretKey() !== '';
    }

    public function isSandbox(): bool
    {
        return Settings::bool('iyzico_sandbox');
    }

    public function baseUrl(): string
    {
        return $this->isSandbox() ? self::SANDBOX_URL : self::LIVE_URL;
    }

    public function createCheckout(PaymentOrder $order, array $context): Checkout
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Ödeme altyapısı henüz etkin değil.');
        }

        $user = $order->user;
        $amount = number_format((float) $order->amount, 2, '.', '');
        $phone = preg_replace('/\D/', '', (string) $user->phone);
        $gsm = $phone !== '' ? '+90'.ltrim($phone, '0') : '+905000000000';
        // Alıcı kimliği: bireyselde TC (11 hane), kurumsalda VKN (10 hane). Sahte "11111111111" hiç gönderilmez (iyzico canlıda reddeder,
        // e-fatura/mutabakat bozulur); eksikse ödeme açılmaz ve kullanıcı profiline yönlendirilir.
        $identity = preg_replace('/\D/', '', (string) ($context['identity_number'] ?? ''));
        if (! in_array(strlen($identity), [10, 11], true)) {
            throw new RuntimeException('Ödeme için kimlik bilgisi eksik: bireysel hesapta T.C. kimlik numarası, kurumsal hesapta vergi numarası profilinizde kayıtlı olmalı.');
        }
        $address = mb_substr((string) ($context['address'] ?? 'Türkiye'), 0, 200);
        $city = mb_substr((string) ($context['city'] ?? 'İstanbul'), 0, 60);
        $contactName = mb_substr((string) $user->full_name, 0, 100);

        $item = [
            'id' => 'ORD-'.$order->id,
            'name' => mb_substr((string) ($context['description'] ?? 'NavlunIQ'), 0, 100),
            'category1' => $order->purpose === 'subscription' ? 'Üyelik' : 'Nakliye hizmeti',
            'itemType' => 'VIRTUAL',
            'price' => $amount,
        ];
        if (! empty($context['sub_merchant_ref']) && ! empty($context['sub_merchant_price']) && $this->supportsSubMerchants()) {
            $item['subMerchantKey'] = (string) $context['sub_merchant_ref'];
            $item['subMerchantPrice'] = number_format((float) $context['sub_merchant_price'], 2, '.', '');
        }

        $payload = [
            'locale' => 'tr',
            'conversationId' => $order->merchant_oid,
            'price' => $amount,
            'paidPrice' => $amount,
            'currency' => 'TRY',
            'basketId' => $order->merchant_oid,
            'paymentGroup' => 'PRODUCT',
            'callbackUrl' => route('payment.webhook', ['provider' => 'iyzico']),
            'enabledInstallments' => [1],
            'buyer' => [
                'id' => (string) $user->id,
                'name' => mb_substr((string) $user->first_name, 0, 50) ?: 'Ad',
                'surname' => mb_substr((string) $user->last_name, 0, 50) ?: 'Soyad',
                'gsmNumber' => $gsm,
                'email' => (string) $user->email,
                'identityNumber' => $identity,
                'registrationAddress' => $address,
                'ip' => (string) ($context['ip'] ?? '127.0.0.1'),
                'city' => $city,
                'country' => 'Turkey',
            ],
            'shippingAddress' => ['contactName' => $contactName, 'city' => $city, 'country' => 'Turkey', 'address' => $address],
            'billingAddress' => ['contactName' => $contactName, 'city' => $city, 'country' => 'Turkey', 'address' => $address],
            'basketItems' => [$item],
        ];

        $data = $this->request(self::PATH_INITIALIZE, $payload);
        if (($data['status'] ?? '') !== 'success' || empty($data['paymentPageUrl']) || empty($data['token'])) {
            Log::error('iyzico ödeme formu başlatılamadı.', ['order' => $order->id, 'response' => $data]);
            // Hata kodu da yazılır: iyzico'nun genel mesajı ("Şu anda işleminizi gerçekleştiremiyoruz") tek başına sebebi söylemez.
            throw new RuntimeException('Ödeme sağlayıcısından yanıt alınamadı: '.trim(($data['errorCode'] ?? '').' '.($data['errorMessage'] ?? 'bilinmeyen hata')).(isset($data['errorGroup']) ? ' ('.$data['errorGroup'].')' : ''));
        }

        $order->update(['status' => 'pending', 'request_snapshot' => $payload]);

        return new Checkout('redirect', (string) $data['paymentPageUrl'], (string) $data['token'], (int) ($data['tokenExpireTime'] ?? 1800));
    }

    /**
     * Callback (kullanıcının tarayıcısı, POST token) ya da iyzico webhook (JSON, iyziEventType) tek yerden:
     * token ile ödeme sonucu sunucudan sorgulanır; kimlik doğrulama bu sorgunun kendisidir.
     */
    public function parseWebhook(Request $request): WebhookResult
    {
        $token = (string) ($request->input('token') ?: $request->input('paymentConversationId', ''));
        $fromBrowser = ! $request->has('iyziEventType');

        if ($token === '' || ! $this->isConfigured()) {
            return new WebhookResult(false, '', 'failed', null, 'iyzico:invalid', $request->all(), null, 'Token yok', 'OK', 'INVALID', $fromBrowser);
        }

        $data = $this->request(self::PATH_RETRIEVE, ['locale' => 'tr', 'token' => $token]);
        $oid = (string) ($data['basketId'] ?? $data['conversationId'] ?? '');
        if (($data['status'] ?? '') !== 'success' || $oid === '') {
            Log::warning('iyzico ödeme sorgusu başarısız.', ['status' => $data['status'] ?? null, 'error' => $data['errorMessage'] ?? ($data['errorCode'] ?? null)]); // kart sahibi/e-posta günlüğe yazılmaz

            return new WebhookResult(false, $oid, 'failed', null, 'iyzico:'.$token, $data, null, $data['errorMessage'] ?? 'Sorgu başarısız', 'OK', 'INVALID', $fromBrowser);
        }

        $paid = strtoupper((string) ($data['paymentStatus'] ?? '')) === 'SUCCESS';
        $paymentId = (string) ($data['paymentId'] ?? '');
        $txId = (string) ($data['itemTransactions'][0]['paymentTransactionId'] ?? '');

        return new WebhookResult(
            valid: true,
            merchantOid: $oid,
            status: $paid ? 'success' : 'failed',
            paidAmount: isset($data['paidPrice']) ? (float) $data['paidPrice'] : null,
            eventId: 'iyzico:'.($paymentId !== '' ? $paymentId : $token).':'.($paid ? 'success' : 'failed'),
            payload: $data,
            providerReference: $paymentId !== '' ? $paymentId.':'.$txId : null,
            failureMessage: $paid ? null : trim(($data['errorCode'] ?? '').' '.($data['errorMessage'] ?? 'Ödeme tamamlanmadı')),
            ackBody: 'OK',
            rejectBody: 'INVALID',
            redirectUser: $fromBrowser,
        );
    }

    public function refund(PaymentOrder $order, float $amount): RefundResult
    {
        if (! $this->isConfigured()) {
            return new RefundResult(false, '', 'Ödeme altyapısı tanımlı değil.');
        }
        $txId = $this->transactionId($order);
        if ($txId === '') {
            return new RefundResult(false, '', 'İşlem kimliği (paymentTransactionId) bulunamadı.');
        }

        $data = $this->request(self::PATH_REFUND, [
            'locale' => 'tr',
            'conversationId' => $order->merchant_oid,
            'paymentTransactionId' => $txId,
            'price' => number_format($amount, 2, '.', ''),
            'currency' => 'TRY',
            'ip' => '127.0.0.1',
        ]);
        $ok = ($data['status'] ?? '') === 'success';

        return new RefundResult($ok, json_encode($data, JSON_UNESCAPED_UNICODE) ?: '', $ok ? null : ($data['errorMessage'] ?? 'İade isteği reddedildi'));
    }

    public function supportsSubMerchants(): bool
    {
        return Settings::bool('iyzico_marketplace');
    }

    /**
     * Şoförü alt üye işyeri olarak kaydeder; subMerchantKey döner.
     * $data: name, surname, email, phone, iban, identity (TC) ya da tax_no (VKN) + legal_type (individual|company), address, external_id.
     * Kimlik yoksa kayıt yapılmaz (sahte TC gönderilmez): null döner, hakediş kuyrukta kalır.
     */
    public function registerSubMerchant(array $data): ?string
    {
        if (! $this->isConfigured() || ! $this->supportsSubMerchants()) {
            return null;
        }
        $identity = preg_replace('/\D/', '', (string) ($data['identity'] ?? ''));
        $taxNo = preg_replace('/\D/', '', (string) ($data['tax_no'] ?? ''));
        $company = ($data['legal_type'] ?? 'individual') === 'company';
        if ($company ? strlen($taxNo) !== 10 : strlen($identity) !== 11) {
            Log::warning('iyzico alt üye işyeri kaydı: kimlik numarası eksik, kayıt yapılmadı.', ['external_id' => $data['external_id'] ?? null]);

            return null;
        }
        $taxOffice = trim((string) ($data['tax_office'] ?? ''));
        if ($company && $taxOffice === '') {
            Log::warning('iyzico alt üye işyeri kaydı: şirket için vergi dairesi eksik, kayıt yapılmadı.', ['external_id' => $data['external_id'] ?? null]);

            return null;
        }
        $phone = preg_replace('/\D/', '', (string) ($data['phone'] ?? ''));
        $fullName = trim(($data['name'] ?? '').' '.($data['surname'] ?? ''));
        $externalId = (string) ($data['external_id'] ?? '');
        // iyzico alt üye işyeri türleri: PERSONAL (TC), PRIVATE_COMPANY (şahıs şirketi: TC + vergi dairesi),
        // LIMITED_OR_JOINT_STOCK_COMPANY (VKN + vergi dairesi + unvan). Şoförde "şirket" = VKN'li şirket.
        $payload = [
            'locale' => 'tr',
            'conversationId' => 'SUB-'.($externalId !== '' ? $externalId : uniqid()),
            'subMerchantExternalId' => $externalId,
            'subMerchantType' => $company ? 'LIMITED_OR_JOINT_STOCK_COMPANY' : 'PERSONAL',
            'name' => $company ? (string) ($data['company_title'] ?: $fullName) : $fullName,
            'contactName' => (string) ($data['name'] ?? ''),
            'contactSurname' => (string) ($data['surname'] ?? ''),
            'email' => (string) ($data['email'] ?? ''),
            'gsmNumber' => $phone !== '' ? '+90'.ltrim($phone, '0') : '',
            'address' => mb_substr((string) ($data['address'] ?? 'Türkiye'), 0, 200),
            'iban' => strtoupper(preg_replace('/\s+/', '', (string) ($data['iban'] ?? ''))),
            'currency' => 'TRY',
        ];
        if ($company) {
            $payload['taxNumber'] = $taxNo;
            $payload['taxOffice'] = $taxOffice;
            $payload['legalCompanyTitle'] = (string) ($data['company_title'] ?: $fullName);
        } else {
            $payload['identityNumber'] = $identity;
        }
        $response = $this->request(self::PATH_SUBMERCHANT, $payload);
        if (($response['status'] ?? '') === 'success' && ! empty($response['subMerchantKey'])) {
            return (string) $response['subMerchantKey'];
        }

        // Aynı dış kimlik daha önce kaydedilmişse (kimlik/vergi dairesi değişti, IBAN aynı) kayıt güncellenir (PUT + subMerchantKey).
        $existing = $externalId !== '' ? $this->request(self::PATH_SUBMERCHANT_RETRIEVE, ['locale' => 'tr', 'conversationId' => 'SUBQ-'.$externalId, 'subMerchantExternalId' => $externalId]) : [];
        if (! empty($existing['subMerchantKey'])) {
            $update = $this->request(self::PATH_SUBMERCHANT, $payload + ['subMerchantKey' => (string) $existing['subMerchantKey']], 'PUT');
            if (($update['status'] ?? '') === 'success') {
                return (string) $existing['subMerchantKey'];
            }
            Log::warning('iyzico alt üye işyeri güncellemesi başarısız.', ['external_id' => $externalId, 'response' => $update]);

            return null;
        }
        Log::warning('iyzico alt üye işyeri kaydı başarısız.', ['external_id' => $externalId, 'response' => $response]);

        return null;
    }

    /**
     * Panelden "Bağlantıyı sına": para hareketi yapmayan isteklerle anahtarları, ortamı ve (açıksa) pazaryeri yetkisini denetler.
     *
     * @return list<array{label:string,ok:bool,detail:string}>
     */
    public function diagnose(): array
    {
        $checks = [];
        $env = $this->isSandbox() ? 'test (sandbox)' : 'canlı';
        if (! $this->isConfigured()) {
            return [['label' => 'Anahtarlar', 'ok' => false, 'detail' => 'API anahtarı ya da gizli anahtar boş.']];
        }
        $looksSandboxKey = str_starts_with($this->apiKey(), 'sandbox-');
        if ($looksSandboxKey !== $this->isSandbox()) {
            $checks[] = ['label' => 'Anahtar / ortam uyumu', 'ok' => false, 'detail' => $looksSandboxKey
                ? 'API anahtarı "sandbox-" ile başlıyor ama test modu kapalı: canlı adrese test anahtarı gönderiliyor.'
                : 'Canlı görünen anahtarla test modu açık: ödemeler sahte parayla "başarılı" olur. Canlıya geçerken test modunu kapatın.'];
        }

        $bin = $this->request(self::PATH_BIN_CHECK, ['locale' => 'tr', 'conversationId' => 'DIAG-'.time(), 'binNumber' => '554960']);
        $authOk = ($bin['status'] ?? '') === 'success';
        $checks[] = ['label' => 'Anahtarlar ve imza ('.$env.')', 'ok' => $authOk,
            'detail' => $authOk ? 'iyzico anahtarları kabul etti ('.$this->baseUrl().').' : 'iyzico reddetti: '.trim(($bin['errorCode'] ?? '').' '.($bin['errorMessage'] ?? 'yanıt yok'))];

        if ($this->supportsSubMerchants()) {
            $probe = $this->request(self::PATH_SUBMERCHANT_RETRIEVE, ['locale' => 'tr', 'conversationId' => 'DIAG-SUB-'.time(), 'subMerchantExternalId' => 'NAVLUNIQ-DIAG-0']);
            $msg = mb_strtolower((string) ($probe['errorMessage'] ?? ''));
            $notFound = ($probe['status'] ?? '') === 'success' || str_contains($msg, 'bulunamad') || str_contains($msg, 'not found');
            $checks[] = ['label' => 'Pazaryeri (alt üye işyeri) yetkisi', 'ok' => $authOk && $notFound,
                'detail' => ! $authOk ? 'Anahtar denetimi geçmeden sınanamaz.' : ($notFound
                    ? 'Pazaryeri uç noktası yanıt veriyor; alt üye işyeri kaydı açılabilir.'
                    : 'iyzico pazaryeri isteğini reddetti: '.trim(($probe['errorCode'] ?? '').' '.($probe['errorMessage'] ?? 'yanıt yok')).' — pazaryeri ürünü hesapta açık değilse iyzico temsilcinizden açılmasını isteyin.')];
        } else {
            $checks[] = ['label' => 'Pazaryeri (alt üye işyeri)', 'ok' => $this->isSandbox(), 'detail' => $this->isSandbox() ? 'Test modunda pazaryeri şartı aranmaz.' : 'Kapalı: canlıda navlun tahsilatı açılmaz. Sözleşme imzalanınca "Pazaryeri ürünü aktif" kutusunu işaretleyin.'];
        }

        $webhook = route('payment.webhook', ['provider' => 'iyzico']);
        $checks[] = ['label' => 'Bildirim adresi', 'ok' => str_starts_with($webhook, 'https://'), 'detail' => $webhook.' — iyzico panelinde İşyeri Bildirimleri → "İşyeri Bildirimleri Url" alanına bu adres yazılır ve bildirim gönderimi açılır.'];

        return $checks;
    }

    /** Teslimat onayı: navlun kalemi onaylanır, iyzico tutarı alt üye işyerine (şoför) aktarır. */
    public function transferToSubMerchant(Payout $payout, string $subMerchantRef): TransferResult
    {
        $order = PaymentOrder::query()->where('load_id', $payout->load_id)->where('purpose', 'escrow')
            ->where('provider', 'iyzico')->where('status', 'paid')->latest('id')->first();
        $txId = $order ? $this->transactionId($order) : '';
        if ($txId === '') {
            return new TransferResult(false, null, 'Onaylanacak ödeme kalemi bulunamadı.');
        }

        $data = $this->request(self::PATH_APPROVE, ['locale' => 'tr', 'conversationId' => 'PAYOUT-'.$payout->id, 'paymentTransactionId' => $txId]);
        $ok = ($data['status'] ?? '') === 'success';

        return new TransferResult($ok, $ok ? 'iyzico-approve:'.$txId : null, $ok ? null : ($data['errorMessage'] ?? 'Kalem onayı reddedildi'));
    }

    private function transactionId(PaymentOrder $order): string
    {
        $ref = (string) $order->provider_reference;

        return str_contains($ref, ':') ? (string) explode(':', $ref, 2)[1] : '';
    }

    private function apiKey(): string
    {
        return Settings::string('iyzico_api_key') ?: trim((string) config('services.iyzico.api_key'));
    }

    private function secretKey(): string
    {
        return Settings::string('iyzico_secret_key') ?: trim((string) config('services.iyzico.secret_key'));
    }

    /** IYZWSv2 imzalı JSON istek (POST; alt üye işyeri güncellemesi PUT). */
    private function request(string $path, array $body, string $method = 'POST'): array
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $randomKey = (string) hrtime(true).random_int(100000, 999999);
        $signature = hash_hmac('sha256', $randomKey.$path.$json, $this->secretKey());
        $authorization = base64_encode('apiKey:'.$this->apiKey().'&randomKey:'.$randomKey.'&signature:'.$signature);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'IYZWSv2 '.$authorization,
                'x-iyzi-rnd' => $randomKey,
                'x-iyzi-client-version' => 'navluniq-php-1.0',
                'Accept' => 'application/json',
            ])->withBody($json, 'application/json')->timeout(25)->send($method, $this->baseUrl().$path);
        } catch (\Throwable $e) {
            Log::error('iyzico isteği başarısız.', ['path' => $path, 'error' => $e->getMessage()]);

            return ['status' => 'failure', 'errorMessage' => 'Ödeme kuruluşuna ulaşılamadı: '.$e->getMessage()];
        }

        $data = $response->json();

        return is_array($data) ? $data : ['status' => 'failure', 'errorMessage' => 'Geçersiz yanıt ('.$response->status().')'];
    }
}
