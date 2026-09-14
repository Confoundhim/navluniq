<?php

namespace App\Services;

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Çift taraflı kayıt: her işlemde borç ve alacak toplamları para birimi bazında eşit olmalıdır.
 */
class LedgerService
{
    public const ACCOUNTS = [
        'escrow_cash' => ['Havuz hesabı (PayTR)', 'asset'],
        'escrow_liability' => ['Yük sahiplerine borç (havuz)', 'liability'],
        'driver_payable' => ['Şoförlere ödenecek hakediş', 'liability'],
        'commission_revenue' => ['Komisyon geliri', 'revenue'],
        'refunds' => ['İadeler', 'expense'],
    ];

    public function post(string $type, string $description, array $entries, ?string $referenceType = null, ?int $referenceId = null): LedgerTransaction
    {
        $totals = [];
        foreach ($entries as $entry) {
            $amount = round((float) ($entry['amount'] ?? 0), 4);
            $direction = $entry['direction'] ?? '';
            $currency = $entry['currency'] ?? 'TRY';
            if ($amount <= 0 || ! in_array($direction, ['debit', 'credit'], true)) {
                throw new InvalidArgumentException('Geçersiz muhasebe satırı.');
            }
            $totals[$currency][$direction] = ($totals[$currency][$direction] ?? 0) + $amount;
        }

        foreach ($totals as $currency => $sides) {
            if (abs(($sides['debit'] ?? 0) - ($sides['credit'] ?? 0)) > 0.0001) {
                throw new InvalidArgumentException("Muhasebe işlemi {$currency} için dengeli değil.");
            }
        }

        return DB::transaction(function () use ($type, $description, $entries, $referenceType, $referenceId): LedgerTransaction {
            $tx = LedgerTransaction::create([
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'transaction_type' => $type,
                'description' => $description,
                'occurred_at' => now(),
                'posted_at' => now(),
                'status' => 'posted',
            ]);

            foreach ($entries as $entry) {
                $currency = $entry['currency'] ?? 'TRY';
                $account = $this->account($entry['account_code'], $currency);
                LedgerEntry::create([
                    'ledger_transaction_id' => $tx->id,
                    'ledger_account_id' => $account->id,
                    'direction' => $entry['direction'],
                    'amount' => round((float) $entry['amount'], 4),
                    'currency' => $currency,
                ]);
            }

            return $tx;
        }, 3);
    }

    public function account(string $code, string $currency = 'TRY'): LedgerAccount
    {
        [$name, $type] = self::ACCOUNTS[$code] ?? [$code, 'other'];

        return LedgerAccount::firstOrCreate(
            ['code' => $code, 'currency' => $currency],
            ['name' => $name, 'account_type' => $type, 'is_active' => true]
        );
    }

    public function balance(string $code, string $currency = 'TRY'): float
    {
        $account = LedgerAccount::query()->where('code', $code)->where('currency', $currency)->first();
        if (! $account) {
            return 0.0;
        }

        $debit = (float) LedgerEntry::where('ledger_account_id', $account->id)->where('direction', 'debit')->sum('amount');
        $credit = (float) LedgerEntry::where('ledger_account_id', $account->id)->where('direction', 'credit')->sum('amount');

        return round($debit - $credit, 2);
    }
}
