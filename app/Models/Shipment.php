<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\HasPublicId;

class Shipment extends Model
{
    use HasFactory, SoftDeletes, HasPublicId;

    protected $fillable = [
        'public_id',
        'load_id',
        'accepted_offer_id',
        'driver_profile_id',
        'vehicle_id',
        'status',
        'pickup_confirmed_at',
        'in_transit_at',
        'delivered_at',
        'owner_approved_at',
        'owner_rejected_at',
        'auto_approval_due_at',
    ];

    protected $casts = [
        'pickup_confirmed_at'=>'datetime',
        'in_transit_at'=>'datetime',
        'delivered_at'=>'datetime',
        'owner_approved_at'=>'datetime',
        'owner_rejected_at'=>'datetime',
        'auto_approval_due_at'=>'datetime',
    ];
    public function cargoLoad(): BelongsTo { return $this->belongsTo(Load::class, 'load_id'); }
    public function acceptedOffer(): BelongsTo { return $this->belongsTo(Offer::class, 'accepted_offer_id'); }
    public function driverProfile(): BelongsTo { return $this->belongsTo(DriverProfile::class); }
    public function evidence(): HasMany { return $this->hasMany(ShipmentEvidence::class); }
}
