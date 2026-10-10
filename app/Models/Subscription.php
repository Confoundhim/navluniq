<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'plan_code',
        'provider',
        'provider_subscription_id',
        'status',
        'amount',
        'currency',
        'interval',
        'auto_renew',
        'renew_months',
        'stored_card_id',
        'renewal_failures',
        'last_renewal_error',
        'next_renewal_attempt_at',
        'trial_ends_at',
        'current_period_starts_at',
        'current_period_ends_at',
        'cancelled_at',
        'ended_at',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cycles(): HasMany
    {
        return $this->hasMany(SubscriptionCycle::class);
    }

    public function storedCard(): BelongsTo
    {
        return $this->belongsTo(StoredCard::class);
    }

    protected $casts = [
        'amount' => 'decimal:4',
        'auto_renew' => 'boolean',
        'renew_months' => 'integer',
        'renewal_failures' => 'integer',
        'next_renewal_attempt_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'current_period_starts_at' => 'datetime',
        'current_period_ends_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'ended_at' => 'datetime',
    ];
}
