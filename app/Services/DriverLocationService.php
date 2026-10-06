<?php

namespace App\Services;

use App\Models\DriverLocation;
use App\Models\DriverProfile;
use App\Models\Shipment;

class DriverLocationService
{
    public const MIN_INTERVAL_SECONDS = 10;

    /** Konum kaydı; aktif sevkiyata bağlanır ve çok sık gönderimler atlanır. */
    public function record(DriverProfile $driver, float $lat, float $lng, ?float $speed = null, ?float $heading = null, ?float $accuracy = null, ?int $shipmentId = null): ?DriverLocation
    {
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return null;
        }

        $last = $driver->latestLocation;
        if ($last && $last->recorded_at && $last->recorded_at->diffInSeconds(now()) < self::MIN_INTERVAL_SECONDS) {
            return null;
        }

        // Yoldaki sevkiyat; uyuşmazlık açılmış ama henüz teslim edilmemiş sevkiyat da "yolda" sayılır (hakem konumu görmeli).
        $live = fn ($q) => $q->where(fn ($w) => $w->where('status', Shipment::STATUS_IN_TRANSIT)
            ->orWhere(fn ($d) => $d->where('status', Shipment::STATUS_DISPUTED)->whereNull('delivered_at')));
        $shipment = null;
        if ($shipmentId) {
            $shipment = Shipment::query()->whereKey($shipmentId)->where('driver_profile_id', $driver->id)->tap($live)->first();
        }
        $shipment ??= Shipment::query()->where('driver_profile_id', $driver->id)->tap($live)->latest('id')->first();
        if (! $shipment) {
            // KVKK metni: konum yalnız aktif (yoldaki) sevkiyat süresince işlenir; tarayıcı yine de gönderirse kaydedilmez.
            return null;
        }

        return DriverLocation::create([
            'driver_profile_id' => $driver->id,
            'shipment_id' => $shipment?->id,
            'latitude' => round($lat, 7),
            'longitude' => round($lng, 7),
            'speed' => $speed !== null ? max(0, round($speed, 2)) : 0,
            'heading' => $heading !== null ? round($heading, 2) : 0,
            'accuracy_meters' => $accuracy !== null ? round($accuracy, 2) : null,
            'recorded_at' => now(),
        ]);
    }

    /** Sevkiyat için son konum ve rota izi. */
    /** Haritada en çok bu kadar nokta çizilir; uzun yolda iz seyreltilir (eskiden son 200 nokta ≈ 50 dakika görünüyordu). */
    public const TRAIL_MAX_POINTS = 300;

    /**
     * Sevkiyatın tüm izi (seyreltilmiş) ve son konum. Seyreltme başlangıç ve son noktayı her zaman korur.
     *
     * @return array{latest: ?DriverLocation, trail: list<array{0: float, 1: float}>}
     */
    public function trailFor(Shipment $shipment, int $maxPoints = self::TRAIL_MAX_POINTS): array
    {
        $latest = DriverLocation::query()->where('shipment_id', $shipment->id)->latest('id')->first();
        $total = $latest ? DriverLocation::query()->where('shipment_id', $shipment->id)->count() : 0;
        if ($total === 0) {
            return ['latest' => null, 'trail' => []];
        }
        $step = max(1, (int) ceil($total / max(2, $maxPoints)));
        $query = DriverLocation::query()->where('shipment_id', $shipment->id)->orderBy('id')->select(['id', 'latitude', 'longitude']);
        $trail = [];
        $i = 0;
        foreach ($query->lazy(1000) as $p) {
            if ($i % $step === 0) {
                $trail[] = [(float) $p->latitude, (float) $p->longitude];
            }
            $i++;
        }
        $last = [(float) $latest->latitude, (float) $latest->longitude];
        if ($trail === [] || end($trail) !== $last) {
            $trail[] = $last;
        }

        return ['latest' => $latest, 'trail' => $trail];
    }

    /** Son konumdan teslim noktasına kuş uçuşu × 1,25 (kara yolu yaklaşımı) km; koordinat yoksa null. */
    public static function remainingKm(?DriverLocation $latest, ?float $deliveryLat, ?float $deliveryLng): ?float
    {
        if (! $latest || $deliveryLat === null || $deliveryLng === null) {
            return null;
        }
        $lat1 = deg2rad((float) $latest->latitude);
        $lat2 = deg2rad($deliveryLat);
        $dLat = $lat2 - $lat1;
        $dLng = deg2rad($deliveryLng - (float) $latest->longitude);
        $a = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;

        return round(6371 * 2 * atan2(sqrt($a), sqrt(1 - $a)) * 1.25, 1);
    }

    /** Saklama süresi dolan konum izlerini siler (günlük görev); varsayılan 90 gün. */
    public function purgeOld(int $days = 90): int
    {
        return DriverLocation::query()->where('recorded_at', '<', now()->subDays(max(1, $days)))->delete();
    }
}
