<?php

namespace App\Services;

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LedgerService
{
    public function post(string $type, string $description, array $entries, ?string $referenceType=null, ?int $referenceId=null): LedgerTransaction
    {
        $debits=0.0; $credits=0.0;
        foreach ($entries as $entry) {
            $amount=round((float)($entry['amount']??0),4); $direction=$entry['direction']??'';
            if ($amount<=0 || !in_array($direction,['debit','credit'],true)) throw new InvalidArgumentException('Geçersiz muhasebe satırı.');
            $direction==='debit' ? $debits+=$amount : $credits+=$amount;
        }
        if (abs($debits-$credits)>0.0001) throw new InvalidArgumentException('Muhasebe işlemi dengeli değil.');
        return DB::transaction(function () use ($type,$description,$entries,$referenceType,$referenceId): LedgerTransaction {
            $tx=LedgerTransaction::create(['reference_type'=>$referenceType,'reference_id'=>$referenceId,'transaction_type'=>$type,'description'=>$description,'occurred_at'=>now(),'posted_at'=>now(),'status'=>'posted']);
            foreach ($entries as $entry) {
                $account=LedgerAccount::query()->where('code',$entry['account_code'])->where('currency',$entry['currency']??'TRY')->lockForUpdate()->firstOrFail();
                LedgerEntry::create(['ledger_transaction_id'=>$tx->id,'ledger_account_id'=>$account->id,'direction'=>$entry['direction'],'amount'=>$entry['amount'],'currency'=>$entry['currency']??'TRY']);
            }
            return $tx;
        },3);
    }
}
