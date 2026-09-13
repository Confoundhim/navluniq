<?php

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

    /** Türk plakası: il kodu, 1-3 harf, 2-4 rakam (boşluksuz, büyük harf). */
    public const PLATE_RULE = 'regex:/^(0[1-9]|[1-7][0-9]|8[01])[A-Z]{1,3}\d{2,4}$/';

    public static function normalizePlate(?string $raw): string
    {
        return mb_strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $raw));
    }

    /** Şoför kaydı ve ilan formunda ortak kullanılan araç türleri. */
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
            'tir' => 'TIR',
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
