<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InsuranceClaim extends Model
{
    use HasFactory;

    protected $fillable = [
        'insurance_policy_id',
        'dispute_id',
        'provider_claim_id',
        'status',
        'claimed_amount',
        'approved_amount',
        'description',
        'metadata',
        'resolved_at',
    ];

    protected $casts = [
        'claimed_amount'=>'decimal:4',
        'approved_amount'=>'decimal:4',
        'metadata'=>'array',
        'resolved_at'=>'datetime',
    ];
}
