<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\DriverProfile;
use App\Models\Invoice;
use App\Models\PaymentOrder;
use App\Models\StoredCard;
use App\Models\Subscription;
use App\Models\SubscriptionCycle;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Premium şoför üyeliği: 1, 3, 6 ya da 12 aylık dönemler halinde satın alınır (uzun sürede panel ayarlı indirim).
 * Ödeme yalnız doğrulanmış sunucu bildirimiyle etkinleşir (PaymentService::afterPaid → activate).
 * Otomatik yenileme (Osman, 2026-10-09: "bir kere kayıt olsun, her ay otomatik devam etsin"): ödeme kuruluşunda kart saklama
 * açıksa şoför satın alma ekranında seçer; kart kuruluşta saklanır, dönem bitiminden RENEWAL_LEAD_HOURS önce aynı süre için
 * güncel fiyattan çekilir (renewDue), başarısızlıkta 24 saat arayla en çok RENEWAL_MAX_ATTEMPTS deneme, sonra kapanır.
 * Şoför Premium sayfasından tek dokunuşla kapatır; kapatma dönem sonuna kadar hakları etkilemez. Kart saklama kapalıysa
 * (ya da seçilmediyse) abonelik eskisi gibi tek seferliktir ve otomatik yenilenmez.
 */
class SubscriptionService
{
    public const PLAN_PREMIUM_MONTHLY = 'premium_monthly';

    /** Yenileme çekimi dönem bitiminden bu kadar saat önce başlar (3 deneme 24 saat arayla bitişten önce tamamlanır). */
    public const RENEWAL_LEAD_HOURS = 72;

    public const RENEWAL_RETRY_HOURS = 24;

    public const RENEWAL_MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly PaymentService $payments,
        private readonly NotificationService $notifications,
        private readonly LedgerService $ledger,
    ) {}

    public function monthlyPrice(): float
    {
        return round(Settings::float('premium_monthly_price'), 2);
    }

    /** Satın alınabilen süreler (ay). 1 ay tam fiyat; diğerleri panel ayarındaki yüzdeyle indirimli. */
    public const PLAN_MONTHS = [1, 3, 6, 12];

    /** Süreye göre indirim yüzdesi (panel: premium_discount_3m/6m/12m; 0-90 arası). */
    public function discountFor(int $months): float
    {
        if (! in_array($months, self::PLAN_MONTHS, true) || $months === 1) {
            return 0.0;
        }

        return max(0.0, min(90.0, Settings::float('premium_discount_'.$months.'m')));
    }

    /** Seçilen sürenin toplam ücreti (KDV dahil): aylık × ay × (1 − indirim). */
    public function priceFor(int $months): float
    {
        if (! in_array($months, self::PLAN_MONTHS, true)) {
            throw new RuntimeException('Geçersiz üyelik süresi.');
        }

        return round($this->monthlyPrice() * $months * (1 - $this->discountFor($months) / 100), 2);
    }

    /**
     * Plan kartları: ay, toplam, aya düşen, indirim yüzdesi, tasarruf, etiket.
     *
     * @return list<array{months:int,price:float,per_month:float,discount:float,saving:float,label:string}>
     */
    public function plans(): array
    {
        $monthly = $this->monthlyPrice();

        return array_map(function (int $months) use ($monthly): array {
            $price = $this->priceFor($months);

            return [
                'months' => $months,
                'price' => $price,
                'per_month' => $months > 0 ? round($price / $months, 2) : $price,
                'discount' => $this->discountFor($months),
                'saving' => round($monthly * $months - $price, 2),
                'label' => $months === 1 ? '1 ay' : $months.' ay',
            ];
        }, self::PLAN_MONTHS);
    }

    public const PLAN_PREMIUM_GIFT = 'premium_gift';

    /** Ücretsiz deneme dönemi: her şoföre bir kez, ödeme kaydı yok, otomatik ücretlendirme yok. */
    public const PLAN_PREMIUM_TRIAL = 'premium_trial';

    /** Panel ayarı: ücretsiz deneme süresi (gün); 0 ise deneme kapalı. */
    public function trialDays(): int
    {
        return max(0, min(90, Settings::int('premium_trial_days')));
    }

    /**
     * Bu şoför ücretsiz denemeyi başlatabilir mi? Deneme açık, belgeler onaylı, şu an premium değil, daha önce deneme ya da
     * ücretli dönem kullanmamış.
     */
    public function trialEligible(?DriverProfile $profile): bool
    {
        if (! $profile || $this->trialDays() <= 0 || $profile->is_staff_view || ! $profile->isKycApproved() || $profile->trial_started_at || $profile->isPremium()) {
            return false; // zaten premium (ücretli ya da hediye) olana deneme sunulmaz
        }

        return ! Subscription::query()->where('user_id', $profile->user_id)
            ->whereIn('plan_code', [self::PLAN_PREMIUM_MONTHLY, self::PLAN_PREMIUM_TRIAL])->exists();
    }

    /**
     * Ücretsiz denemeyi başlatır (belge onayında kendiliğinden ya da Premium sayfasındaki düğmeyle). Mevcut premium süre bitmediyse
     * üzerine eklenir. Bir kez verilir; uygun değilse null döner, hata fırlatmaz (onay akışını bozmasın).
     */
    public function startTrial(User $user, bool $automatic = false): ?Subscription
    {
        $profile = $user->driverProfile;
        if (! $this->trialEligible($profile)) {
            return null;
        }
        $days = $this->trialDays();

        $subscription = DB::transaction(function () use ($user, $profile, $days, $automatic): ?Subscription {
            $locked = DriverProfile::query()->lockForUpdate()->find($profile->id);
            if (! $locked || $locked->trial_started_at) {
                return null; // aynı anda iki istek: ikincisi boş döner
            }
            $start = $locked->premium_until && $locked->premium_until->isFuture() ? $locked->premium_until->copy() : now();
            $end = $start->copy()->addDays($days);
            $locked->update(['premium_until' => $end, 'trial_started_at' => now()]);

            $subscription = Subscription::create([
                'user_id' => $user->id,
                'plan_code' => self::PLAN_PREMIUM_TRIAL,
                'provider' => 'manual',
                'status' => 'active',
                'amount' => 0,
                'currency' => 'TRY',
                'interval' => 'trial',
                'trial_ends_at' => $end,
                'current_period_starts_at' => $start,
                'current_period_ends_at' => $end,
            ]);
            ActivityLog::record('subscription.trial_started', "Ücretsiz premium deneme: {$days} gün → kullanıcı #{$user->id} (".$end->format('d.m.Y').' tarihine kadar'.($automatic ? ', belge onayında' : ', şoför başlattı').')', null, $subscription);

            return $subscription;
        });
        if (! $subscription) {
            return null;
        }

        $until = $profile->fresh()->premium_until->format('d.m.Y H:i');
        $this->notifications->notify($user, "{$days} günlük premium deneme süreniz başladı",
            ["Premium'un tüm özellikleri {$until} tarihine kadar ücretsiz: gruplardan derlenen ilanlar ilan bilgileriyle, yeni ilanlar herkesten önce ve bildirimle, dönüş yükü radarı.",
                'Kart bilgisi istenmez, süre sonunda ücret alınmaz; beğenirseniz Premium sayfasından aylık devam edersiniz.'],
            route('driver.loads.index', ['tab' => 'external']), 'İlanlara git', 'subscription');

        return $subscription;
    }

    /** Sürmekte olan deneme döneminin bitişi; deneme yoksa ya da ücretli dönem de varsa null (ekranda "deneme" yazmasın). */
    public function activeTrialEndsAt(User $user): ?Carbon
    {
        $trial = Subscription::query()->where('user_id', $user->id)->where('plan_code', self::PLAN_PREMIUM_TRIAL)
            ->where('status', 'active')->where('current_period_ends_at', '>', now())->value('current_period_ends_at');
        if (! $trial) {
            return null;
        }
        $paid = Subscription::query()->where('user_id', $user->id)->where('plan_code', self::PLAN_PREMIUM_MONTHLY)
            ->where('status', 'active')->where('current_period_ends_at', '>', now())->exists();

        return $paid ? null : Carbon::parse($trial);
    }

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
                'Yeni ilanları herkesten 20 dakika önce görür, anında bildirim alırsınız; yalnız premium üyelere açık dış kaynak ilanlarını ilan sahibinin numarasıyla görürsünüz.'],
            route('driver.premium.index'), 'Premium sayfam', 'subscription');

        return $subscription;
    }

    /**
     * Premium'u geri alır. Varsayılan: yalnız hediye/test süresi (premium_gift) iptal edilir; ücretli dönem varsa premium o dönemin
     * sonuna kadar sürer (abonelik iadesiz, dönem sonuna kadar kullanılır). $includePaid=true yalnız açıkça istenirse ücretli
     * aboneliği de bitirir.
     */
    public function revokePremium(User $user, ?User $admin = null, bool $includePaid = false): void
    {
        $profile = $user->driverProfile;
        if (! $profile || ! $profile->premium_until || $profile->premium_until->isPast()) {
            return;
        }
        $query = Subscription::query()->where('user_id', $user->id)->where('status', 'active');
        if (! $includePaid) {
            $query->where('plan_code', self::PLAN_PREMIUM_GIFT);
        }
        $query->update(['status' => 'cancelled', 'cancelled_at' => now(), 'ended_at' => now(), 'auto_renew' => false]);

        // Kalan aktif (ücretli) dönemin sonu yeni premium bitişidir; yoksa hemen biter.
        $remaining = Subscription::query()->where('user_id', $user->id)->where('status', 'active')
            ->whereNotNull('current_period_ends_at')->where('current_period_ends_at', '>', now())->max('current_period_ends_at');
        $profile->update(['premium_until' => $remaining ? Carbon::parse($remaining) : now()]);

        ActivityLog::record('subscription.revoked', 'Premium '.($includePaid ? 'tamamen' : 'hediye süresi').' kaldırıldı → kullanıcı #'.$user->id, $admin?->id);
        if ($remaining) {
            $this->notifications->notify($user, 'Hediye premium süreniz kaldırıldı', ['Yönetici tarafından tanımlanan hediye süresi kaldırıldı; ücretli premium döneminiz '.$profile->fresh()->premium_until->format('d.m.Y H:i').' tarihine kadar sürer.'], route('driver.premium.index'), 'Premium sayfam', 'subscription', sendMail: false);
        } else {
            $this->notifications->notify($user, 'Premium üyeliğiniz sona erdi', ['Premium üyeliğiniz yönetici tarafından sonlandırıldı; hesabınız standart üyeliğe döndü.'], route('driver.premium.index'), 'Premium sayfam', 'subscription', sendMail: false);
        }
    }

    /** Ödeme kuruluşunda kart saklama açık mı: açıksa satın alma ekranında "otomatik yenile" seçeneği çıkar. */
    public function autoRenewAvailable(): bool
    {
        return $this->payments->gateway()->supportsStoredCards();
    }

    /** Kullanıcının etkin kuruluşta kayıtlı kartı (en yenisi). */
    public function storedCardFor(User $user): ?StoredCard
    {
        return StoredCard::query()->where('user_id', $user->id)->where('provider', $this->payments->gateway()->id())->latest('id')->first();
    }

    /** Sürmekte olan ücretli premium aboneliği (yenileme tercihi bunun üzerinde tutulur). */
    public function activePaidSubscription(User $user): ?Subscription
    {
        return Subscription::query()->where('user_id', $user->id)->where('plan_code', self::PLAN_PREMIUM_MONTHLY)
            ->where('status', 'active')->where('current_period_ends_at', '>', now())->latest('id')->first();
    }

    /** Bu abonelik dönem sonunda gerçekten yenilenecek mi (tercih açık + kart var + kuruluşta kart saklama açık + deneme hakkı var). */
    public function willAutoRenew(Subscription $subscription): bool
    {
        return $subscription->auto_renew && $subscription->stored_card_id && $subscription->storedCard
            && $subscription->renewal_failures < self::RENEWAL_MAX_ATTEMPTS && $this->autoRenewAvailable()
            && $subscription->storedCard->provider === $this->payments->gateway()->id();
    }

    /** Şoför için premium ödeme emri; ödeme ekranı PaymentService::checkout ile açılır. $autoRenew yalnız kart saklama açıkken işlenir. */
    public function startCheckout(User $driverUser, int $months = 1, bool $autoRenew = false): PaymentOrder
    {
        if ($driverUser->driverProfile?->is_staff_view) {
            throw new RuntimeException('Yönetici görünümünde işlem yapılamaz.');
        }
        $profile = $driverUser->driverProfile;
        if (! $profile) {
            throw new RuntimeException('Premium üyelik yalnız şoför hesapları için geçerlidir.');
        }
        if (! $profile->isKycApproved()) {
            throw new RuntimeException('Premium üyelik için belgelerinizin onaylanmış olması gerekir.');
        }

        return $this->payments->orderForSubscription($driverUser, $this->priceFor($months), $months, $autoRenew && $this->autoRenewAvailable());
    }

    /**
     * Ödenmiş abonelik siparişini üyeliğe çevirir: dönem, fatura kaydı, premium süresi, bildirim, defter.
     * $card: ödeme sırasında kuruluşun kaydettiği kart (otomatik yenileme için); yoksa kullanıcının önceki kayıtlı kartı aranır.
     */
    public function activate(PaymentOrder $order, ?StoredCard $card = null): ?Subscription
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

        $months = max(1, (int) ($order->subscription_months ?: 1));
        $isRenewal = $order->stored_card_id !== null;
        // Otomatik yenileme: satın almada istenmişse ve kuruluş bir kart kaydettiyse (bu ödemede ya da daha önce) açılır.
        $card ??= $order->storedCard ?? ($order->auto_renew ? StoredCard::query()->where('user_id', $user->id)->where('provider', (string) $order->provider)->latest('id')->first() : null);
        $autoRenew = (bool) $order->auto_renew && $card !== null;
        $subscription = DB::transaction(function () use ($order, $user, $profile, $months, $autoRenew, $card): Subscription {
            // Mevcut süre bitmediyse üzerine eklenir; bittiyse bugünden başlar. Seçilen süre (1/3/6/12 ay) emirde durur.
            $start = $profile->premium_until && $profile->premium_until->isFuture() ? $profile->premium_until->copy() : now();
            $end = $start->copy()->addMonthsNoOverflow($months); // 31 Ocak + 1 ay = 28/29 Şubat (3 Mart'a taşmaz)

            $subscription = Subscription::query()->where('user_id', $user->id)->where('plan_code', self::PLAN_PREMIUM_MONTHLY)->latest('id')->first()
                ?? Subscription::create([
                    'user_id' => $user->id,
                    'plan_code' => self::PLAN_PREMIUM_MONTHLY,
                    'provider' => $order->provider,
                    'status' => 'active',
                    'amount' => $order->amount,
                    'currency' => $order->currency,
                    'interval' => $months === 1 ? 'monthly' : $months.'_months',
                ]);

            $subscription->update([
                'status' => 'active',
                'amount' => $order->amount,
                'interval' => $months === 1 ? 'monthly' : $months.'_months',
                'provider' => $order->provider,
                'current_period_starts_at' => $start,
                'current_period_ends_at' => $end,
                'cancelled_at' => null,
                'ended_at' => null,
                'auto_renew' => $autoRenew,
                'renew_months' => $months,
                'stored_card_id' => $autoRenew ? $card->id : null,
                'renewal_failures' => 0,
                'last_renewal_error' => null,
                'next_renewal_attempt_at' => null,
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

        $until = $profile->fresh()->premium_until?->format('d.m.Y H:i');
        $priceText = number_format((float) $order->amount, 2, ',', '.');
        if ($isRenewal) {
            $this->notifications->notify($user, 'Premium üyeliğiniz yenilendi',
                ['Kayıtlı kartınızdan ('.$card?->label().') '.$priceText.' ₺ çekildi; premium üyeliğiniz '.$until.' tarihine kadar uzatıldı. Faturanız Premium sayfanızda.',
                    'Otomatik yenilemeyi istediğiniz zaman Premium sayfasından tek dokunuşla kapatabilirsiniz; kapatma dönem sonuna kadar haklarınızı etkilemez.'],
                route('driver.premium.index'), 'Premium sayfam', 'subscription');

            return $subscription;
        }
        $lines = [$months.' aylık premium üyeliğiniz '.$until.' tarihine kadar geçerli. Onaylı dış kaynak ilanlarını artık herkesten önce, ilan sahibinin numarasıyla görüyorsunuz.'];
        if ($autoRenew) {
            $lines[] = 'Otomatik yenileme açık: dönem bitiminden 3 gün önce kayıtlı kartınızdan ('.$card->label().') o günkü '.$months.' aylık ücret çekilir ve üyelik kesintisiz sürer; bedel çekimden önce bildirilir. Premium sayfasından tek dokunuşla kapatabilirsiniz.';
        } elseif ($order->auto_renew) {
            $lines[] = 'Ödeme sayfasında kart kaydedilmediği için otomatik yenileme açılmadı; üyelik dönem sonunda biter. İsterseniz bir sonraki ödemede "kartımı sakla" seçeneğini işaretleyin.';
        }
        $this->notifications->notify($user, 'Premium üyeliğiniz etkinleşti', $lines, route('driver.loads.index', ['tab' => 'external']), 'İlanlara git', 'subscription');

        return $subscription;
    }

    /**
     * Zamanlanmış görev: dönemi RENEWAL_LEAD_HOURS içinde biten, otomatik yenilemesi açık ve kartı kayıtlı abonelikleri güncel
     * fiyattan çeker. Başarıda dönem eklenir (activate); başarısızlıkta 24 saat sonra tekrar denenir, üçüncü başarısızlıkta ya da
     * kart geçersizse yenileme kapanır ve şoföre haber verilir. Çekim satır kilidi dışında yapılır; kilit içinde yalnız "sıra bende"
     * damgası (next_renewal_attempt_at) basılır ki iki işçi aynı aboneliği iki kez çekmesin. Yenilenen abonelik sayısını döndürür.
     */
    public function renewDue(int $limit = 50): int
    {
        if (! $this->autoRenewAvailable()) {
            return 0;
        }
        $providerId = $this->payments->gateway()->id();
        $ids = Subscription::query()->where('plan_code', self::PLAN_PREMIUM_MONTHLY)->where('status', 'active')->where('auto_renew', true)
            ->whereNotNull('stored_card_id')->where('renewal_failures', '<', self::RENEWAL_MAX_ATTEMPTS)
            ->where('current_period_ends_at', '>', now())->where('current_period_ends_at', '<=', now()->addHours(self::RENEWAL_LEAD_HOURS))
            ->where(fn ($q) => $q->whereNull('next_renewal_attempt_at')->orWhere('next_renewal_attempt_at', '<=', now()))
            ->orderBy('current_period_ends_at')->limit($limit)->pluck('id');

        $renewed = 0;
        foreach ($ids as $id) {
            $subscription = DB::transaction(function () use ($id): ?Subscription {
                $locked = Subscription::query()->lockForUpdate()->find($id);
                if (! $locked || ! $locked->auto_renew || $locked->status !== 'active' || ($locked->next_renewal_attempt_at && $locked->next_renewal_attempt_at->isFuture())) {
                    return null;
                }
                $locked->update(['next_renewal_attempt_at' => now()->addHours(2)]); // sıra bende: aynı anda ikinci çekim yok

                return $locked;
            });
            if (! $subscription) {
                continue;
            }
            if ($this->renewOne($subscription->fresh(['user.driverProfile', 'storedCard']), $providerId)) {
                $renewed++;
            }
        }

        return $renewed;
    }

    /** Tek aboneliğin yenileme çekimi; sonuçta abonelik satırını ve bildirimi yazar. */
    private function renewOne(Subscription $subscription, string $providerId): bool
    {
        $user = $subscription->user;
        $card = $subscription->storedCard;
        if (! $user || ! $card || $card->provider !== $providerId || ! $user->is_active || $user->banned_at) {
            // Kart silinmiş, kuruluş değişmiş ya da hesap kapalı: sessizce kapanır, dönem sonunda üyelik biter (bitiş bildirimi gider).
            $subscription->update(['auto_renew' => false, 'next_renewal_attempt_at' => null, 'last_renewal_error' => ! $card ? 'Kayıtlı kart bulunamadı.' : ($card->provider !== $providerId ? 'Ödeme kuruluşu değişti.' : 'Hesap kapalı.')]);

            return false;
        }
        $months = max(1, min(12, (int) $subscription->renew_months ?: 1));
        if (! in_array($months, self::PLAN_MONTHS, true)) {
            $months = 1;
        }
        $order = $this->payments->orderForSubscription($user, $this->priceFor($months), $months, true, $card);
        $result = $this->payments->chargeRenewal($order, $card);
        if ($result->succeeded) {
            return true; // activate() yenileme alanlarını sıfırladı ve bildirimi gönderdi
        }

        $failures = (int) $subscription->renewal_failures + 1;
        $error = mb_substr((string) ($result->failureMessage ?: 'Çekim reddedildi'), 0, 255);
        $endText = $subscription->current_period_ends_at->format('d.m.Y');
        if ($result->cardInvalid || $failures >= self::RENEWAL_MAX_ATTEMPTS) {
            $subscription->update(['auto_renew' => false, 'renewal_failures' => $failures, 'last_renewal_error' => $error, 'next_renewal_attempt_at' => null]);
            ActivityLog::record('subscription.auto_renew_stopped', "Otomatik yenileme kapatıldı (kullanıcı #{$user->id}): {$error}", null, $subscription);
            $this->notifications->notify($user, 'Otomatik yenileme kapatıldı',
                ['Premium yenileme ödemesi kayıtlı kartınızdan ('.$card->label().') alınamadı: '.$error.($result->cardInvalid ? '' : ' ('.$failures.' deneme).'),
                    'Üyeliğiniz '.$endText.' tarihinde bitecek. Kesintisiz sürmesi için Premium sayfasından yeni ödeme yapın; yeni kartınız kaydedilir ve otomatik yenileme yeniden açılır.'],
                route('driver.premium.index'), 'Premium sayfam', 'subscription');

            return false;
        }
        $subscription->update(['renewal_failures' => $failures, 'last_renewal_error' => $error, 'next_renewal_attempt_at' => now()->addHours(self::RENEWAL_RETRY_HOURS)]);
        $this->notifications->notify($user, 'Premium yenileme ödemesi alınamadı',
            ['Kayıtlı kartınızdan ('.$card->label().') çekim yapılamadı: '.$error.'. '.self::RENEWAL_RETRY_HOURS.' saat sonra yeniden denenecek ('.$failures.'/'.self::RENEWAL_MAX_ATTEMPTS.').',
                'Kartınızda sorun varsa Premium sayfasından yeni ödeme yapabilirsiniz; üyeliğiniz '.$endText.' tarihine kadar sürer.'],
            route('driver.premium.index'), 'Premium sayfam', 'subscription');

        return false;
    }

    /**
     * Şoför Premium sayfasından otomatik yenilemeyi açar/kapatır (tek dokunuş). Açmak için kayıtlı kart ve kuruluşta kart saklama
     * gerekir; yoksa false döner (ekran "yeni ödeme yapın" der). Kapatma dönem sonuna kadar hakları etkilemez.
     */
    public function setAutoRenew(User $user, bool $on): bool
    {
        $subscription = $this->activePaidSubscription($user);
        if (! $subscription) {
            return false;
        }
        if (! $on) {
            $subscription->update(['auto_renew' => false, 'next_renewal_attempt_at' => null]);
            ActivityLog::record('subscription.auto_renew_off', 'Otomatik yenileme kapatıldı (şoför)', $user->id, $subscription);

            return true;
        }
        $card = $this->storedCardFor($user);
        if (! $card || ! $this->autoRenewAvailable()) {
            return false;
        }
        $subscription->update(['auto_renew' => true, 'stored_card_id' => $card->id, 'renew_months' => in_array((int) $subscription->renew_months, self::PLAN_MONTHS, true) ? $subscription->renew_months : 1,
            'renewal_failures' => 0, 'last_renewal_error' => null, 'next_renewal_attempt_at' => null]);
        ActivityLog::record('subscription.auto_renew_on', 'Otomatik yenileme açıldı (şoför, '.$card->label().')', $user->id, $subscription);

        return true;
    }

    /** Kayıtlı kartı kuruluştan ve NavlunIQ'dan siler; karta bağlı yenilemeler kapanır. */
    public function deleteStoredCard(User $user, StoredCard $card): void
    {
        if ($card->user_id !== $user->id) {
            throw new RuntimeException('Bu kart size ait değil.');
        }
        try {
            $this->payments->gateway()->deleteStoredCard($card);
        } catch (\Throwable $e) {
            Log::warning('Kayıtlı kart kuruluştan silinemedi.', ['card' => $card->id, 'error' => $e->getMessage()]);
        }
        Subscription::query()->where('stored_card_id', $card->id)->update(['auto_renew' => false, 'stored_card_id' => null, 'next_renewal_attempt_at' => null]);
        $card->delete();
        ActivityLog::record('subscription.card_deleted', 'Kayıtlı kart silindi ('.$card->label().')', $user->id);
    }

    /**
     * Süresi dolan abonelikleri kapatır (zamanlanmış görev). premium_until zaten geçmişte olduğu için erişim kendiliğinden düşer.
     * Hediye satırı bitti ama ücretli dönem sürüyorsa (premium_until ileride) "sona erdi" bildirimi gönderilmez.
     */
    public function expireDue(): int
    {
        $due = Subscription::query()->with('user.driverProfile')->where('status', 'active')
            ->whereNotNull('current_period_ends_at')->where('current_period_ends_at', '<', now())->get();
        if ($due->isEmpty()) {
            return 0;
        }

        $count = Subscription::query()->whereIn('id', $due->pluck('id'))->update(['status' => 'expired', 'ended_at' => now()]);

        foreach ($due as $subscription) {
            $user = $subscription->user;
            if (! $user || $user->driverProfile?->isPremium()) {
                continue; // başka bir dönem hâlâ sürüyor: premium bitmedi
            }
            if ($subscription->plan_code === self::PLAN_PREMIUM_TRIAL) {
                $this->notifications->notify($user, 'Ücretsiz deneme süreniz bitti',
                    ['Premium deneme döneminiz '.$subscription->current_period_ends_at->format('d.m.Y').' tarihinde bitti; hesabınız standart üyeliğe döndü, ücret alınmadı.', 'Gruplardan derlenen ilanları ve erken erişimi sürdürmek için Premium sayfasından aylık '.number_format($this->monthlyPrice(), 0, ',', '.').' ₺ ile devam edebilirsiniz.'],
                    route('driver.premium.index'), 'Premium ile devam et', 'subscription');

                continue;
            }
            $this->notifications->notify($user, 'Premium üyeliğiniz sona erdi',
                ['Premium döneminiz '.$subscription->current_period_ends_at->format('d.m.Y').' tarihinde bitti; hesabınız standart plana döndü.'.($subscription->last_renewal_error ? ' Otomatik yenileme ödemesi alınamamıştı: '.$subscription->last_renewal_error : ''), 'Onaylı dış kaynak ilanlarını yine herkesten önce görmek için premium\'u istediğiniz zaman yeniden başlatabilirsiniz.'],
                route('driver.premium.index'), 'Premium\'u yeniden başlat', 'subscription');
        }

        return $count;
    }

    /**
     * Bitişine belirli gün kalan premium üyeler için tek seferlik hatırlatma (zamanlanmış görev). Otomatik yenilemesi açık
     * abonelikte hatırlatma çekimden önce gider (pencere 2 gün daha geniş) ve çekilecek tutarı söyler (bedel önceden bildirilir).
     */
    public function remindExpiring(int $daysBefore = 3): int
    {
        $window = [now()->startOfDay(), now()->addDays($daysBefore + 2)->endOfDay()];
        $sent = 0;
        Subscription::query()->with(['user', 'storedCard'])->where('status', 'active')->whereBetween('current_period_ends_at', $window)
            ->get()->each(function (Subscription $subscription) use (&$sent, $daysBefore): void {
                $user = $subscription->user;
                if (! $user) {
                    return;
                }
                $renewing = $this->willAutoRenew($subscription);
                if (! $renewing && $subscription->current_period_ends_at->gt(now()->addDays($daysBefore)->endOfDay())) {
                    return; // yenilenmeyen abonelikte pencere eskisi gibi $daysBefore gün
                }
                $already = UserNotification::query()->where('user_id', $user->id)->where('type', 'subscription')
                    ->where('title', 'Premium üyeliğiniz yakında sona eriyor')->where('created_at', '>=', now()->subDays(10))->exists();
                if ($already) {
                    return;
                }
                if ($subscription->plan_code === self::PLAN_PREMIUM_TRIAL) {
                    $this->notifications->notify($user, 'Premium üyeliğiniz yakında sona eriyor',
                        ['Ücretsiz deneme süreniz '.$subscription->current_period_ends_at->format('d.m.Y').' tarihinde bitiyor; ücret alınmaz, hesabınız standart üyeliğe döner.', 'Gruplardan derlenen ilanları ve erken erişimi kesintisiz sürdürmek için şimdi aylık premium başlatabilirsiniz; süre denemenin bitiminden itibaren eklenir.'],
                        route('driver.premium.index'), 'Premium ile devam et', 'subscription');
                } elseif ($renewing) {
                    $months = in_array((int) $subscription->renew_months, self::PLAN_MONTHS, true) ? (int) $subscription->renew_months : 1;
                    $this->notifications->notify($user, 'Premium üyeliğiniz yakında sona eriyor',
                        ['Premium döneminiz '.$subscription->current_period_ends_at->format('d.m.Y').' tarihinde bitiyor. Otomatik yenileme açık: bitişten 3 gün önce kayıtlı kartınızdan ('.$subscription->storedCard->label().') '.number_format($this->priceFor($months), 2, ',', '.').' ₺ çekilerek üyelik '.$months.' ay uzatılır.',
                            'İstemiyorsanız Premium sayfasından otomatik yenilemeyi tek dokunuşla kapatın; mevcut dönem sonuna kadar haklarınız sürer.'],
                        route('driver.premium.index'), 'Premium sayfam', 'subscription');
                } else {
                    $this->notifications->notify($user, 'Premium üyeliğiniz yakında sona eriyor',
                        ['Premium döneminiz '.$subscription->current_period_ends_at->format('d.m.Y').' tarihinde bitiyor. Üyelik otomatik yenilenmez.', 'Kesinti olmaması için şimdi uzatabilirsiniz (1, 3, 6 ya da 12 ay; uzun sürelerde indirim); süre mevcut dönemin bitiminden itibaren eklenir.'],
                        route('driver.premium.index'), 'Üyeliği uzat', 'subscription');
                }
                $sent++;
            });

        return $sent;
    }
}
