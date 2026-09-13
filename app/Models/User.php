<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * User Model Yapılandırması
 *
 * @mixin \Spatie\Permission\Traits\HasRoles
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes, HasRoles;

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
    ];

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
        'is_active' => 'boolean',
    ];

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

    /**
     * ŞART: Aynı telefon numarasına kayıtlı bir Şoför hesabı/profili var mı kontrol eder.
     */
    public function hasDriverAccountWithSamePhone(): bool
    {
        // 1. Kendi kullanıcısına bağlı DriverProfile var mı?
        if ($this->hasDriverProfile()) {
            return true;
        }

        // 2. VEYA aynı telefon numarasına sahip başka bir şoför kullanıcısı var mı?
        if (!empty($this->phone)) {
            return self::where('phone', $this->phone)
                ->where('id', '!=', $this->id)
                ->where(function ($query) {
                    $query->where('current_role', 'driver')
                          ->orWhereHas('driverProfile');
                })
                ->exists();
        }

        return false;
    }

    /**
     * ŞART: Aynı telefon numarasına kayıtlı bir Yük Sahibi hesabı/profili var mı kontrol eder.
     */
    public function hasCargoOwnerAccountWithSamePhone(): bool
    {
        if ($this->hasCargoOwnerProfile()) {
            return true;
        }

        if (!empty($this->phone)) {
            return self::where('phone', $this->phone)
                ->where('id', '!=', $this->id)
                ->where(function ($query) {
                    $query->where('current_role', 'cargo_owner')
                          ->orWhereHas('cargoOwnerProfile');
                })
                ->exists();
        }

        return false;
    }

    /**
     * Rol Değiştirici: Kullanıcının aktif panel arayüzünü günceller.
     */
    public function switchRole(string $role): bool
    {
        if (!in_array($role, ['cargo_owner', 'driver'])) {
            return false;
        }

        $this->update(['current_role' => $role]);
        return true;
    }
}
