<?php

namespace App\Models;

use App\Support\Settings;
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
        'is_staff_view',
        'premium_until',
        'trial_started_at',
        'payout_provider_ref',
        'payout_provider',
        'legal_type',
        'identity_number',
        'tax_number',
        'tax_office',
        'withdrawals_after_payment',
        'bank_account_changed_at',
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
        'kyc_submitted_at',
        'kyc_verified_at',
        'kyc_verified_by',
        'preferences',
    ];

    protected $casts = [
        'is_staff_view' => 'boolean',
        'premium_until' => 'datetime',
        'trial_started_at' => 'datetime',
        'ocr_data' => 'array',
        'preferences' => 'array',
        'kyc_submitted_at' => 'datetime',
        'kyc_verified_at' => 'datetime',
        'bank_account_changed_at' => 'datetime',
        'withdrawals_after_payment' => 'integer',
    ];

    public const LEGAL_INDIVIDUAL = 'individual';

    public const LEGAL_COMPANY = 'company';

    /** Pazaryeri alt üye işyeri kaydı için gereken kimlik: bireyselde TC, şirkette VKN. */
    public function payoutIdentityNumber(): ?string
    {
        $value = $this->legal_type === self::LEGAL_COMPANY ? $this->tax_number : $this->identity_number;

        return filled($value) ? (string) $value : null;
    }

    public function hasPayoutIdentity(): bool
    {
        return $this->payoutIdentityNumber() !== null;
    }

    /** Ekranda gösterilen maskeli kimlik: 123******89 / 12*****890. */
    public function maskedPayoutIdentity(): ?string
    {
        $value = $this->payoutIdentityNumber();
        if ($value === null) {
            return null;
        }

        return substr($value, 0, 2).str_repeat('*', max(0, strlen($value) - 4)).substr($value, -2);
    }

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
    public function filterPresets(): HasMany
    {
        return $this->hasMany(DriverFilterPreset::class)->orderByDesc('is_default')->orderBy('name');
    }

    /** Kaydedilen (yıldızlanan) ilanlar */
    public function savedLoads(): HasMany
    {
        return $this->hasMany(DriverSavedLoad::class);
    }

    /** "Bu işi aldım" sefer kayıtları */
    public function trips(): HasMany
    {
        return $this->hasMany(DriverTrip::class);
    }

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

    public function isKycApproved(): bool
    {
        return $this->kyc_status === 'approved';
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function kycDocuments(): HasMany
    {
        return $this->hasMany(KycDocument::class, 'user_id', 'user_id');
    }

    public function commissionRate(): float
    {
        // Komisyon oranı üyelik türünden bağımsızdır; premium yalnız erken erişim sağlar.
        return Settings::float('commission_standard_driver');
    }
}
