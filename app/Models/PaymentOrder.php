<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentOrder extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'load_id',
        'user_id',
        'purpose',
        'provider',
        'merchant_oid',
        'provider_reference',
        'amount',
        'currency',
        'service_fee_amount',
        'commission_rate',
        'commission_amount',
        'driver_net_amount',
        'insurance_amount',
        'status',
        'request_snapshot',
        'authorized_at',
        'paid_at',
        'failed_at',
        'refunded_at',
        'failure_message',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'service_fee_amount' => 'decimal:4',
        'commission_rate' => 'decimal:3',
        'commission_amount' => 'decimal:4',
        'driver_net_amount' => 'decimal:4',
        'insurance_amount' => 'decimal:4',
        'request_snapshot' => 'array',
        'authorized_at' => 'datetime',
        'paid_at' => 'datetime',
        'failed_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    public function cargoLoad(): BelongsTo
    {
        return $this->belongsTo(Load::class, 'load_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }
}
