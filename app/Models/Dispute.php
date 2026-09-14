<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Dispute extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'load_id',
        'opened_by',
        'cargo_owner_claim',
        'claim_photo_path',
        'driver_proof_photo_path',
        'driver_defense',
        'status',
        'resolution',
        'arbitration_notes',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public const STATUS_LABELS = [
        'open' => 'İnceleniyor',
        'resolved_driver_paid' => 'Şoför lehine sonuçlandı',
        'resolved_owner_refunded' => 'Yük sahibi lehine sonuçlandı',
        'cancelled' => 'Geri çekildi',
    ];

    public function cargoLoad(): BelongsTo
    {
        return $this->belongsTo(Load::class, 'load_id');
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
