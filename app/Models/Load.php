<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasRouteDistance;
use App\Support\BodyTypes;
use App\Support\TurkishLocations;
use App\Support\VehicleTypes;
use Illuminate\Database\Eloquent\Builder;
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

    /** İade kararı verildi ama ödeme kuruluşu iadeyi henüz onaylamadı; finans ekibi "İade yapıldı" deyince refunded_to_owner olur. */
    public const ESCROW_REFUND_PENDING = 'refund_pending';

    /** Navlun doğrudan taraflar arasında ödenir (FreightPayment::direct): platform tahsilat yapmaz, hakediş/iade yoktur. */
    public const ESCROW_DIRECT = 'direct_payment';

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
        self::ESCROW_REFUND_PENDING => 'İade bekleniyor',
        self::ESCROW_DIRECT => 'Taraflar arasında ödenir',
    ];

    public const GOODS_TYPES = [
        'Paletli Yük', 'Dökme Yük', 'Koli / Paket', 'Ev / Ofis Eşyası', 'Soğuk Zincir (Frigo)',
        'İnşaat / Yapı Malzemesi', 'Makine & Ağır Sanayi', 'Tehlikeli Madde (ADR)', 'Diğer / Özel',
    ];

    use HasFactory, HasPublicId, HasRouteDistance, SoftDeletes;

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
        'pickup_address_private',
        'delivery_address_private',
        'pickup_contact_name',
        'pickup_contact_phone',
        'notes',
        'pickup_coordinates',
        'delivery_coordinates',
        'pickup_lat',
        'pickup_lng',
        'delivery_lat',
        'delivery_lng',
        'pickup_date',
        'delivery_date',
        'vehicle_type',
        'body_types',
        'load_kind',
        'delivery_stops',
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
        'available_to_free_at',
        'released_at',
        'telegram_posted_at',
        'telegram_attempts',
        'cancelled_at',
        'payment_due_at',
        'payment_reminded_at',
        'no_show_notified_at',
        'transit_overdue_notified_at',
    ];

    protected $casts = [
        'pickup_date' => 'datetime',
        'delivery_date' => 'datetime',
        'published_at' => 'datetime',
        'available_to_free_at' => 'datetime',
        'released_at' => 'datetime',
        'telegram_posted_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'payment_due_at' => 'datetime',
        'payment_reminded_at' => 'datetime',
        'no_show_notified_at' => 'datetime',
        'transit_overdue_notified_at' => 'datetime',
        'price' => 'decimal:2',
        'body_types' => 'array',
        'delivery_stops' => 'array',
    ];

    /** Şoföre not en çok bu kadar karakter. */
    public const NOTES_MAX = 500;

    /**
     * Herkese açık kalkış etiketi ("Ankara Yenimahalle"): il/ilçe çözülmüşse ondan, yoksa ilan metninden. Eski (serbest metinli)
     * ilanlarda da yalnız il/ilçe gösterilir; açık adres `pickup_address_private` kolonunda ayrı durur.
     */
    public function publicPickup(): string
    {
        return $this->publicPlace('pickup');
    }

    public function publicDelivery(): string
    {
        return $this->publicPlace('delivery');
    }

    /** "Ankara Yenimahalle → İzmir Aliağa": kartlar, bildirimler ve Telegram için herkese açık rota. */
    public function publicRoute(): string
    {
        return $this->publicPickup().' → '.$this->publicDelivery();
    }

    private function publicPlace(string $side): string
    {
        $code = $this->{$side.'_province_code'};
        if ($code && ($province = TurkishLocations::province((int) $code))) {
            return (string) TurkishLocations::label(['province' => $province['name'], 'district' => $this->{$side.'_district'}]);
        }

        return (string) $this->{$side.'_location'};
    }

    /**
     * Açık adres, yükleme yetkilisi ve şoföre not kime görünür: ilan sahibi, atanmış ve ödemesi alınmış (ya da sonrası) şoför,
     * yönetici paneli kullanıcıları. Havuzdaki şoför yalnız il/ilçe görür (KVKK, plan E6/E7).
     */
    public function canSeePrivateDetails(?User $viewer): bool
    {
        if (! $viewer) {
            return false;
        }
        // Pasif ya da engellenmiş yönetici hesabı gizli alanları göremez (isAdminPanelUser aktiflik ve engel denetimi yapar).
        if ($viewer->isAdminPanelUser()) {
            return true;
        }
        if ($this->cargo_owner_profile_id && $viewer->cargoOwnerProfile?->id === $this->cargo_owner_profile_id) {
            return true;
        }

        return $this->driver_profile_id
            && $viewer->driverProfile?->id === $this->driver_profile_id
            && ($this->isPaid() || $this->isDirectPayment());
    }

    /** Navlun doğrudan taraflar arasında ödeniyor (teklif kabulünde FreightPayment::direct açıktı). */
    public function isDirectPayment(): bool
    {
        return $this->escrow_status === self::ESCROW_DIRECT;
    }

    /**
     * Açık adresler; görme hakkı yoksa null. Dönen dizi: ['pickup' => ?string, 'delivery' => ?string].
     *
     * @return array{pickup: ?string, delivery: ?string}|null
     */
    public function privateAddressFor(?User $viewer): ?array
    {
        if (! $this->canSeePrivateDetails($viewer)) {
            return null;
        }

        return [
            'pickup' => $this->pickup_address_private ?: null,
            'delivery' => $this->delivery_address_private ?: null,
        ];
    }

    /** Şoföre not; görme hakkı yoksa ya da not yoksa null. */
    public function notesFor(?User $viewer): ?string
    {
        if (! $this->canSeePrivateDetails($viewer)) {
            return null;
        }

        return $this->notes !== null && trim($this->notes) !== '' ? $this->notes : null;
    }

    /**
     * Yükleme yetkilisi (adres defterinden taşınır); görme hakkı yoksa ya da ikisi de boşsa null.
     *
     * @return array{name: ?string, phone: ?string}|null
     */
    public function pickupContactFor(?User $viewer): ?array
    {
        if (! $this->canSeePrivateDetails($viewer) || (! $this->pickup_contact_name && ! $this->pickup_contact_phone)) {
            return null;
        }

        return ['name' => $this->pickup_contact_name ?: null, 'phone' => $this->pickup_contact_phone ?: null];
    }

    /** Yük sahibi ilanı düzenleyebilir mi (yalnız teklif bekleyen ilan)? */
    public function isEditableByOwner(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** Kasa etiketi ("Tenteli", "Damper / Açık"); belirtilmemişse null. */
    public function bodyLabel(): ?string
    {
        return BodyTypes::summary($this->body_types);
    }

    public function loadKindLabel(): ?string
    {
        return BodyTypes::LOAD_KINDS[$this->load_kind ?? ''] ?? null;
    }

    /** "24 ton" / "800 kg" / null (dış kaynak kartıyla aynı biçim). */
    public function weightLabel(): ?string
    {
        $kg = (int) $this->weight;
        if ($kg <= 0) {
            return null;
        }
        if ($kg >= 1000) {
            $t = $kg / 1000;

            return (fmod($t, 1.0) === 0.0 ? number_format($t, 0, ',', '.') : number_format($t, 1, ',', '.')).' ton';
        }

        return number_format($kg, 0, ',', '.').' kg';
    }

    /** "45.000 ₺" / "45.000,50 ₺"; fiyat yoksa null. */
    public function priceLabel(): ?string
    {
        if ($this->price === null || (float) $this->price <= 0) {
            return null;
        }
        $v = (float) $this->price;

        return number_format($v, fmod($v, 1.0) === 0.0 ? 0 : 2, ',', '.').' ₺';
    }

    /** Kart satırı: "TIR · Tenteli · Komple yük". */
    public function vehicleSummary(): string
    {
        return implode(' · ', array_filter([VehicleTypes::label($this->vehicle_type) ?: 'Araç belirtilmemiş', $this->bodyLabel(), $this->loadKindLabel()]));
    }

    /**
     * "Paylaş" metni: rota, araç, yük, fiyat ve ilan bağlantısı. Yük sahibinin adı ya da iletişim bilgisi paylaşılmaz
     * (Kullanıcı Sözleşmesi md. 3.4); ilana ulaşan şoför bilgileri kendi hesabıyla görür.
     */
    public function shareText(): string
    {
        $lines = array_filter([
            'NavlunIQ ilanı: '.($this->pickup_location ?: 'Belirtilmemiş').' → '.($this->delivery_location ?: 'Belirtilmemiş'),
            implode(' · ', array_filter([$this->goods_type, $this->vehicleSummary(), $this->weightLabel()])),
            $this->priceLabel() ? 'Navlun: '.$this->priceLabel() : null,
            $this->pickup_date ? 'Yükleme: '.$this->pickup_date->format('d.m.Y H:i') : null,
        ]);

        return implode("\n", $lines);
    }

    /** Paylaşılan bağlantı: ilan havuzunda bu ilanla açılır. */
    public function shareUrl(): string
    {
        return route('driver.loads.index', ['ilan' => $this->id]);
    }

    /** Ücretsiz (premium olmayan) şoförlere açıldı mı? Süre tanımsızsa her zaman açık. */
    public function isAvailableToFree(): bool
    {
        return $this->available_to_free_at === null || $this->available_to_free_at->isPast();
    }

    /** Premium erken erişim penceresinde mi? */
    public function isEarlyAccess(): bool
    {
        return ! $this->isAvailableToFree();
    }

    /** Verilen şoförün (premium değilse) görebileceği ilanlarla sınırlar. */
    /**
     * Şoförün teklif verebileceği ilanlar: yayında, herkese/premiuma açık, yükleme tarihi geçmemiş, kendi ilanı değil ve
     * bu şoförün bekleyen/kabul edilmiş teklifi yok. Havuz, genel bakış ve dönüş yükü listesi aynı kuralı kullanır
     * (genel bakışta teklif verilmiş ilan "Teklif ver" ile listelenip havuzda "bulunamadı" diyordu, 2026-10-06).
     */
    public function scopeOfferableBy(Builder $query, ?DriverProfile $profile): Builder
    {
        $profileId = $profile?->id ?? 0;
        $userId = $profile?->user_id ?? 0;

        return $query
            ->where('status', self::STATUS_ACTIVE)->where('visibility', 'public')
            ->where(fn (Builder $q) => $q->whereNull('pickup_date')->orWhere('pickup_date', '>=', today()))
            ->where(fn (Builder $q) => $q->whereNull('cargo_owner_profile_id')->orWhereHas('cargoOwnerProfile', fn ($o) => $o->where('user_id', '!=', $userId)))
            ->openTo($profile)
            ->whereDoesntHave('offers', fn (Builder $q) => $q->where('driver_profile_id', $profileId)->whereIn('status', ['pending', 'accepted']));
    }

    public function scopeOpenTo(Builder $query, ?DriverProfile $profile): Builder
    {
        if ($profile?->isPremium()) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q->whereNull('available_to_free_at')->orWhere('available_to_free_at', '<=', now()));
    }

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
        if ($this->isClosedWithRefund()) {
            return $this->escrow_status === self::ESCROW_REFUND_PENDING ? 'İade bekleniyor' : 'İade ile kapandı';
        }

        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /**
     * Uyuşmazlık ya da iptal sonucu navlun yük sahibine iade edilerek (ya da iadesi beklenerek) kapanan sevkiyat:
     * teslimat sayılmaz, puanlanmaz, istatistiğe girmez.
     */
    public function isClosedWithRefund(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)
            && in_array($this->escrow_status, [self::ESCROW_REFUNDED, self::ESCROW_REFUND_PENDING], true);
    }

    /** Ödeme alınmış, yola çıkılmamış: yük sahibi tam iadeyle iptal edebilir, şoför vazgeçebilir (karar 4). */
    public function canBeCancelledBeforeTransit(): bool
    {
        return $this->status === self::STATUS_ASSIGNED && $this->escrow_status === self::ESCROW_PAID;
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
