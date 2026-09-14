<?php

namespace App\Models;

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
        'type',
        'company_title',
        'tax_office',
        'tax_no',
        'tc_no',
        'birth_year',
        'nvi_verified',
        'gib_verified',
        'kyc_status',
        'kyc_notes',
        'kyc_submitted_at',
        'kyc_verified_at',
        'kyc_verified_by',
    ];

    protected $casts = [
        'nvi_verified' => 'boolean',
        'gib_verified' => 'boolean',
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
     * Üst Kullanıcı İlişkisi
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
