<?php

namespace App\Services;

use App\Models\Load;
use App\Models\PaymentEvent;
use App\Models\PaymentOrder;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * PayTR iFrame API entegrasyonu.
 * Ödeme yalnız imzası doğrulanmış sunucu bildirimi (callback) ile başarılı sayılır.
 */
class PaymentService
{
    public const TOKEN_URL = 'https://www.paytr.com/odeme/api/get-token';

    public const IFRAME_URL = 'https://www.paytr.com/odeme/guvenli/';

    public const REFUND_URL = 'https://www.paytr.com/odeme/iade';

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly LedgerService $ledger,
    ) {}

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

    /** Navlun bedeli + yük sahibi hizmet bedeli. */
    public function calculateAmounts(Load $load): array
    {
        $price = round((float) $load->price, 2);
        $feeRate = Settings::float('commission_cargo_owner');
        $fee = round($price * $feeRate / 100, 2);

        return ['price' => $price, 'service_fee' => $fee, 'total' => round($price + $fee, 2)];
    }

    /** Ödenmemiş ilan için açık bir ödeme emri döner (varsa yeniden kullanır). */
    public function orderFor(Load $load, User $payer): PaymentOrder
    {
        if ($load->cargoOwnerProfile?->user_id !== $payer->id) {
            throw new RuntimeException('Bu ilan size ait değil.');
        }

        if ($load->status !== Load::STATUS_ASSIGNED || $load->escrow_status !== Load::ESCROW_PENDING) {
            throw new RuntimeException('Bu ilan ödeme adımında değil.');
        }

        $amounts = $this->calculateAmounts($load);

        $existing = PaymentOrder::query()->where('load_id', $load->id)->where('purpose', 'escrow')
            ->whereIn('status', ['created', 'pending'])->latest()->first();

        if ($existing && (float) $existing->amount === $amounts['total']) {
            return $existing;
        }

        return PaymentOrder::create([
            'load_id' => $load->id,
            'user_id' => $payer->id,
            'purpose' => 'escrow',
            'provider' => 'paytr',
            'merchant_oid' => 'NQ'.$load->id.'T'.now()->format('ymdHis').random_int(10, 99),
            'amount' => $amounts['total'],
            'currency' => 'TRY',
            'service_fee_amount' => $amounts['service_fee'],
            'status' => 'created',
        ]);
    }

    /** PayTR'den iFrame token'ı alır. */
    public function iframeToken(PaymentOrder $order, Request $request): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Ödeme altyapısı henüz etkin değil.');
        }

        $user = $order->user;
        $load = $order->cargoLoad;
        $merchantId = (string) config('services.paytr.merchant_id');
        $merchantKey = (string) config('services.paytr.merchant_key');
        $merchantSalt = (string) config('services.paytr.merchant_salt');
        $userIp = $request->ip();
        $email = $user->email;
        $paymentAmount = (int) round(((float) $order->amount) * 100);
        $basket = base64_encode(json_encode([[
            'Navlun bedeli #'.$load->id.' '.$load->pickup_location.' - '.$load->delivery_location,
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
            'user_name' => mb_substr($user->full_name, 0, 60),
            'user_address' => mb_substr($load->cargoOwnerProfile?->company_title ?: $load->pickup_location, 0, 400),
            'user_phone' => '0'.$user->phone,
            'merchant_ok_url' => route('payment.paytr.success', $load->id),
            'merchant_fail_url' => route('payment.paytr.fail', $load->id),
            'timeout_limit' => 30,
            'currency' => $currency,
            'test_mode' => $testMode,
            'lang' => 'tr',
        ];

        $response = Http::asForm()->timeout(20)->post(self::TOKEN_URL, $payload);
        $data = $response->json();

        if (! $response->successful() || ($data['status'] ?? '') !== 'success' || empty($data['token'])) {
            Log::error('PayTR token alınamadı.', ['order' => $order->id, 'response' => $response->body()]);
            $order->update(['status' => 'failed', 'failed_at' => now()]);
            throw new RuntimeException('Ödeme sağlayıcısından yanıt alınamadı: '.($data['reason'] ?? 'bilinmeyen hata'));
        }

        $order->update([
            'status' => 'pending',
            'request_snapshot' => array_diff_key($payload, ['paytr_token' => true]),
        ]);

        return $data['token'];
    }

    /** PayTR sunucu bildirimi. Dönüş değeri PayTR'ye yazılacak düz metin yanıttır. */
    public function handleCallback(array $post): string
    {
        $merchantKey = (string) config('services.paytr.merchant_key');
        $merchantSalt = (string) config('services.paytr.merchant_salt');
        $oid = (string) ($post['merchant_oid'] ?? '');
        $status = (string) ($post['status'] ?? '');
        $totalAmount = (string) ($post['total_amount'] ?? '');
        $hash = (string) ($post['hash'] ?? '');

        $expected = base64_encode(hash_hmac('sha256', $oid.$merchantSalt.$status.$totalAmount, $merchantKey, true));
        if ($oid === '' || $hash === '' || ! hash_equals($expected, $hash)) {
            Log::warning('PayTR bildirimi imza doğrulaması başarısız.', ['merchant_oid' => $oid]);

            return 'PAYTR notification failed: bad hash';
        }

        $order = PaymentOrder::query()->where('merchant_oid', $oid)->first();
        if (! $order) {
            Log::warning('PayTR bildirimi bilinmeyen sipariş.', ['merchant_oid' => $oid]);

            return 'OK';
        }

        $eventId = $oid.':'.$status;
        $payloadJson = json_encode($post, JSON_UNESCAPED_UNICODE);

        $processed = DB::transaction(function () use ($order, $eventId, $status, $post, $payloadJson, $totalAmount): bool {
            $locked = PaymentOrder::query()->lockForUpdate()->findOrFail($order->id);

            if (PaymentEvent::query()->where('payment_order_id', $locked->id)->where('provider_event_id', $eventId)->exists()) {
                return false;
            }

            PaymentEvent::create([
                'payment_order_id' => $locked->id,
                'provider_event_id' => $eventId,
                'event_type' => 'callback',
                'status' => $status,
                'payload_hash' => hash('sha256', $payloadJson),
                'payload_encrypted' => Crypt::encryptString($payloadJson),
                'processed_at' => now(),
                'failure_message' => $status === 'success' ? null : trim(($post['failed_reason_code'] ?? '').' '.($post['failed_reason_msg'] ?? '')),
            ]);

            if ($locked->status === 'paid') {
                return false;
            }

            if ($status !== 'success') {
                $locked->update(['status' => 'failed', 'failed_at' => now()]);

                return false;
            }

            $paidTotal = ((int) $totalAmount) / 100;
            if (abs($paidTotal - (float) $locked->amount) > 0.01) {
                Log::critical('PayTR tutar uyuşmazlığı.', ['order' => $locked->id, 'expected' => $locked->amount, 'paid' => $paidTotal]);
            }

            $locked->update([
                'status' => 'paid',
                'paid_at' => now(),
                'provider_reference' => $post['payment_type'] ?? null,
            ]);

            $load = Load::query()->lockForUpdate()->find($locked->load_id);
            if ($load && $load->escrow_status === Load::ESCROW_PENDING) {
                $load->update(['escrow_status' => Load::ESCROW_PAID]);
            }

            return true;
        }, 3);

        if ($processed) {
            $order->refresh();
            $this->afterPaid($order);
        }

        return 'OK';
    }

    private function afterPaid(PaymentOrder $order): void
    {
        $load = $order->cargoLoad;

        try {
            $this->ledger->post('escrow_in', 'Havuza tahsilat #'.$load?->id, [
                ['account_code' => 'escrow_cash', 'direction' => 'debit', 'amount' => $order->amount],
                ['account_code' => 'escrow_liability', 'direction' => 'credit', 'amount' => $order->amount],
            ], PaymentOrder::class, $order->id);
        } catch (\Throwable $e) {
            Log::error('Havuz tahsilatı muhasebeye yazılamadı.', ['order' => $order->id, 'error' => $e->getMessage()]);
        }

        if ($load) {
            if ($driverUser = $load->driverProfile?->user) {
                $this->notifications->notify($driverUser, 'Navlun bedeli havuza yatırıldı',
                    ['Yük sahibi ödemeyi güvenli havuza yatırdı. Artık sevkiyatı başlatabilirsiniz.'],
                    route('driver.shipments.show', $load->id), 'Sevkiyata git');
            }
            if ($ownerUser = $load->cargoOwnerProfile?->user) {
                $this->notifications->notify($ownerUser, 'Ödemeniz alındı',
                    ['Navlun bedeli teslimat onayınıza kadar güvenli havuzda tutulacaktır.'],
                    route('cargo-owner.shipments.show', $load->id), 'Sevkiyatı takip et');
            }
        }
    }

    /** Uyuşmazlık kararı sonrası iade. Sağlayıcı yapılandırılmamışsa manuel iade kaydı bırakır. */
    public function refund(PaymentOrder $order, float $amount, string $reason): bool
    {
        if ($order->status !== 'paid') {
            throw new RuntimeException('Yalnız ödenmiş siparişler iade edilebilir.');
        }

        $amount = round(min($amount, (float) $order->amount), 2);
        $succeeded = false;

        if ($this->isConfigured()) {
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
            $succeeded = $response->successful() && ($data['status'] ?? '') === 'success';

            PaymentEvent::create([
                'payment_order_id' => $order->id,
                'provider_event_id' => 'refund:'.now()->timestamp,
                'event_type' => 'refund',
                'status' => $succeeded ? 'success' : 'failed',
                'payload_hash' => hash('sha256', (string) $response->body()),
                'payload_encrypted' => Crypt::encryptString((string) $response->body()),
                'processed_at' => now(),
                'failure_message' => $succeeded ? null : ($data['err_msg'] ?? $data['reason'] ?? 'İade isteği reddedildi'),
            ]);
        }

        if ($succeeded) {
            $order->update(['status' => 'refunded', 'refunded_at' => now()]);
            try {
                $this->ledger->post('refund', 'İade #'.$order->load_id.' '.$reason, [
                    ['account_code' => 'escrow_liability', 'direction' => 'debit', 'amount' => $amount],
                    ['account_code' => 'escrow_cash', 'direction' => 'credit', 'amount' => $amount],
                ], PaymentOrder::class, $order->id);
            } catch (\Throwable $e) {
                Log::error('İade muhasebeye yazılamadı.', ['order' => $order->id, 'error' => $e->getMessage()]);
            }
        } else {
            $order->update(['status' => 'refund_pending']);
            Log::warning('İade manuel olarak tamamlanmalı.', ['order' => $order->id, 'amount' => $amount, 'reason' => $reason]);
        }

        return $succeeded;
    }
}
