<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\HasPublicId;

class Offer extends Model
{
    use HasFactory, SoftDeletes, HasPublicId;

    protected $fillable = [
        'public_id',
        'load_id',
        'driver_profile_id',
        'amount',
        'currency',
        'message',
        'status',
        'expires_at',
        'responded_at',
    ];

    protected $casts = [
        'amount'=>'decimal:4',
        'expires_at'=>'datetime',
        'responded_at'=>'datetime',
    ];
    public function cargoLoad(): BelongsTo { return $this->belongsTo(Load::class, 'load_id'); }
    public function driverProfile(): BelongsTo { return $this->belongsTo(DriverProfile::class); }
}
