<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * User Model Yapılandırması
 *
 * @mixin HasRoles
 */
class User extends Authenticatable
{
    use HasFactory, HasPublicId, HasRoles, Notifiable, SoftDeletes;

    /**
     * Toplu atama yapılabilecek alanlar.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'password',
        'current_role',
        'otp_code',
        'otp_expires_at',
        'is_active',
        'banned_at',
        'ban_reason',
        'email_verified_at',
        'phone_verified_at',
        'last_login_at',
    ];

    /** Yönetim paneline girebilen roller. Personel modülü yeni rol eklerse bu listeye de eklenmelidir. */
    public const ADMIN_PANEL_ROLES = ['super_admin', 'kyc_validator', 'financial_officer', 'support_agent'];

    /**
     * Gizlenmesi gereken alanlar.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'otp_code',
    ];

    /**
     * Cast edilecek veri tipleri.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'password' => 'hashed',
        'otp_expires_at' => 'datetime',
        'banned_at' => 'datetime',
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function isAdminPanelUser(): bool
    {
        return $this->current_role === 'admin'
            && $this->is_active
            && $this->banned_at === null
            && $this->hasAnyRole(self::ADMIN_PANEL_ROLES);
    }

    /**
     * Kullanıcının tam adı ve soyadı birleşimi.
     */
    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * Geriye dönük uyumluluk için name çağrısını full_name'e bağlar.
     */
    public function getNameAttribute(): string
    {
        return $this->full_name;
    }

    /**
     * Yük Sahibi Profil İlişkisi (1-to-1)
     */
    public function cargoOwnerProfile(): HasOne
    {
        return $this->hasOne(CargoOwnerProfile::class);
    }

    /**
     * Şoför Profil İlişkisi (1-to-1)
     */
    public function driverProfile(): HasOne
    {
        return $this->hasOne(DriverProfile::class);
    }

    /**
     * Kullanıcının doğrudan şoför profili var mı?
     */
    public function hasDriverProfile(): bool
    {
        return $this->driverProfile()->exists();
    }

    /**
     * Kullanıcının doğrudan yük sahibi profili var mı?
     */
    public function hasCargoOwnerProfile(): bool
    {
        return $this->cargoOwnerProfile()->exists();
    }

    public function consents(): HasMany
    {
        return $this->hasMany(UserConsent::class);
    }

    public function kycDocuments(): HasMany
    {
        return $this->hasMany(KycDocument::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }

    public function defaultBankAccount(): HasOne
    {
        return $this->hasOne(BankAccount::class)->where('is_default', true);
    }

    public function savedAddresses(): HasMany
    {
        return $this->hasMany(SavedAddress::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }

    public function reviewsReceived(): HasMany
    {
        return $this->hasMany(Review::class, 'reviewee_id');
    }

    public function averageRating(): ?float
    {
        $avg = $this->reviewsReceived()->avg('rating');

        return $avg === null ? null : round((float) $avg, 1);
    }

    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    /** Kullanıcının aktif panel rolünü değiştirir. */
    public function switchRole(string $role): bool
    {
        if (! in_array($role, ['cargo_owner', 'driver'], true)) {
            return false;
        }

        $this->update(['current_role' => $role]);

        return true;
    }
}
