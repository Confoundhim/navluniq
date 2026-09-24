<?php

namespace App\Models;

use App\Support\TurkishLocations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Şoförün "Bu işi aldım" dediği sefer. Dış kaynak ilanından elle açılır; sistem ilanında teklif
 * kabul edilince kendiliğinden açılır ve sevkiyat durumunu izler. Varış yeri, dönüş yükü
 * taramasının merkezidir.
 */
class DriverTrip extends Model
{
    public const STATUS_PLANNED = 'planned';

    public const STATUS_ON_THE_WAY = 'on_the_way';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_CLOSED = 'closed';

    public const OPEN_STATUSES = [self::STATUS_PLANNED, self::STATUS_ON_THE_WAY, self::STATUS_DELIVERED];

    public const STATUS_LABELS = [
        self::STATUS_PLANNED => 'Planlandı',
        self::STATUS_ON_THE_WAY => 'Yolda',
        self::STATUS_DELIVERED => 'Teslim edildi',
        self::STATUS_CLOSED => 'Kapandı',
    ];

    protected $fillable = [
        'driver_profile_id', 'source', 'scraped_load_id', 'load_id', 'shipment_id',
        'pickup_location', 'pickup_province_code', 'delivery_location', 'delivery_province_code', 'delivery_lat', 'delivery_lng',
        'pickup_date', 'delivery_date', 'status', 'notify_return', 'last_scanned_at', 'last_mailed_at', 'match_count', 'closed_at',
    ];

    protected $casts = [
        'pickup_date' => 'date',
        'delivery_date' => 'date',
        'notify_return' => 'boolean',
        'last_scanned_at' => 'datetime',
        'last_mailed_at' => 'datetime',
        'closed_at' => 'datetime',
        'match_count' => 'integer',
    ];

    public function driverProfile(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class);
    }

    public function scrapedLoad(): BelongsTo
    {
        return $this->belongsTo(ScrapedLoad::class);
    }

    public function cargoLoad(): BelongsTo
    {
        return $this->belongsTo(Load::class, 'load_id');
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(DriverTripMatch::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isSystem(): bool
    {
        return $this->source === 'system';
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /**
     * Ekranda gösterilen durum anahtarı. NavlunIQ işinde ilanın durumu esastır: ödeme, teslimat onayı ve
     * uyuşmazlık orada yaşanır; iş kartı da aynı şeyi söyler. Gruptan alınan işte seferin kendi durumu.
     * Değerler: planned | on_the_way | delivered | disputed | closed
     */
    public function displayStatusKey(): string
    {
        $load = $this->isSystem() ? $this->cargoLoad : null;
        if (! $load) {
            return $this->status;
        }

        return match ($load->status) {
            Load::STATUS_ASSIGNED => self::STATUS_PLANNED,
            Load::STATUS_ON_THE_WAY => self::STATUS_ON_THE_WAY,
            Load::STATUS_DELIVERED => self::STATUS_DELIVERED,
            Load::STATUS_DISPUTED => 'disputed',
            default => self::STATUS_CLOSED,
        };
    }

    public function displayStatusLabel(): string
    {
        $load = $this->isSystem() ? $this->cargoLoad : null;
        if (! $load) {
            return $this->statusLabel();
        }

        return match ($load->status) {
            Load::STATUS_ASSIGNED => $load->isPaid() ? 'Yüklemeye hazır' : 'Ödeme bekleniyor',
            Load::STATUS_ON_THE_WAY => 'Yolda',
            Load::STATUS_DELIVERED => 'Teslim edildi, onay bekleniyor',
            Load::STATUS_DISPUTED => 'Uyuşmazlık',
            Load::STATUS_COMPLETED => 'Tamamlandı',
            Load::STATUS_CANCELLED => 'İptal edildi',
            default => $load->statusLabel(),
        };
    }

    /** Gruptan alınan iş elle kapatılır; NavlunIQ işi teslimat onayı ya da iptal ile kendiliğinden kapanır. */
    public function canCloseManually(): bool
    {
        return ! $this->isSystem() && $this->isOpen();
    }

    /** NavlunIQ işinde "Yola çıktım" ancak ödeme alındıktan sonra; gruptan alınan işte planlandı durumunda. */
    public function canStart(): bool
    {
        if (! $this->isOpen()) {
            return false;
        }
        if ($this->isSystem()) {
            $load = $this->cargoLoad;

            return $load !== null && $load->status === Load::STATUS_ASSIGNED && $load->isPaid();
        }

        return $this->status === self::STATUS_PLANNED;
    }

    public function sourceLabel(): string
    {
        return $this->isSystem() ? 'NavlunIQ ilanı' : 'Gruptan alındı';
    }

    public function routeLabel(): string
    {
        return ($this->pickup_location ?: 'Belirtilmemiş').' → '.($this->delivery_location ?: 'Belirtilmemiş');
    }

    /** Dönüş yükü aranacak merkez: varış koordinatı; yoksa il merkezi. */
    public function destinationPoint(): ?array
    {
        if ($this->delivery_lat !== null && $this->delivery_lng !== null) {
            return ['lat' => (float) $this->delivery_lat, 'lng' => (float) $this->delivery_lng];
        }
        $province = $this->delivery_province_code ? TurkishLocations::province((int) $this->delivery_province_code) : null;

        return $province ? ['lat' => (float) $province['lat'], 'lng' => (float) $province['lng']] : null;
    }
}
