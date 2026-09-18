<?php

namespace App\Services;

use App\Models\Load;
use App\Models\PaymentEvent;
use App\Models\PaymentOrder;
use App\Models\User;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\Data\Checkout;
use App\Payments\GatewayManager;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Sağlayıcıdan bağımsız ödeme akışı: sipariş oluşturma, ödeme ekranı, sunucu bildirimi, iade.
 * Sağlayıcıya özgü her şey PaymentGateway adaptöründedir. Ödeme yalnız imzası doğrulanmış
 * sunucu bildirimiyle "alındı" sayılır; kullanıcı dönüş sayfası hiçbir zaman durum belirlemez.
 *
 * Sipariş amaçları: escrow (navlun bedeli, teslimat onaylı), subscription (premium üyelik).
 */
class PaymentService
{
    public const PURPOSE_ESCROW = 'escrow';

    public const PURPOSE_SUBSCRIPTION = 'subscription';

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly LedgerService $ledger,
        private readonly GatewayManager $gateways,
    ) {}

    public function gateway(): PaymentGateway
    {
        return $this->gateways->active();
    }

    public function isConfigured(): bool
    {
        return $this->gateway()->isConfigured();
    }

    public function isSandbox(): bool
    {
        return $this->gateway()->isSandbox();
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

        $existing = PaymentOrder::query()->where('load_id', $load->id)->where('purpose', self::PURPOSE_ESCROW)
            ->whereIn('status', ['created', 'pending'])->latest()->first();

        if ($existing && (float) $existing->amount === $amounts['total']) {
            return $existing;
        }

        return PaymentOrder::create([
            'load_id' => $load->id,
            'user_id' => $payer->id,
            'purpose' => self::PURPOSE_ESCROW,
            'provider' => $this->gateway()->id(),
            'merchant_oid' => $this->merchantOid('NQ'.$load->id),
            'amount' => $amounts['total'],
            'currency' => 'TRY',
            'service_fee_amount' => $amounts['service_fee'],
            'status' => 'created',
        ]);
    }

    /** Premium abonelik için açık bir ödeme emri döner (varsa yeniden kullanır). */
    public function orderForSubscription(User $payer, float $amount): PaymentOrder
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new RuntimeException('Abonelik ücreti tanımlı değil.');
        }

        $existing = PaymentOrder::query()->where('user_id', $payer->id)->where('purpose', self::PURPOSE_SUBSCRIPTION)
            ->whereIn('status', ['created', 'pending'])->where('created_at', '>=', now()->subHours(6))->latest()->first();
        if ($existing && abs((float) $existing->amount - $amount) < 0.01) {
            return $existing;
        }

        return PaymentOrder::create([
            'load_id' => null,
            'user_id' => $payer->id,
            'purpose' => self::PURPOSE_SUBSCRIPTION,
            'provider' => $this->gateway()->id(),
            'merchant_oid' => $this->merchantOid('NQS'.$payer->id),
            'amount' => $amount,
            'currency' => 'TRY',
            'service_fee_amount' => 0,
            'status' => 'created',
        ]);
    }

    /** Sağlayıcıya özgü ödeme ekranını (iframe/yönlendirme) hazırlar. */
    public function checkout(PaymentOrder $order, Request $request): Checkout
    {
        $gateway = $this->gateway();
        if (! $gateway->isConfigured()) {
            throw new RuntimeException('Ödeme altyapısı henüz etkin değil.');
        }
        if (! in_array($order->status, ['created', 'pending'], true)) {
            throw new RuntimeException('Bu sipariş ödeme adımında değil.');
        }

        $load = $order->cargoLoad;
        $description = $order->purpose === self::PURPOSE_SUBSCRIPTION
            ? 'NavlunIQ Premium şoför üyeliği (1 ay)'
            : 'Navlun bedeli #'.$order->load_id.' '.($load?->pickup_location).' - '.($load?->delivery_location);

        return $gateway->createCheckout($order, [
            'ip' => (string) $request->ip(),
            'ok_url' => route('payment.result', ['order' => $order->public_id, 'outcome' => 'basarili']),
            'fail_url' => route('payment.result', ['order' => $order->public_id, 'outcome' => 'basarisiz']),
            'description' => $description,
            'address' => $load?->cargoOwnerProfile?->company_title ?: ($load?->pickup_location ?: 'Türkiye'),
        ]);
    }

    /**
     * Sunucu bildirimi. Sağlayıcıya dönülecek [gövde, HTTP kodu] çifti döndürür.
     * Aynı bildirim ikinci kez gelirse çift işlem yapılmaz (provider_event_id benzersiz).
     */
    public function handleWebhook(string $providerId, Request $request): array
    {
        $gateway = $this->gateways->gateway($providerId);
        $result = $gateway->parseWebhook($request);

        if (! $result->valid) {
            Log::warning('Ödeme bildirimi imza doğrulaması başarısız.', ['provider' => $providerId, 'merchant_oid' => $result->merchantOid]);

            // Sağlayıcılar "OK" dışındaki her yanıtta bildirimi yineler; 200 ile açık bir ret gövdesi dönülür.
            return [$result->rejectBody, 200];
        }

        $order = PaymentOrder::query()->where('merchant_oid', $result->merchantOid)->first();
        if (! $order) {
            Log::warning('Ödeme bildirimi bilinmeyen sipariş.', ['provider' => $providerId, 'merchant_oid' => $result->merchantOid]);

            return [$result->ackBody, 200];
        }

        $payloadJson = json_encode($result->payload, JSON_UNESCAPED_UNICODE) ?: '{}';

        $processed = DB::transaction(function () use ($order, $result, $payloadJson): bool {
            $locked = PaymentOrder::query()->lockForUpdate()->findOrFail($order->id);

            if (PaymentEvent::query()->where('payment_order_id', $locked->id)->where('provider_event_id', $result->eventId)->exists()) {
                return false;
            }

            PaymentEvent::create([
                'payment_order_id' => $locked->id,
                'provider_event_id' => $result->eventId,
                'event_type' => 'callback',
                'status' => $result->status,
                'payload_hash' => hash('sha256', $payloadJson),
                'payload_encrypted' => Crypt::encryptString($payloadJson),
                'processed_at' => now(),
                'failure_message' => $result->failureMessage,
            ]);

            if ($locked->status === 'paid') {
                return false;
            }

            if ($result->status !== 'success') {
                $locked->update(['status' => 'failed', 'failed_at' => now()]);

                return false;
            }

            if ($result->paidAmount !== null && abs($result->paidAmount - (float) $locked->amount) > 0.01) {
                Log::critical('Ödeme tutar uyuşmazlığı.', ['order' => $locked->id, 'expected' => $locked->amount, 'paid' => $result->paidAmount]);
            }

            $locked->update([
                'status' => 'paid',
                'paid_at' => now(),
                'provider_reference' => $result->providerReference,
            ]);

            if ($locked->purpose === self::PURPOSE_ESCROW) {
                $load = Load::query()->lockForUpdate()->find($locked->load_id);
                if ($load && $load->escrow_status === Load::ESCROW_PENDING) {
                    $load->update(['escrow_status' => Load::ESCROW_PAID]);
                }
            }

            return true;
        }, 3);

        if ($processed) {
            $this->afterPaid($order->fresh());
        }

        return [$result->ackBody, 200];
    }

    private function afterPaid(PaymentOrder $order): void
    {
        if ($order->purpose === self::PURPOSE_SUBSCRIPTION) {
            app(SubscriptionService::class)->activate($order);

            return;
        }

        $load = $order->cargoLoad;

        try {
            $this->ledger->post('escrow_in', 'Navlun tahsilatı #'.$load?->id, [
                ['account_code' => 'escrow_cash', 'direction' => 'debit', 'amount' => $order->amount],
                ['account_code' => 'escrow_liability', 'direction' => 'credit', 'amount' => $order->amount],
            ], PaymentOrder::class, $order->id);
        } catch (\Throwable $e) {
            Log::error('Navlun tahsilatı muhasebeye yazılamadı.', ['order' => $order->id, 'error' => $e->getMessage()]);
        }

        if ($load) {
            if ($driverUser = $load->driverProfile?->user) {
                $this->notifications->notify($driverUser, 'Navlun ödemesi yapıldı',
                    ['Yük sahibi navlun ödemesini yaptı. Artık sevkiyatı başlatabilirsiniz.'],
                    route('driver.shipments.show', $load->id), 'Sevkiyata git');
            }
            if ($ownerUser = $load->cargoOwnerProfile?->user) {
                $this->notifications->notify($ownerUser, 'Ödemeniz alındı',
                    ['Navlun ödemesi teslimat onayınızla şoföre tamamlanacaktır.'],
                    route('cargo-owner.shipments.show', $load->id), 'Sevkiyatı takip et');
            }
        }
    }

    /** Uyuşmazlık kararı sonrası iade. Sağlayıcı iadeyi kabul etmezse manuel iade kaydı bırakır. */
    public function refund(PaymentOrder $order, float $amount, string $reason): bool
    {
        if ($order->status !== 'paid') {
            throw new RuntimeException('Yalnız ödenmiş siparişler iade edilebilir.');
        }

        $amount = round(min($amount, (float) $order->amount), 2);
        $gateway = $this->gateways->gateway((string) $order->provider);
        $result = $gateway->isConfigured() ? $gateway->refund($order, $amount) : null;
        $succeeded = $result?->succeeded ?? false;

        if ($result !== null) {
            PaymentEvent::create([
                'payment_order_id' => $order->id,
                'provider_event_id' => 'refund:'.now()->timestamp,
                'event_type' => 'refund',
                'status' => $succeeded ? 'success' : 'failed',
                'payload_hash' => hash('sha256', $result->rawResponse),
                'payload_encrypted' => Crypt::encryptString($result->rawResponse),
                'processed_at' => now(),
                'failure_message' => $succeeded ? null : $result->failureMessage,
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

    private function merchantOid(string $prefix): string
    {
        return $prefix.'T'.now()->format('ymdHis').random_int(10, 99);
    }
}
