<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverLocation extends Model
{
    use HasFactory;

    // MySQL Spatial işlemleri için timestamps kullanımını kapatıyoruz (sadece recorded_at kullanacağız)
    public $timestamps = false;

    protected $fillable = [
        'driver_profile_id',
        'coordinates',
        'speed',
        'heading',
        'recorded_at',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'speed' => 'decimal:2',
        'heading' => 'decimal:2',
    ];

    /**
     * Şoför Profil İlişkisi
     */
    public function driverProfile(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class);
    }

    /**
     * Coğrafi Konumu (POINT) Enlem ve Boylam dizisine dönüştüren yardımcı metod.
     */
    public function getLatLngAttribute(): array
    {
        return [
            'lat' => $this->attributes['lat'] ?? 0.0,
            'lng' => $this->attributes['lng'] ?? 0.0
        ];
    }
}
