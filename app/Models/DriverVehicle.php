<?php
// app/Models/DriverVehicle.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DriverVehicle extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'driver_profile_id',
        'plate',
        'brand',
        'model',
        'vehicle_type',
        'ruhsat_path',
        'vehicle_photo_path',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Sistemdeki tüm araç tiplerini dinamik olarak döndürür.
     * Hem şoför kaydında hem de yük sahibi ilan sihirbazında bu metot çağrılmalıdır.
     */
    public static function getVehicleTypes(): array
    {
        return [
            'otomobil' => 'Otomobil',
            'minivan' => 'Minivan',
            'orta_panelvan' => 'Orta Panelvan',
            'uzun_panelvan' => 'Uzun Panelvan',
            'kamyonet' => 'Kamyonet',
            '6_teker_kamyon' => '6 Teker Kamyon',
            '8_teker_kamyon' => '8 Teker Kamyon',
            '10_teker_kamyon' => '10 Teker Kamyon',
            'kirkayak' => 'Kırkayak',
            'tir' => 'TIR'
        ];
    }

    /**
     * Şoför Profil İlişkisi
     */
    public function driverProfile(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class);
    }
}
