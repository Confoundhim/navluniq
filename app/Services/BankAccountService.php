<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

class BankAccountService
{
    public function save(User $user, string $iban, string $holder, bool $default=true): BankAccount
    {
        $normalized=strtoupper(preg_replace('/\s+/', '', $iban));
        if (!$this->isValidTurkishIban($normalized)) throw new InvalidArgumentException('Geçerli bir Türkiye IBAN numarası girin.');
        if ($default) BankAccount::query()->where('user_id',$user->id)->update(['is_default'=>false]);
        return BankAccount::updateOrCreate(['user_id'=>$user->id,'iban_last4'=>substr($normalized,-4)],['encrypted_iban'=>Crypt::encryptString($normalized),'account_holder'=>trim($holder),'is_default'=>$default,'is_verified'=>false]);
    }
    public function decrypt(BankAccount $account): string { return Crypt::decryptString($account->encrypted_iban); }
    private function isValidTurkishIban(string $iban): bool
    {
        if (!preg_match('/^TR\d{24}$/',$iban)) return false;
        $numeric=substr($iban,4).'2927'.substr($iban,2,2); $remainder=0;
        foreach (str_split($numeric) as $digit) $remainder=(($remainder*10)+(int)$digit)%97;
        return $remainder===1;
    }
}
