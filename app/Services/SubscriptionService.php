<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\PaymentOrder;
use App\Models\Subscription;
use App\Models\SubscriptionCycle;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Premium şoför üyeliği: aylık dönemler halinde satın alınır, otomatik yenilenmez.
 * Ödeme yalnız doğrulanmış sunucu bildirimiyle etkinleşir (PaymentService::afterPaid → activate).
 */
class SubscriptionService
{
    public const PLAN_PREMIUM_MONTHLY = 'premium_monthly';

    public function __construct(
        private readonly PaymentService $payments,
        private readonly NotificationService $notifications,
        private readonly LedgerService $ledger,
    ) {}

    public function monthlyPrice(): float
    {
        return round(Settings::float('premium_monthly_price'), 2);
    }

    /** Şoför için premium ödeme emri; ödeme ekranı PaymentService::checkout ile açılır. */
    public function startCheckout(User $driverUser): PaymentOrder
    {
        $profile = $driverUser->driverProfile;
        if (! $profile) {
            throw new RuntimeException('Premium üyelik yalnız şoför hesapları için geçerlidir.');
        }
        if (! $profile->isKycApproved()) {
            throw new RuntimeException('Premium üyelik için belgelerinizin onaylanmış olması gerekir.');
        }

        return $this->payments->orderForSubscription($driverUser, $this->monthlyPrice());
    }

    /** Ödenmiş abonelik siparişini üyeliğe çevirir: dönem, fatura kaydı, premium süresi, bildirim, defter. */
    public function activate(PaymentOrder $order): ?Subscription
    {
        if ($order->purpose !== PaymentService::PURPOSE_SUBSCRIPTION || $order->status !== 'paid') {
            return null;
        }
        if (SubscriptionCycle::query()->where('payment_order_id', $order->id)->exists()) {
            return Subscription::query()->whereHas('cycles', fn ($q) => $q->where('payment_order_id', $order->id))->first();
        }

        $user = $order->user;
        $profile = $user?->driverProfile;
        if (! $user || ! $profile) {
            Log::error('Abonelik etkinleştirilemedi: şoför profili yok.', ['order' => $order->id]);

            return null;
        }

        $subscription = DB::transaction(function () use ($order, $user, $profile): Subscription {
            // Mevcut süre bitmediyse üzerine eklenir; bittiyse bugünden başlar.
            $start = $profile->premium_until && $profile->premium_until->isFuture() ? $profile->premium_until->copy() : now();
            $end = $start->copy()->addMonth();

            $subscription = Subscription::query()->where('user_id', $user->id)->where('plan_code', self::PLAN_PREMIUM_MONTHLY)->latest('id')->first()
                ?? Subscription::create([
                    'user_id' => $user->id,
                    'plan_code' => self::PLAN_PREMIUM_MONTHLY,
                    'provider' => $order->provider,
                    'status' => 'active',
                    'amount' => $order->amount,
                    'currency' => $order->currency,
                    'interval' => 'monthly',
                ]);

            $subscription->update([
                'status' => 'active',
                'amount' => $order->amount,
                'provider' => $order->provider,
                'current_period_starts_at' => $start,
                'current_period_ends_at' => $end,
                'cancelled_at' => null,
                'ended_at' => null,
            ]);

            SubscriptionCycle::create([
                'subscription_id' => $subscription->id,
                'payment_order_id' => $order->id,
                'period_start' => $start,
                'period_end' => $end,
                'amount' => $order->amount,
                'currency' => $order->currency,
                'status' => 'paid',
                'paid_at' => $order->paid_at ?? now(),
            ]);

            $profile->update(['premium_until' => $end]);

            // KDV dahil fiyattan matrah ayrıştırılır; fatura numarası e-belge sağlayıcısı bağlanınca yazılır.
            $vatRate = Settings::float('payment_vat_rate');
            $total = round((float) $order->amount, 2);
            $base = round($total / (1 + $vatRate / 100), 2);
            Invoice::create([
                'user_id' => $user->id,
                'payment_order_id' => $order->id,
                'invoice_type' => 'subscription',
                'base_amount' => $base,
                'tax_amount' => round($total - $base, 2),
                'total_amount' => $total,
                'currency' => $order->currency,
                'tax_rate' => $vatRate,
                'status' => 'pending',
            ]);

            return $subscription;
        });

        try {
            $vatRate = Settings::float('payment_vat_rate');
            $total = round((float) $order->amount, 2);
            $base = round($total / (1 + $vatRate / 100), 2);
            $this->ledger->post('subscription_in', 'Premium abonelik #'.$order->id, array_values(array_filter([
                ['account_code' => 'bank_cash', 'direction' => 'debit', 'amount' => $total],
                ['account_code' => 'subscription_revenue', 'direction' => 'credit', 'amount' => $base],
                $total - $base > 0 ? ['account_code' => 'vat_payable', 'direction' => 'credit', 'amount' => round($total - $base, 2)] : null,
            ])), PaymentOrder::class, $order->id);
        } catch (\Throwable $e) {
            Log::error('Abonelik tahsilatı muhasebeye yazılamadı.', ['order' => $order->id, 'error' => $e->getMessage()]);
        }

        $this->notifications->notify($user, 'Premium üyeliğiniz etkinleşti',
            ['Premium üyeliğiniz '.$profile->fresh()->premium_until?->format('d.m.Y H:i').' tarihine kadar geçerli. Onaylı dış kaynak ilanlarını artık herkesten önce, ilan sahibinin numarasıyla görüyorsunuz.'],
            route('driver.loads.index', ['tab' => 'external']), 'İlanlara git');

        return $subscription;
    }

    /** Süresi dolan abonelikleri kapatır (zamanlanmış görev). premium_until zaten geçmişte olduğu için erişim kendiliğinden düşer. */
    public function expireDue(): int
    {
        return Subscription::query()->where('status', 'active')
            ->whereNotNull('current_period_ends_at')->where('current_period_ends_at', '<', now())
            ->update(['status' => 'expired', 'ended_at' => now()]);
    }
}
