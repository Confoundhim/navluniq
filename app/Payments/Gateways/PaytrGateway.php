<?php

namespace App\Payments\Gateways;

use App\Models\PaymentOrder;
use App\Models\Payout;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\Data\Checkout;
use App\Payments\Data\RefundResult;
use App\Payments\Data\TransferResult;
use App\Payments\Data\WebhookResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * PayTR iFrame API adaptörü. Standart üye işyeri modeli: alt üye işyeri aktarımı desteklenmez
 * (PayTR Pazaryeri ürünü ayrı bir adaptör olarak eklenir).
 */
final class PaytrGateway implements PaymentGateway
{
    public const TOKEN_URL = 'https://www.paytr.com/odeme/api/get-token';

    public const IFRAME_URL = 'https://www.paytr.com/odeme/guvenli/';

    public const REFUND_URL = 'https://www.paytr.com/odeme/iade';

    public function id(): string
    {
        return 'paytr';
    }

    public function label(): string
    {
        return 'PayTR (iFrame)';
    }

    public function isConfigured(): bool
    {
        return filled(config('services.paytr.merchant_id'))
            && filled(config('services.paytr.merchant_key'))
            && filled(config('services.paytr.merchant_salt'));
    }

    public function isSandbox(): bool
    {
        return (bool) config('services.paytr.sandbox', true);
    }

    public function createCheckout(PaymentOrder $order, array $context): Checkout
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Ödeme altyapısı henüz etkin değil.');
        }

        $user = $order->user;
        $merchantId = (string) config('services.paytr.merchant_id');
        $merchantKey = (string) config('services.paytr.merchant_key');
        $merchantSalt = (string) config('services.paytr.merchant_salt');
        $userIp = (string) ($context['ip'] ?? '127.0.0.1');
        $email = (string) $user->email;
        $paymentAmount = (int) round(((float) $order->amount) * 100);
        $basket = base64_encode(json_encode([[
            mb_substr((string) ($context['description'] ?? 'NavlunIQ'), 0, 120),
            number_format((float) $order->amount, 2, '.', ''),
            1,
        ]], JSON_UNESCAPED_UNICODE));
        $noInstallment = 1;
        $maxInstallment = 0;
        $currency = 'TL';
        $testMode = $this->isSandbox() ? 1 : 0;

        $hashStr = $merchantId.$userIp.$order->merchant_oid.$email.$paymentAmount.$basket.$noInstallment.$maxInstallment.$currency.$testMode;
        $paytrToken = base64_encode(hash_hmac('sha256', $hashStr.$merchantSalt, $merchantKey, true));

        $payload = [
            'merchant_id' => $merchantId,
            'user_ip' => $userIp,
            'merchant_oid' => $order->merchant_oid,
            'email' => $email,
            'payment_amount' => $paymentAmount,
            'paytr_token' => $paytrToken,
            'user_basket' => $basket,
            'debug_on' => $testMode,
            'no_installment' => $noInstallment,
            'max_installment' => $maxInstallment,
            'user_name' => mb_substr((string) $user->full_name, 0, 60),
            'user_address' => mb_substr((string) ($context['address'] ?? 'Türkiye'), 0, 400),
            'user_phone' => '0'.$user->phone,
            'merchant_ok_url' => $context['ok_url'],
            'merchant_fail_url' => $context['fail_url'],
            'timeout_limit' => 30,
            'currency' => $currency,
            'test_mode' => $testMode,
            'lang' => 'tr',
        ];

        $response = Http::asForm()->timeout(20)->post(self::TOKEN_URL, $payload);
        $data = $response->json();

        if (! $response->successful() || ($data['status'] ?? '') !== 'success' || empty($data['token'])) {
            Log::error('PayTR token alınamadı.', ['order' => $order->id, 'response' => $response->body()]);
            throw new RuntimeException('Ödeme sağlayıcısından yanıt alınamadı: '.($data['reason'] ?? 'bilinmeyen hata'));
        }

        $order->update(['status' => 'pending', 'request_snapshot' => array_diff_key($payload, ['paytr_token' => true])]);

        return new Checkout('iframe', self::IFRAME_URL.$data['token'], (string) $data['token'], 25 * 60, 'https://www.paytr.com/js/iframeResizer.min.js');
    }

    public function parseWebhook(Request $request): WebhookResult
    {
        $post = $request->post();
        $merchantKey = (string) config('services.paytr.merchant_key');
        $merchantSalt = (string) config('services.paytr.merchant_salt');
        $oid = (string) ($post['merchant_oid'] ?? '');
        $status = (string) ($post['status'] ?? '');
        $totalAmount = (string) ($post['total_amount'] ?? '');
        $hash = (string) ($post['hash'] ?? '');

        $expected = base64_encode(hash_hmac('sha256', $oid.$merchantSalt.$status.$totalAmount, $merchantKey, true));
        $valid = $oid !== '' && $hash !== '' && $merchantKey !== '' && hash_equals($expected, $hash);

        return new WebhookResult(
            valid: $valid,
            merchantOid: $oid,
            status: $status === 'success' ? 'success' : 'failed',
            paidAmount: $totalAmount !== '' ? ((int) $totalAmount) / 100 : null,
            eventId: $oid.':'.$status,
            payload: $post,
            providerReference: $post['payment_type'] ?? null,
            failureMessage: $status === 'success' ? null : trim(($post['failed_reason_code'] ?? '').' '.($post['failed_reason_msg'] ?? '')),
            ackBody: 'OK',
            rejectBody: 'PAYTR notification failed: bad hash',
        );
    }

    public function refund(PaymentOrder $order, float $amount): RefundResult
    {
        if (! $this->isConfigured()) {
            return new RefundResult(false, '', 'Ödeme altyapısı tanımlı değil.');
        }
        $merchantId = (string) config('services.paytr.merchant_id');
        $merchantKey = (string) config('services.paytr.merchant_key');
        $merchantSalt = (string) config('services.paytr.merchant_salt');
        $token = base64_encode(hash_hmac('sha256', $merchantId.$order->merchant_oid.number_format($amount, 2, '.', '').$merchantSalt, $merchantKey, true));

        $response = Http::asForm()->timeout(20)->post(self::REFUND_URL, [
            'merchant_id' => $merchantId,
            'merchant_oid' => $order->merchant_oid,
            'return_amount' => number_format($amount, 2, '.', ''),
            'paytr_token' => $token,
        ]);
        $data = $response->json();
        $ok = $response->successful() && ($data['status'] ?? '') === 'success';

        return new RefundResult($ok, (string) $response->body(), $ok ? null : ($data['err_msg'] ?? $data['reason'] ?? 'İade isteği reddedildi'));
    }

    public function supportsSubMerchants(): bool
    {
        return false;
    }

    public function registerSubMerchant(array $data): ?string
    {
        return null;
    }

    public function transferToSubMerchant(Payout $payout, string $subMerchantRef): TransferResult
    {
        return new TransferResult(false, null, 'PayTR standart üye işyeri hesabı alt üye işyeri aktarımını desteklemez.');
    }
}
