<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\HasPublicId;

class PaymentOrder extends Model
{
    use HasFactory, SoftDeletes, HasPublicId;

    protected $fillable = [
        'public_id',
        'load_id',
        'user_id',
        'purpose',
        'provider',
        'merchant_oid',
        'provider_reference',
        'amount',
        'currency',
        'service_fee_amount',
        'insurance_amount',
        'status',
        'request_snapshot',
        'authorized_at',
        'paid_at',
        'failed_at',
        'refunded_at',
    ];

    protected $casts = [
        'amount'=>'decimal:4',
        'service_fee_amount'=>'decimal:4',
        'insurance_amount'=>'decimal:4',
        'request_snapshot'=>'array',
        'authorized_at'=>'datetime',
        'paid_at'=>'datetime',
        'failed_at'=>'datetime',
        'refunded_at'=>'datetime',
    ];
}
