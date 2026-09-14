<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Load;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PayoutService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly NotificationService $notifications,
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

        return $payout;
    }

    /** Finans ekibi banka transferini tamamladığında çağrılır. */
    public function markPaid(Payout $payout, User $admin, string $reference): void
    {
        DB::transaction(function () use ($payout, $admin, $reference): void {
            $locked = Payout::query()->lockForUpdate()->findOrFail($payout->id);
            if (! in_array($locked->status, ['pending', 'processing', 'failed'], true)) {
                throw new RuntimeException('Bu hakediş zaten sonuçlandırılmış.');
            }

            $bankAccount = $locked->bankAccount ?? $locked->user?->defaultBankAccount;
            $locked->update([
                'status' => 'paid',
                'paid_at' => now(),
                'reference_no' => mb_substr(trim($reference), 0, 120),
                'bank_account_id' => $bankAccount?->id ?? $locked->bank_account_id,
            ]);

            Load::query()->whereKey($locked->load_id)->update(['escrow_status' => Load::ESCROW_RELEASED]);

            ActivityLog::record('payout.paid', "Hakediş #{$locked->id} ödendi ({$reference})", $admin->id, $locked);
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
            $this->notifications->notify($driverUser, 'Hakedişiniz ödendi',
                [number_format((float) $payout->net_amount, 2, ',', '.').' ₺ tutarındaki hakedişiniz banka hesabınıza aktarıldı. Referans: '.$reference],
                route('driver.wallet.index'), 'Cüzdanı görüntüle');
        }
    }

    public function markFailed(Payout $payout, User $admin, string $reason): void
    {
        $payout->update(['status' => 'failed', 'reference_no' => mb_substr(trim($reason), 0, 120)]);
        ActivityLog::record('payout.failed', "Hakediş #{$payout->id} başarısız: {$reason}", $admin->id, $payout);
    }

    /** Şoför cüzdanı: bekleyen ve ödenmiş hakedişler. */
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
