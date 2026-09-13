<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InsurancePolicy extends Model
{
    use HasFactory;

    protected $fillable = [
        'insurance_quote_id',
        'payment_order_id',
        'provider_policy_id',
        'policy_number',
        'status',
        'starts_at',
        'ends_at',
        'document_disk',
        'document_path',
    ];

    protected $casts = [
        'starts_at'=>'datetime',
        'ends_at'=>'datetime',
    ];
}
