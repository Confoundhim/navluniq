<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BankAccount extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'provider_recipient_id',
        'encrypted_iban',
        'iban_hash',
        'iban_last4',
        'account_holder',
        'is_verified',
        'is_default',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function maskedIban(): string
    {
        return 'TR** **** **** **** **** **'.$this->iban_last4;
    }
}
