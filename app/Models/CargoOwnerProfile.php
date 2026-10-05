<?php

namespace App\Models;

use App\Support\Settings;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CargoOwnerProfile extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'is_staff_view',
        'type',
        'company_title',
        'tax_office',
        'tax_no',
        'tc_no',
        'birth_year',
        'nvi_verified',
        'nvi_checked_at',
        'nvi_message',
        'gib_verified',
        'gib_verified_at',
        'gib_verified_by',
        'kyc_status',
        'kyc_notes',
        'kyc_submitted_at',
        'kyc_verified_at',
        'kyc_verified_by',
    ];

    protected $casts = [
        'is_staff_view' => 'boolean',
        'nvi_verified' => 'boolean',
        'gib_verified' => 'boolean',
        'nvi_checked_at' => 'datetime',
        'gib_verified_at' => 'datetime',
        'kyc_submitted_at' => 'datetime',
        'kyc_verified_at' => 'datetime',
    ];

    public function loads(): HasMany
    {
        return $this->hasMany(Load::class);
    }

    public function kycDocuments(): HasMany
    {
        return $this->hasMany(KycDocument::class, 'user_id', 'user_id');
    }

    public function displayName(): string
    {
        return $this->type === 'corporate' && $this->company_title ? $this->company_title : ($this->user?->full_name ?? '');
    }

    /**
     * Teklif öncesi herkese görünen ad: kurumsalda unvan, bireyselde "Ad S." (tam ad yalnız teklif kabulünden sonra;
     * KVKK, 2026-10-05 canlıya geçiş planı E6).
     */
    public function publicName(): string
    {
        if ($this->type === 'corporate' && $this->company_title) {
            return $this->company_title;
        }
        $first = trim((string) $this->user?->first_name);
        $last = trim((string) $this->user?->last_name);

        return trim($first.' '.($last !== '' ? mb_substr($last, 0, 1).'.' : ''));
    }

    /**
     * Yük sahibi doğrulaması (karar 3, 2026-10-05): bireysel → TC + ad soyad + doğum yılı NVİ'de eşleşti; kurumsal → VKN/unvan
     * yönetici tarafından teyit edildi (ileride e-fatura mükellef sorgusuyla kendiliğinden). Belge fotoğrafı şart değildir.
     */
    public function isVerified(): bool
    {
        return $this->type === 'corporate' ? (bool) $this->gib_verified : (bool) $this->nvi_verified;
    }

    public function verificationLabel(): string
    {
        if ($this->isVerified()) {
            return $this->type === 'corporate' ? 'Kurumsal · doğrulandı' : 'Kimliği doğrulandı';
        }

        return $this->type === 'corporate' ? 'Şirket doğrulaması bekleniyor' : 'Kimlik doğrulanmadı';
    }

    /** Panel ayarı: doğrulanmamış yük sahibi teklif kabul edemez, aktif ilan sayısı sınırlı. */
    public static function verificationRequired(): bool
    {
        return Settings::bool('cargo_owner_verification_required');
    }

    /** Teklif kabulünü engelleyen gerekçe (yoksa null). */
    public function verificationBlocker(): ?string
    {
        if (! self::verificationRequired() || $this->isVerified()) {
            return null;
        }

        return $this->type === 'corporate'
            ? 'Teklif kabul etmek için şirket doğrulaması gerekir; vergi bilgileriniz ekibimizce teyit edilince (genelde aynı gün) açılır. Profil → Doğrulama.'
            : 'Teklif kabul etmek için kimlik doğrulaması gerekir. Profil → Doğrulama bölümünden T.C. kimlik numaranızı ve doğum yılınızı kontrol edip yeniden doğrulayın.';
    }

    /**
     * Üst Kullanıcı İlişkisi
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
