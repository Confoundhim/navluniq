<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CouponRedemption extends Model
{
    use HasFactory;

    protected $fillable = [
        'coupon_id',
        'user_id',
        'payment_order_id',
        'discount_amount',
        'redeemed_at',
    ];

    protected $casts = [
        'discount_amount'=>'decimal:4',
        'redeemed_at'=>'datetime',
    ];
}
