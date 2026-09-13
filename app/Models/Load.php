<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\SoftDeletes;

class Load extends Model
{
    use HasFactory, SoftDeletes, HasPublicId;

    protected $fillable = [
        'cargo_owner_profile_id',
        'driver_profile_id',
        'pickup_location',
        'delivery_location',
        'pickup_coordinates',
        'delivery_coordinates',
        'pickup_date',
        'delivery_date',
        'vehicle_type',
        'goods_type',
        'weight',
        'volume',
        'price',
        'escrow_status',
        'status',
        'dispute_reason',
        'rejection_reason',
        'e_irsaliye_no',
    ];

    protected $casts = [
        'pickup_date' => 'datetime',
        'delivery_date' => 'datetime',
        'price' => 'decimal:2',
    ];

    /**
     * Yük Sahibi (Gönderici) Profil İlişkisi
     */
    public function cargoOwnerProfile(): BelongsTo
    {
        return $this->belongsTo(CargoOwnerProfile::class);
    }

    /**
     * Sürücü (Şoför) Profil İlişkisi
     */
    public function driverProfile(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class);
    }


    public function offers(): HasMany { return $this->hasMany(Offer::class); }
    public function shipment(): HasOne { return $this->hasOne(Shipment::class); }
    public function paymentOrders(): HasMany { return $this->hasMany(PaymentOrder::class); }
    public function disputes(): HasMany { return $this->hasMany(Dispute::class); }

    /**
     * Koordinat Yardımcısı: MySQL Spatial POINT verisini
     * enlem/boylam dizisine dönüştürerek haritada kullanılabilir hale getirir.
     */
    public function getPickupLatLngAttribute(): ?array
    {
        return null;
    }

    public function getDeliveryLatLngAttribute(): ?array
    {
        return null;
    }
}
