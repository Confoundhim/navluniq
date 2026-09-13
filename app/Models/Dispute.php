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
        'cargo_owner_claim',
        'driver_proof_photo_path',
        'driver_defense',
        'status',
        'arbitration_notes',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    /**
     * Ait Olduğu Yük / Sevkiyat İlişkisi
     * 🚀 İsim çakışmasını önlemek için 'cargoLoad' yapılmıştır [11.2].
     */
    public function cargoLoad(): BelongsTo
    {
        return $this->belongsTo(Load::class, 'load_id');
    }
}
