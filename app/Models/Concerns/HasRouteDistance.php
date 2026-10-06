<?php

namespace App\Models\Concerns;

use App\Support\Geo;

/**
 * Kalkış–varış koordinatı olan ilanlar (Load, ScrapedLoad): kara yolu tahmini ve kilometre başına navlun.
 * Kolon adları iki modelde aynıdır (pickup_lat/lng, delivery_lat/lng, price). Ton başına fiyatta ₺/km hesaplanmaz.
 */
trait HasRouteDistance
{
    /** Kara yolu tahmini (km, haversine × 1,25); koordinat eksikse null. */
    public function distanceKm(): ?float
    {
        $num = fn ($v) => $v === null || $v === '' ? null : (float) $v;

        return Geo::roadKm($num($this->pickup_lat), $num($this->pickup_lng), $num($this->delivery_lat), $num($this->delivery_lng));
    }

    /** Kilometre başına navlun (₺/km); fiyat yok, ton başına fiyat ya da mesafe yoksa null. */
    public function pricePerKm(): ?float
    {
        if (($this->price_unit ?? null) === 'per_ton' || ($this->currency ?? 'TRY') !== 'TRY') {
            return null;
        }

        return Geo::pricePerKm($this->price === null ? null : (float) $this->price, $this->distanceKm());
    }

    /** Kart satırı: "≈ 650 km · 69 ₺/km"; mesafe bilinmiyorsa null. */
    public function distanceLabel(): ?string
    {
        return Geo::label($this->distanceKm(), $this->pricePerKm());
    }
}
