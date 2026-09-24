<?php

namespace App\Models;

use App\Support\VehicleTypes;
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
        'body_type',
        'trailer_length',
        'has_lift',
        'ruhsat_path',
        'vehicle_photo_path',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'has_lift' => 'boolean',
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
        return VehicleTypes::labels();
    }

    /**
     * Şoför Profil İlişkisi
     */
    public function driverProfile(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class);
    }
}
