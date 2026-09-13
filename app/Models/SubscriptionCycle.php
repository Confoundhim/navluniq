<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionCycle extends Model
{
    use HasFactory;

    protected $fillable = [
        'subscription_id',
        'payment_order_id',
        'period_start',
        'period_end',
        'amount',
        'currency',
        'status',
        'paid_at',
    ];

    protected $casts = [
        'period_start'=>'datetime',
        'period_end'=>'datetime',
        'amount'=>'decimal:4',
        'paid_at'=>'datetime',
    ];
}
