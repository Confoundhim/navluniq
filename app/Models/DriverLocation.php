<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverLocation extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'driver_profile_id',
        'shipment_id',
        'latitude',
        'longitude',
        'speed',
        'heading',
        'accuracy_meters',
        'recorded_at',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'speed' => 'decimal:2',
        'heading' => 'decimal:2',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    /**
     * Şoför Profil İlişkisi
     */
    public function driverProfile(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function getLatLngAttribute(): ?array
    {
        return $this->latitude !== null && $this->longitude !== null ? [(float) $this->latitude, (float) $this->longitude] : null;
    }
}
