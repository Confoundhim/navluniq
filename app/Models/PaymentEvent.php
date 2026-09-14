<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_order_id',
        'provider_event_id',
        'event_type',
        'status',
        'payload_hash',
        'payload_encrypted',
        'processed_at',
        'failure_message',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
    ];

    public function paymentOrder(): BelongsTo
    {
        return $this->belongsTo(PaymentOrder::class);
    }
}
