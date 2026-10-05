<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\DriverProfile;
use App\Models\Invoice;
use App\Models\Load;
use App\Models\PaymentOrder;
use App\Models\Payout;
use App\Models\PayoutAttempt;
use App\Models\User;
use App\Payments\GatewayManager;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Hakediş (şoför ödemesi). Tek canlı yol pazaryeri: ödeme kuruluşu şoför payını alt üye işyerine aktarır (releaseViaGateway).
 * Elle banka transferi (markPaid) yalnız aktarım başarısız kalırsa kullanılan yedek yoldur.
 */
class PayoutService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly NotificationService $notifications,
        private readonly GatewayManager $gateways,
    ) {}

    /** Onaylanmış teslimat için hakediş kaydı (ilan başına tek; payouts.load_id benzersiz). */
    public function createForLoad(Load $load): Payout
    {
        $driver = $load->driverProfile;
        if (! $driver) {
            throw new RuntimeException('Sevkiyata atanmış şoför bulunamadı.');
        }

        $existing = Payout::query()->where('load_id', $load->id)->first();
        if ($existing) {
            return $existing;
        }
        if ($load->escrow_status !== Load::ESCROW_RELEASE_APPROVED) {
            throw new RuntimeException('Hakediş yalnız ödemesi alınmış ve serbest bırakılması onaylanmış sevkiyat için açılabilir.');
        }

        // Komisyon, ödeme emrinde dondurulmuş orandan okunur: ayar sonradan değişse de şoföre teklif anında gösterilen rakam ödenir.
        $order = PaymentOrder::query()->where('load_id', $load->id)->where('purpose', PaymentService::PURPOSE_ESCROW)->where('status', 'paid')->latest('id')->first();
        $total = round((float) $load->price, 2);
        if ($order && $order->commission_amount !== null && $order->driver_net_amount !== null) {
            $commission = round((float) $order->commission_amount, 2);
            $net = round((float) $order->driver_net_amount, 2);
        } else {
            $commission = round($total * $driver->commissionRate() / 100, 2);
            $net = round($total - $commission, 2);
        }
        $bankAccount = $driver->user?->defaultBankAccount;

        $payout = Payout::create([
            'load_id' => $load->id,
            'user_id' => $driver->user_id,
            'bank_account_id' => $bankAccount?->id,
            'total_amount' => $total,
            'commission_amount' => $commission,
            'net_amount' => $net,
            'currency' => 'TRY',
            'status' => 'pending',
            'available_at' => now(),
        ]);

        try {
            $this->ledger->post('payout_accrual', 'Hakediş tahakkuku #'.$load->id, [
                ['account_code' => 'escrow_liability', 'direction' => 'debit', 'amount' => $total],
                ['account_code' => 'driver_payable', 'direction' => 'credit', 'amount' => $net],
                ['account_code' => 'commission_revenue', 'direction' => 'credit', 'amount' => $commission],
            ], Payout::class, $payout->id);
        } catch (\Throwable $e) {
            Log::error('Hakediş tahakkuku muhasebeye yazılamadı.', ['payout' => $payout->id, 'error' => $e->getMessage()]);
        }

        // Platform hizmet bedeli faturası (şoföre); numara e-belge sağlayıcısı bağlanınca yazılır.
        if ($commission > 0) {
            $vatRate = Settings::float('payment_vat_rate');
            $base = round($commission / (1 + $vatRate / 100), 2);
            Invoice::firstOrCreate(['payout_id' => $payout->id, 'invoice_type' => 'commission'], [
                'user_id' => $driver->user_id,
                'base_amount' => $base,
                'tax_amount' => round($commission - $base, 2),
                'total_amount' => $commission,
                'currency' => 'TRY',
                'tax_rate' => $vatRate,
                'status' => 'pending',
            ]);
        }

        // Pazaryeri modelinde ödeme kuruluşu alt üye işyerine aktarımı yapar; desteklenmiyorsa finans ekibi banka transferi yapar.
        $this->releaseViaGateway($payout);

        return $payout;
    }

    /**
     * Pazaryeri modelinde şoförü ödeme kuruluşuna alt üye işyeri olarak kaydeder (bir kez). Kayıt için kimlik (bireyselde TC,
     * şirkette VKN) ve varsayılan IBAN gerekir; eksikse ya da kuruluş reddederse false döner. Sahte kimlik hiç gönderilmez.
     */
    public function ensureSubMerchant(DriverProfile $driver): bool
    {
        $gateway = $this->gateways->active();
        if (! $gateway->supportsSubMerchants()) {
            return false;
        }
        if ($driver->payout_provider_ref && $driver->payout_provider === $gateway->id()) {
            return true;
        }

        $user = $driver->user;
        $account = $user?->defaultBankAccount;
        if (! $user || ! $account || ! $driver->hasPayoutIdentity()) {
            return false;
        }

        try {
            $ref = $gateway->registerSubMerchant([
                // IBAN değişince yeni hesapla yeniden kaydedilir; kuruluşta dış kimlik benzersiz olduğundan hesap kimliği eklenir.
                'external_id' => 'DRV-'.$driver->id.'-'.$account->id,
                'name' => $user->first_name,
                'surname' => $user->last_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'iban' => app(BankAccountService::class)->decrypt($account),
                'legal_type' => $driver->legal_type ?: DriverProfile::LEGAL_INDIVIDUAL,
                'identity' => (string) ($driver->identity_number ?? ''),
                'tax_no' => (string) ($driver->tax_number ?? ''),
                'company_title' => $driver->legal_type === DriverProfile::LEGAL_COMPANY ? $account->account_holder : '',
                'address' => 'Türkiye',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Alt üye işyeri kaydı başarısız.', ['driver' => $driver->id, 'error' => $e->getMessage()]);

            return false;
        }

        if (! $ref) {
            return false;
        }

        $driver->update(['payout_provider_ref' => $ref, 'payout_provider' => $gateway->id()]);
        ActivityLog::record('payout.submerchant', "Şoför #{$driver->id} ödeme kuruluşuna alt üye işyeri olarak kaydedildi ({$gateway->id()})", null, $driver);

        return true;
    }

    /** Şoförün teklif kabulü / ödeme için eksiği: null ise hazır, değilse kullanıcıya gösterilecek gerekçe. */
    public function payoutReadinessBlocker(DriverProfile $driver): ?string
    {
        if (! $this->gateways->active()->supportsSubMerchants()) {
            return null;
        }
        $hasIban = $driver->user?->defaultBankAccount()->exists() ?? false;
        if (! $hasIban && ! $driver->hasPayoutIdentity()) {
            return 'Şoförün ödeme bilgileri eksik: Ödemelerim sayfasından IBAN ve kimlik (T.C. / vergi) numarası girilmeden teklif kabul edilemez.';
        }
        if (! $hasIban) {
            return 'Şoförün kayıtlı IBAN\'ı yok: Ödemelerim sayfasından IBAN girilmeden teklif kabul edilemez.';
        }
        if (! $driver->hasPayoutIdentity()) {
            return 'Şoförün kimlik (T.C. / vergi) numarası kayıtlı değil: Ödemelerim sayfasından girilmeden teklif kabul edilemez.';
        }

        return null;
    }

    /**
     * Etkin ödeme kuruluşu alt üye işyeri aktarımını destekliyorsa ve şoför kayıtlıysa hakedişi aktarır; her deneme
     * payout_attempts'e yazılır. Başarılıysa payout "paid" olur; aksi halde "pending" kalır ve payouts:reconcile artan beklemeyle
     * yeniden dener. IBAN yeni değişmişse (ayar: bank_change_hold_hours) aktarım bekletilir.
     */
    public function releaseViaGateway(Payout $payout, bool $force = false): bool
    {
        $gateway = $this->gateways->active();
        $driver = $payout->user?->driverProfile;
        if ($driver && $gateway->supportsSubMerchants() && ! $driver->payout_provider_ref) {
            $this->ensureSubMerchant($driver);
            $driver->refresh();
        }
        if (! $gateway->supportsSubMerchants() || ! $driver?->payout_provider_ref || $driver->payout_provider !== $gateway->id()) {
            return false;
        }
        if (! in_array($payout->status, ['pending', 'failed'], true)) {
            return false;
        }
        $hold = max(0, Settings::int('bank_change_hold_hours'));
        if (! $force && $hold > 0 && $driver->bank_account_changed_at && $driver->bank_account_changed_at->gt(now()->subHours($hold))) {
            $payout->update(['failure_reason' => 'IBAN yeni değişti; otomatik aktarım '.$driver->bank_account_changed_at->copy()->addHours($hold)->format('d.m.Y H:i').' sonrasına bekletildi.']);

            return false;
        }

        $attemptNo = (int) $payout->attempts()->max('attempt_no') + 1;
        $payout->update(['status' => 'processing', 'channel' => 'gateway']);
        $result = $gateway->transferToSubMerchant($payout, (string) $driver->payout_provider_ref);
        PayoutAttempt::create([
            'payout_id' => $payout->id,
            'provider' => $gateway->id(),
            'provider_reference' => $result->reference,
            'status' => $result->succeeded ? 'success' : 'failed',
            'attempt_no' => $attemptNo,
            'failure_message' => $result->succeeded ? null : mb_substr((string) $result->failureMessage, 0, 500),
            'attempted_at' => now(),
        ]);
        if (! $result->succeeded) {
            $payout->update(['status' => 'pending', 'failure_reason' => mb_substr((string) $result->failureMessage, 0, 500)]);
            Log::warning('Ödeme kuruluşu aktarımı başarısız; yeniden denenecek.', ['payout' => $payout->id, 'attempt' => $attemptNo, 'error' => $result->failureMessage]);
            if ($attemptNo === 1 || $attemptNo >= max(1, Settings::int('payout_retry_max_attempts'))) {
                $this->notifications->notifyAdmins('manage payouts', 'Hakediş aktarımı başarısız',
                    ["Hakediş #{$payout->id} ödeme kuruluşu üzerinden aktarılamadı ({$attemptNo}. deneme): ".($result->failureMessage ?: 'sebep belirtilmedi'),
                        $attemptNo >= max(1, Settings::int('payout_retry_max_attempts')) ? 'Deneme sınırı doldu; Finans ekranından banka transferiyle tamamlayın.' : 'Sistem artan aralıklarla yeniden deneyecek; sürerse Finans ekranından banka transferiyle tamamlayın.'],
                    route('admin.finance'), 'Finans ekranı', 'admin');
            }

            return false;
        }

        $this->settle($payout, 'gateway', (string) ($result->reference ?: $gateway->id().':'.now()->timestamp), null);

        return true;
    }

    /**
     * Zamanlanmış mutabakat (10 dk): hakedişsiz onaylı ilanlar, 15 dk'dan uzun "işlemde" kalan satırlar ve bekleyen pazaryeri
     * aktarımları (artan beklemeyle yeniden deneme).
     *
     * @return array{created:int, reset:int, retried:int}
     */
    public function reconcile(): array
    {
        $created = 0;
        Load::query()->with('driverProfile.user')->where('escrow_status', Load::ESCROW_RELEASE_APPROVED)
            ->whereDoesntHave('payout')->orderBy('id')->limit(100)->get()
            ->each(function (Load $load) use (&$created): void {
                try {
                    $this->createForLoad($load);
                    $created++;
                } catch (\Throwable $e) {
                    Log::warning('Mutabakat: hakediş açılamadı.', ['load' => $load->id, 'error' => $e->getMessage()]);
                }
            });

        $staleMinutes = max(1, Settings::int('payout_processing_stale_minutes'));
        $stale = Payout::query()->where('status', 'processing')->where('updated_at', '<', now()->subMinutes($staleMinutes))->get();
        foreach ($stale as $payout) {
            $payout->update(['status' => 'pending', 'failure_reason' => 'Aktarım '.$staleMinutes.' dakikadan uzun "işlemde" kaldı; sonuç alınamadı.']);
            $this->notifications->notifyAdmins('manage payouts', 'Hakediş aktarımı takıldı',
                ["Hakediş #{$payout->id} ".$staleMinutes.' dakikadan uzun süre "işlemde" kaldı; "bekliyor" durumuna alındı. Ödeme kuruluşu panelinde aktarımın gerçekleşip gerçekleşmediğini kontrol edin; gerçekleştiyse Finans ekranından referansla "Ödendi" deyin.'],
                route('admin.finance'), 'Finans ekranı', 'admin');
        }

        $retried = 0;
        $maxAttempts = max(1, Settings::int('payout_retry_max_attempts'));
        if ($this->gateways->active()->supportsSubMerchants()) {
            Payout::query()->with(['user.driverProfile', 'attempts'])->where('status', 'pending')->orderBy('id')->limit(100)->get()
                ->each(function (Payout $payout) use (&$retried, $maxAttempts): void {
                    $attempts = $payout->attempts->count();
                    if ($attempts >= $maxAttempts) {
                        return;
                    }
                    // Artan bekleme: 10, 20, 40, 80… dakika (ilk deneme hemen).
                    $last = $payout->attempts->max('attempted_at');
                    if ($attempts > 0 && $last && $last->gt(now()->subMinutes(10 * (2 ** ($attempts - 1))))) {
                        return;
                    }
                    if ($this->releaseViaGateway($payout)) {
                        $retried++;
                    }
                });
        }

        return ['created' => $created, 'reset' => $stale->count(), 'retried' => $retried];
    }

    /** Finans ekibi banka transferini tamamladığında çağrılır (yalnız aktarım başarısız kaldıysa kullanılan yedek yol). */
    public function markPaid(Payout $payout, User $admin, string $reference): void
    {
        $this->settle($payout, 'manual', $reference, $admin);
    }

    private function settle(Payout $payout, string $channel, string $reference, ?User $admin): void
    {
        DB::transaction(function () use ($payout, $channel, $reference, $admin): void {
            $locked = Payout::query()->lockForUpdate()->findOrFail($payout->id);
            if (! in_array($locked->status, ['pending', 'processing', 'failed'], true)) {
                throw new RuntimeException('Bu hakediş zaten sonuçlandırılmış.');
            }

            $bankAccount = $locked->bankAccount ?? $locked->user?->defaultBankAccount;
            $locked->update([
                'status' => 'paid',
                'channel' => $channel,
                'paid_at' => now(),
                'reference_no' => mb_substr(trim($reference), 0, 120),
                'failure_reason' => null,
                'bank_account_id' => $bankAccount?->id ?? $locked->bank_account_id,
            ]);

            Load::query()->whereKey($locked->load_id)->update(['escrow_status' => Load::ESCROW_RELEASED]);

            ActivityLog::record('payout.paid', "Hakediş #{$locked->id} ödendi ({$channel}: {$reference})", $admin?->id, $locked);
        });

        try {
            $this->ledger->post('payout_paid', 'Hakediş ödemesi #'.$payout->load_id, [
                ['account_code' => 'driver_payable', 'direction' => 'debit', 'amount' => $payout->net_amount],
                ['account_code' => 'escrow_cash', 'direction' => 'credit', 'amount' => $payout->net_amount],
            ], Payout::class, $payout->id);
        } catch (\Throwable $e) {
            Log::error('Hakediş ödemesi muhasebeye yazılamadı.', ['payout' => $payout->id, 'error' => $e->getMessage()]);
        }

        if ($driverUser = $payout->user) {
            $this->notifications->notify($driverUser, 'Ödemeniz hesabınıza geçti',
                [number_format((float) $payout->net_amount, 2, ',', '.').' ₺ tutarındaki navlun ödemeniz banka hesabınıza geçti. Referans: '.$reference],
                route('driver.wallet.index'), 'Ödemelerimi görüntüle', 'payout');
        }
    }

    public function markFailed(Payout $payout, User $admin, string $reason): void
    {
        // Yalnız bekleyen/işlemdeki hakediş "başarısız" olur; ödenmiş hakediş geri alınamaz (para gitmiştir).
        DB::transaction(function () use ($payout, $reason): void {
            $locked = Payout::query()->lockForUpdate()->findOrFail($payout->id);
            if (! in_array($locked->status, ['pending', 'processing'], true)) {
                throw new RuntimeException('Yalnız bekleyen ya da işlemdeki hakediş başarısız olarak işaretlenebilir.');
            }
            $locked->update(['status' => 'failed', 'failure_reason' => mb_substr(trim($reason), 0, 500)]);
        });
        $payout->refresh();
        ActivityLog::record('payout.failed', "Hakediş #{$payout->id} başarısız: {$reason}", $admin->id, $payout, ['amount' => (string) $payout->net_amount]);

        if ($driverUser = $payout->user) {
            $this->notifications->notify($driverUser, 'Ödemeniz yapılamadı',
                ['Ödemeniz banka tarafından tamamlanamadı: '.mb_substr(trim($reason), 0, 200), 'Lütfen Ödemelerim sayfasından IBAN ve hesap sahibi bilgilerinizi düzeltin, sonra "IBAN\'ı güncelledim, yeniden gönder" deyin.'],
                route('driver.wallet.index'), 'IBAN bilgilerimi kontrol et', 'payout');
        }
    }

    /**
     * Şoför IBAN'ını düzeltti ve "yeniden gönder" dedi: başarısız hakediş yeniden sıraya girer (pending), yeni varsayılan hesap
     * bağlanır, finans ekibi haberdar edilir; pazaryeri açıksa aktarım hemen denenir.
     */
    public function retryAfterFix(Payout $payout, User $driverUser): void
    {
        if ($payout->user_id !== $driverUser->id) {
            throw new RuntimeException('Bu ödeme kaydı size ait değil.');
        }
        if ($payout->status !== 'failed') {
            throw new RuntimeException('Yalnız düzeltme bekleyen ödemeler yeniden gönderilir.');
        }
        $account = $driverUser->defaultBankAccount;
        if (! $account) {
            throw new RuntimeException('Önce kayıtlı bir IBAN ekleyin.');
        }
        $payout->update(['status' => 'pending', 'bank_account_id' => $account->id, 'failure_reason' => null]);
        ActivityLog::record('payout.retry_requested', "Hakediş #{$payout->id}: şoför IBAN'ı güncelledi, yeniden gönderim istedi", $driverUser->id, $payout);
        $this->notifications->notifyAdmins('manage payouts', 'Hakediş yeniden gönderim bekliyor',
            ["Hakediş #{$payout->id} için şoför IBAN bilgisini güncelledi (…{$account->iban_last4}). Kayıt yeniden \"bekliyor\" durumuna alındı."],
            route('admin.finance'), 'Finans ekranı', 'admin');
        $this->releaseViaGateway($payout->fresh());
    }

    /** Şoför ödemeleri özeti: bekleyen, ödenmiş ve düzeltme bekleyen tutarlar. */
    public function walletSummary(User $driverUser): array
    {
        $base = Payout::query()->where('user_id', $driverUser->id);

        return [
            'pending' => (float) (clone $base)->whereIn('status', ['pending', 'processing'])->sum('net_amount'),
            'paid' => (float) (clone $base)->where('status', 'paid')->sum('net_amount'),
            'failed' => (float) (clone $base)->where('status', 'failed')->sum('net_amount'),
            'commission' => (float) (clone $base)->whereIn('status', ['pending', 'processing', 'paid'])->sum('commission_amount'),
            'in_escrow' => (float) Load::query()->where('driver_profile_id', $driverUser->driverProfile?->id ?? 0)
                ->whereIn('escrow_status', [Load::ESCROW_PAID, Load::ESCROW_ON_HOLD])->sum('price'),
        ];
    }
}
