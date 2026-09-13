<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DisputeAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'dispute_id',
        'beneficiary_type',
        'beneficiary_user_id',
        'amount',
        'currency',
        'status',
        'ledger_transaction_id',
    ];

    protected $casts = [
        'amount'=>'decimal:4',
    ];
}
