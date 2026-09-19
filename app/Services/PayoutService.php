<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\DriverProfile;
use App\Models\Invoice;
use App\Models\Load;
use App\Models\Payout;
use App\Models\User;
use App\Payments\GatewayManager;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PayoutService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly NotificationService $notifications,
        private readonly GatewayManager $gateways,
    ) {}

    /** Onaylanmış teslimat için hakediş kaydı (ilan başına tek). */
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

        $total = round((float) $load->price, 2);
        $rate = $driver->commissionRate();
        $commission = round($total * $rate / 100, 2);
        $net = round($total - $commission, 2);
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
     * Etkin ödeme kuruluşu alt üye işyeri aktarımını destekliyorsa ve şoför kayıtlıysa hakedişi aktarır.
     * Başarılıysa payout otomatik "paid" olur; aksi halde manuel süreçte kalır.
     */
    /**
     * Pazaryeri modelinde şoförü ödeme kuruluşuna alt üye işyeri olarak kaydeder (bir kez).
     * Kayıtlı IBAN yoksa ya da kuruluş reddederse false döner; hakediş manuel sürece düşer.
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
        if (! $user || ! $account) {
            return false;
        }

        try {
            $ref = $gateway->registerSubMerchant([
                'external_id' => 'DRV-'.$driver->id,
                'name' => $user->first_name,
                'surname' => $user->last_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'iban' => app(BankAccountService::class)->decrypt($account),
                'identity' => '',
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

    public function releaseViaGateway(Payout $payout): bool
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

        $payout->update(['status' => 'processing', 'channel' => 'gateway']);
        $result = $gateway->transferToSubMerchant($payout, (string) $driver->payout_provider_ref);
        if (! $result->succeeded) {
            $payout->update(['status' => 'pending', 'failure_reason' => mb_substr((string) $result->failureMessage, 0, 500)]);
            Log::warning('Ödeme kuruluşu aktarımı başarısız; manuel sürece düştü.', ['payout' => $payout->id, 'error' => $result->failureMessage]);
            $this->notifications->notifyAdmins('manage payouts', 'Hakediş aktarımı başarısız',
                ["Hakediş #{$payout->id} ödeme kuruluşu üzerinden aktarılamadı: ".($result->failureMessage ?: 'sebep belirtilmedi'), 'Kayıt manuel sürece düştü; Finans ekranından banka transferiyle tamamlayın.'],
                route('admin.finance'), 'Finans ekranı', 'admin');

            return false;
        }

        $this->settle($payout, 'gateway', (string) ($result->reference ?: $gateway->id().':'.now()->timestamp), null);

        return true;
    }

    /** Finans ekibi banka transferini tamamladığında çağrılır. */
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
        $payout->update(['status' => 'failed', 'reference_no' => mb_substr(trim($reason), 0, 120)]);
        ActivityLog::record('payout.failed', "Hakediş #{$payout->id} başarısız: {$reason}", $admin->id, $payout);

        if ($driverUser = $payout->user) {
            $this->notifications->notify($driverUser, 'Ödemeniz yapılamadı',
                ['Ödemeniz banka tarafından tamamlanamadı: '.mb_substr(trim($reason), 0, 200), 'Lütfen Ödemelerim sayfasından IBAN ve hesap sahibi bilgilerinizi kontrol edin; finans ekibimiz düzeltme sonrası ödemeyi yeniden gönderecek.'],
                route('driver.wallet.index'), 'IBAN bilgilerimi kontrol et', 'payout');
        }
    }

    /** Şoför ödemeleri özeti: bekleyen ve ödenmiş tutarlar. */
    public function walletSummary(User $driverUser): array
    {
        $base = Payout::query()->where('user_id', $driverUser->id);

        return [
            'pending' => (float) (clone $base)->whereIn('status', ['pending', 'processing'])->sum('net_amount'),
            'paid' => (float) (clone $base)->where('status', 'paid')->sum('net_amount'),
            'commission' => (float) (clone $base)->whereIn('status', ['pending', 'processing', 'paid'])->sum('commission_amount'),
            'in_escrow' => (float) Load::query()->where('driver_profile_id', $driverUser->driverProfile?->id ?? 0)
                ->whereIn('escrow_status', [Load::ESCROW_PAID, Load::ESCROW_ON_HOLD])->sum('price'),
        ];
    }
}
