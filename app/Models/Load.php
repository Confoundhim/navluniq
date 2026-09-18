<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Load extends Model
{
    public const STATUS_ACTIVE = 'active_seeking';

    public const STATUS_ASSIGNED = 'driver_assigned';

    public const STATUS_ON_THE_WAY = 'on_the_way';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_DISPUTED = 'disputed';

    public const STATUS_CANCELLED = 'cancelled';

    public const ESCROW_PENDING = 'pending_payment';

    public const ESCROW_PAID = 'paid_in_escrow';

    public const ESCROW_ON_HOLD = 'on_hold';

    public const ESCROW_RELEASE_APPROVED = 'release_approved';

    public const ESCROW_RELEASED = 'released_to_driver';

    public const ESCROW_REFUNDED = 'refunded_to_owner';

    public const STATUS_LABELS = [
        self::STATUS_ACTIVE => 'Teklif bekliyor',
        self::STATUS_ASSIGNED => 'Şoför atandı',
        self::STATUS_ON_THE_WAY => 'Yolda',
        self::STATUS_DELIVERED => 'Teslim edildi, onay bekliyor',
        self::STATUS_COMPLETED => 'Tamamlandı',
        self::STATUS_DISPUTED => 'Uyuşmazlık',
        self::STATUS_CANCELLED => 'İptal edildi',
    ];

    public const ESCROW_LABELS = [
        self::ESCROW_PENDING => 'Ödeme bekleniyor',
        self::ESCROW_PAID => 'Ödendi, teslimat onayı bekleniyor',
        self::ESCROW_ON_HOLD => 'Uyuşmazlık nedeniyle askıda',
        self::ESCROW_RELEASE_APPROVED => 'Şoför ödemesi onaylandı',
        self::ESCROW_RELEASED => 'Şoföre ödendi',
        self::ESCROW_REFUNDED => 'Yük sahibine iade edildi',
    ];

    public const GOODS_TYPES = [
        'Paletli Yük', 'Dökme Yük', 'Koli / Paket', 'Ev / Ofis Eşyası', 'Soğuk Zincir (Frigo)',
        'İnşaat / Yapı Malzemesi', 'Makine & Ağır Sanayi', 'Tehlikeli Madde (ADR)', 'Diğer / Özel',
    ];

    use HasFactory, HasPublicId, SoftDeletes;

    protected $fillable = [
        'cargo_owner_profile_id',
        'driver_profile_id',
        'source_type',
        'visibility',
        'pickup_location',
        'pickup_province_code',
        'pickup_district',
        'delivery_location',
        'delivery_province_code',
        'delivery_district',
        'pickup_coordinates',
        'delivery_coordinates',
        'pickup_lat',
        'pickup_lng',
        'delivery_lat',
        'delivery_lng',
        'pickup_date',
        'delivery_date',
        'vehicle_type',
        'goods_type',
        'weight',
        'volume',
        'price',
        'currency',
        'escrow_status',
        'status',
        'dispute_reason',
        'rejection_reason',
        'e_irsaliye_no',
        'e_irsaliye_path',
        'published_at',
        'cancelled_at',
    ];

    protected $casts = [
        'pickup_date' => 'datetime',
        'delivery_date' => 'datetime',
        'published_at' => 'datetime',
        'cancelled_at' => 'datetime',
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

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function shipment(): HasOne
    {
        return $this->hasOne(Shipment::class);
    }

    public function paymentOrders(): HasMany
    {
        return $this->hasMany(PaymentOrder::class);
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class);
    }

    public function payout(): HasOne
    {
        return $this->hasOne(Payout::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function escrowLabel(): string
    {
        return self::ESCROW_LABELS[$this->escrow_status] ?? $this->escrow_status;
    }

    public function isPaid(): bool
    {
        return in_array($this->escrow_status, [self::ESCROW_PAID, self::ESCROW_ON_HOLD, self::ESCROW_RELEASE_APPROVED, self::ESCROW_RELEASED], true);
    }

    public function openDispute(): ?Dispute
    {
        return $this->disputes()->where('status', 'open')->latest()->first();
    }

    public function getPickupLatLngAttribute(): ?array
    {
        return $this->pickup_lat !== null && $this->pickup_lng !== null ? [(float) $this->pickup_lat, (float) $this->pickup_lng] : null;
    }

    public function getDeliveryLatLngAttribute(): ?array
    {
        return $this->delivery_lat !== null && $this->delivery_lng !== null ? [(float) $this->delivery_lat, (float) $this->delivery_lng] : null;
    }
}
