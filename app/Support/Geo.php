<?php

namespace App\Support;

/**
 * Coğrafi hesapların tek yeri: büyük daire (haversine) uzaklığı, kara yolu tahmini ve kilometre başına navlun.
 * "Yakınımda" süzgeci (LoadFilterService), kart satırı ("≈ 650 km · 69 ₺/km") ve testler aynı formülü kullanır.
 */
final class Geo
{
    public const EARTH_RADIUS_KM = 6371.0;

    /** Kuş uçuşu → kara yolu çarpanı (Türkiye şehirler arası yollar için kaba ortalama). */
    public const ROAD_FACTOR = 1.25;

    /** İki nokta arası kuş uçuşu uzaklık (km). */
    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * asin(min(1.0, sqrt($a)));
    }

    /** Kara yolu tahmini (km): kuş uçuşu × ROAD_FACTOR. Koordinatlardan biri eksikse null. */
    public static function roadKm(?float $lat1, ?float $lng1, ?float $lat2, ?float $lng2): ?float
    {
        if ($lat1 === null || $lng1 === null || $lat2 === null || $lng2 === null) {
            return null;
        }
        if (abs($lat1) > 90 || abs($lat2) > 90 || abs($lng1) > 180 || abs($lng2) > 180) {
            return null;
        }
        $km = self::haversineKm($lat1, $lng1, $lat2, $lng2) * self::ROAD_FACTOR;

        return $km >= 1 ? $km : null; // aynı nokta: anlamlı mesafe yok
    }

    /** Kilometre başına navlun; fiyat ya da mesafe yoksa null. */
    public static function pricePerKm(?float $price, ?float $km): ?float
    {
        if ($price === null || $price <= 0 || $km === null || $km <= 0) {
            return null;
        }

        return $price / $km;
    }

    /** Kart satırı: "≈ 650 km · 69 ₺/km" (₺/km yoksa yalnız mesafe). */
    public static function label(?float $km, ?float $perKm): ?string
    {
        if ($km === null) {
            return null;
        }
        $text = '≈ '.number_format(round($km / 5) * 5, 0, ',', '.').' km';
        if ($perKm !== null) {
            $text .= ' · '.number_format($perKm, $perKm < 10 ? 1 : 0, ',', '.').' ₺/km';
        }

        return $text;
    }

    /** Haversine SQL (km); bağlamalar sırası: lat, lat, lng. Trigonometrik fonksiyon gerektirir (MySQL/MariaDB). */
    public static function distanceSql(string $latCol, string $lngCol): string
    {
        return '('.self::EARTH_RADIUS_KM." * 2 * ASIN(SQRT(POWER(SIN(RADIANS({$latCol} - ?) / 2), 2) + COS(RADIANS(?)) * COS(RADIANS({$latCol})) * POWER(SIN(RADIANS({$lngCol} - ?) / 2), 2))))";
    }
}
