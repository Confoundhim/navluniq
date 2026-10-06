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
    public function trailFor(Shipment $shipment, int $limit = 200): array
    {
        $points = DriverLocation::query()->where('shipment_id', $shipment->id)->latest('id')->take($limit)->get()->reverse()->values();

        return [
            'latest' => $points->last(),
            'trail' => $points->map(fn (DriverLocation $p) => [(float) $p->latitude, (float) $p->longitude])->all(),
        ];
    }

    /** Saklama süresi dolan konum izlerini siler (günlük görev); varsayılan 90 gün. */
    public function purgeOld(int $days = 90): int
    {
        return DriverLocation::query()->where('recorded_at', '<', now()->subDays(max(1, $days)))->delete();
    }
}
