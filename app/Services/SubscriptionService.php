<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\PaymentOrder;
use App\Models\Subscription;
use App\Models\SubscriptionCycle;
use App\Models\User;
use App\Models\UserNotification;
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

    public const PLAN_PREMIUM_GIFT = 'premium_gift';

    /**
     * Yönetici hediyesi / test premium'u: mevcut süre bitmediyse üzerine eklenir. Ödeme kaydı yoktur;
     * abonelik "premium_gift" planıyla ve 0 tutarla izlenir, kullanıcıya bildirim gider.
     */
    public function grantPremium(User $user, int $days, ?User $admin = null, string $note = ''): Subscription
    {
        $profile = $user->driverProfile;
        if (! $profile) {
            throw new RuntimeException('Premium yalnız şoför profili olan kullanıcıya tanımlanır.');
        }
        $days = max(1, min(3660, $days));

        $subscription = DB::transaction(function () use ($user, $profile, $days, $admin, $note): Subscription {
            $start = $profile->premium_until && $profile->premium_until->isFuture() ? $profile->premium_until->copy() : now();
            $end = $start->copy()->addDays($days);
            $profile->update(['premium_until' => $end]);

            $subscription = Subscription::create([
                'user_id' => $user->id,
                'plan_code' => self::PLAN_PREMIUM_GIFT,
                'provider' => 'manual',
                'status' => 'active',
                'amount' => 0,
                'currency' => 'TRY',
                'interval' => 'custom',
                'current_period_starts_at' => $start,
                'current_period_ends_at' => $end,
            ]);
            ActivityLog::record('subscription.gifted', "Premium hediye: {$days} gün → kullanıcı #{$user->id} (".$end->format('d.m.Y').' tarihine kadar)'.($note !== '' ? " · {$note}" : ''), $admin?->id, $subscription);

            return $subscription;
        });

        $this->notifications->notify($user, 'Premium üyelik hediye edildi',
            ["Hesabınıza {$days} günlük premium üyelik tanımlandı; ".$profile->fresh()->premium_until->format('d.m.Y H:i').' tarihine kadar geçerli.',
                'Yeni ilanları herkesten 20 dakika önce görür, anında bildirim alırsınız; dış kaynak ilanlarında numaranın tamamı görünür.'],
            route('driver.premium.index'), 'Premium sayfam', 'subscription');

        return $subscription;
    }

    /** Premium'u hemen bitirir (hediye ya da test süresi geri alınır). */
    public function revokePremium(User $user, ?User $admin = null): void
    {
        $profile = $user->driverProfile;
        if (! $profile || ! $profile->premium_until || $profile->premium_until->isPast()) {
            return;
        }
        $profile->update(['premium_until' => now()]);
        Subscription::query()->where('user_id', $user->id)->where('status', 'active')->update(['status' => 'cancelled', 'cancelled_at' => now(), 'ended_at' => now()]);
        ActivityLog::record('subscription.revoked', "Premium kaldırıldı → kullanıcı #{$user->id}", $admin?->id);
        $this->notifications->notify($user, 'Premium üyeliğiniz sona erdi', ['Premium üyeliğiniz yönetici tarafından sonlandırıldı; hesabınız standart üyeliğe döndü.'], route('driver.premium.index'), 'Premium sayfam', 'subscription', sendMail: false);
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
            route('driver.loads.index', ['tab' => 'external']), 'İlanlara git', 'subscription');

        return $subscription;
    }

    /** Süresi dolan abonelikleri kapatır (zamanlanmış görev). premium_until zaten geçmişte olduğu için erişim kendiliğinden düşer. */
    public function expireDue(): int
    {
        $due = Subscription::query()->with('user')->where('status', 'active')
            ->whereNotNull('current_period_ends_at')->where('current_period_ends_at', '<', now())->get();
        if ($due->isEmpty()) {
            return 0;
        }

        $count = Subscription::query()->whereIn('id', $due->pluck('id'))->update(['status' => 'expired', 'ended_at' => now()]);

        foreach ($due as $subscription) {
            if ($user = $subscription->user) {
                $this->notifications->notify($user, 'Premium üyeliğiniz sona erdi',
                    ['Premium döneminiz '.$subscription->current_period_ends_at->format('d.m.Y').' tarihinde bitti; hesabınız standart plana döndü.', 'Onaylı dış kaynak ilanlarını yine herkesten önce görmek için premium\'u istediğiniz zaman yeniden başlatabilirsiniz.'],
                    route('driver.premium.index'), 'Premium\'u yeniden başlat', 'subscription');
            }
        }

        return $count;
    }

    /** Bitişine belirli gün kalan premium üyeler için tek seferlik hatırlatma (zamanlanmış görev). */
    public function remindExpiring(int $daysBefore = 3): int
    {
        $window = [now()->startOfDay(), now()->addDays($daysBefore)->endOfDay()];
        $sent = 0;
        Subscription::query()->with('user')->where('status', 'active')->whereBetween('current_period_ends_at', $window)
            ->get()->each(function (Subscription $subscription) use (&$sent): void {
                $user = $subscription->user;
                if (! $user) {
                    return;
                }
                $already = UserNotification::query()->where('user_id', $user->id)->where('type', 'subscription')
                    ->where('title', 'Premium üyeliğiniz yakında sona eriyor')->where('created_at', '>=', now()->subDays(10))->exists();
                if ($already) {
                    return;
                }
                $this->notifications->notify($user, 'Premium üyeliğiniz yakında sona eriyor',
                    ['Premium döneminiz '.$subscription->current_period_ends_at->format('d.m.Y').' tarihinde bitiyor. Üyelik otomatik yenilenmez.', 'Kesinti olmaması için şimdi 1 ay daha uzatabilirsiniz; süre mevcut dönemin bitiminden itibaren eklenir.'],
                    route('driver.premium.checkout'), '1 ay daha uzat', 'subscription');
                $sent++;
            });

        return $sent;
    }
}
