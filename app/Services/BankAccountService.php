<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class BankAccountService
{
    public function save(User $user, string $iban, string $holder, bool $default = true): BankAccount
    {
        $normalized = self::normalize($iban);

        if (! self::isValidTurkishIban($normalized)) {
            throw new InvalidArgumentException('Geçerli bir Türkiye IBAN numarası girin (TR ile başlayan 26 karakter).');
        }

        return DB::transaction(function () use ($user, $normalized, $holder, $default): BankAccount {
            if ($default) {
                BankAccount::query()->where('user_id', $user->id)->update(['is_default' => false]);
            }

            return BankAccount::updateOrCreate(
                ['user_id' => $user->id, 'iban_hash' => hash('sha256', $normalized)],
                [
                    'encrypted_iban' => Crypt::encryptString($normalized),
                    'iban_last4' => substr($normalized, -4),
                    'account_holder' => trim($holder),
                    'is_default' => $default,
                    'is_verified' => false,
                ]
            );
        });
    }

    public function decrypt(BankAccount $account): string
    {
        return Crypt::decryptString($account->encrypted_iban);
    }

    public static function normalize(string $iban): string
    {
        return strtoupper(preg_replace('/\s+/', '', $iban));
    }

    public static function isValidTurkishIban(string $iban): bool
    {
        if (! preg_match('/^TR\d{24}$/', $iban)) {
            return false;
        }

        $numeric = substr($iban, 4).'2927'.substr($iban, 2, 2);
        $remainder = 0;
        foreach (str_split($numeric) as $digit) {
            $remainder = (($remainder * 10) + (int) $digit) % 97;
        }

        return $remainder === 1;
    }
}
