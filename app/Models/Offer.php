<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Offer extends Model
{
    public const STATUS_LABELS = [
        'pending' => 'Değerlendiriliyor',
        'accepted' => 'Kabul edildi',
        'rejected' => 'Reddedildi',
        'withdrawn' => 'Geri çekildi',
        'expired' => 'Süresi doldu',
    ];

    use HasFactory, HasPublicId, SoftDeletes;

    protected $fillable = [
        'load_id',
        'driver_profile_id',
        'amount',
        'currency',
        'message',
        'estimated_days',
        'status',
        'expires_at',
        'responded_at',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'expires_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    public function cargoLoad(): BelongsTo
    {
        return $this->belongsTo(Load::class, 'load_id');
    }

    public function driverProfile(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class);
    }
}
