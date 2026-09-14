<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class KycDocument extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'document_type',
        'storage_disk',
        'storage_path',
        'sha256',
        'status',
        'extracted_data',
        'expires_at',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected $casts = [
        'extracted_data' => 'array',
        'expires_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public const DRIVER_TYPES = [
        'driver_license' => 'Sürücü belgesi (ön yüz)',
        'src_document' => 'SRC belgesi',
        'psychotechnic' => 'Psikoteknik raporu',
        'selfie_with_id' => 'Kimlikle çekilmiş fotoğraf',
        'vehicle_registration' => 'Araç ruhsatı',
        'k_document' => 'K yetki belgesi (varsa)',
        'liability_insurance' => 'Taşıyıcı sorumluluk sigortası (varsa)',
    ];

    public const DRIVER_REQUIRED = ['driver_license', 'src_document', 'psychotechnic', 'selfie_with_id', 'vehicle_registration'];

    public const CARGO_OWNER_TYPES = [
        'id_card' => 'Kimlik kartı (ön yüz)',
        'tax_plate' => 'Vergi levhası (kurumsal)',
        'signature_circular' => 'İmza sirküleri (kurumsal, varsa)',
    ];

    public const CARGO_OWNER_REQUIRED = ['id_card'];

    public const CARGO_OWNER_CORPORATE_REQUIRED = ['id_card', 'tax_plate'];

    public const STATUS_LABELS = [
        'pending' => 'İnceleniyor',
        'approved' => 'Onaylandı',
        'rejected' => 'Reddedildi',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function label(): string
    {
        return self::DRIVER_TYPES[$this->document_type] ?? self::CARGO_OWNER_TYPES[$this->document_type] ?? $this->document_type;
    }
}
