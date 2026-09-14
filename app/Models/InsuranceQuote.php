<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InsuranceQuote extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'load_id',
        'user_id',
        'provider',
        'provider_quote_id',
        'insured_value',
        'premium_amount',
        'currency',
        'status',
        'coverage',
        'expires_at',
    ];

    protected $casts = [
        'insured_value' => 'decimal:4',
        'premium_amount' => 'decimal:4',
        'coverage' => 'array',
        'expires_at' => 'datetime',
    ];
}
