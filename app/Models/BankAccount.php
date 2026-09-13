<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BankAccount extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'provider_recipient_id',
        'iban_last4',
        'encrypted_iban',
        'account_holder',
        'is_verified',
        'is_default',
    ];

    protected $casts = [
        'is_verified'=>'boolean',
        'is_default'=>'boolean',
    ];
}
