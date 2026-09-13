<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class DriverProfile extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'premium_until',
        'avatar_path',
        'driver_license_path',
        'src_document_path',
        'psychotechnic_path',
        'k_document_path',
        'liability_insurance_path',
        'selfie_with_id_path',
        'ocr_data',
        'kyc_status',
        'kyc_notes',
    ];

    protected $casts = [
        'premium_until' => 'datetime',
        'ocr_data' => 'array',
    ];

    /**
     * Üst Kullanıcı İlişkisi
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Şoförün Sahip Olduğu Araçlar (1-to-Many)
     */
    public function vehicles(): HasMany
    {
        return $this->hasMany(DriverVehicle::class);
    }

    /**
     * Şoförün O An Aktif Kullandığı Araç (1-to-1 Helper)
     */
    public function activeVehicle(): HasOne
    {
        return $this->hasOne(DriverVehicle::class)->where('is_active', true);
    }

    /**
     * Şoförün Konum Kayıtları (1-to-Many)
     */
    public function locations(): HasMany
    {
        return $this->hasMany(DriverLocation::class);
    }

    /**
     * Şoförün En Son Bildirilen Canlı Konumu (1-to-1 Helper)
     */
    public function latestLocation(): HasOne
    {
        return $this->hasOne(DriverLocation::class)->latestOfMany('id');
    }

    /**
     * Şoförün Premium Aboneliği Aktif Mi?
     */
    public function isPremium(): bool
    {
        return $this->premium_until && $this->premium_until->isFuture();
    }
}
