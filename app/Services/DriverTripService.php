<?php

namespace App\Services;

use App\Models\DriverProfile;
use App\Models\DriverTrip;
use App\Models\DriverTripMatch;
use App\Models\Load;
use App\Models\ScrapedLoad;
use App\Models\Shipment;
use App\Support\BodyTypes;
use App\Support\Settings;
use App\Support\VehicleTypes;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Sefer takibi ve dönüş yükü.
 *
 * - Dış kaynak ilanında şoför "Bu işi aldım" der: sefer açılır (yükleme/teslim tarihi, varış yeri).
 * - Sistem ilanında teklif kabul edilince sefer kendiliğinden açılır; sevkiyat durumunu izler.
 * - Zamanlanmış tarama: açık seferlerin varış yeri çevresinden (aynı il ya da yarıçap içi) çıkan,
 *   araca/kasaya uyan, yükleme tarihi teslimden önce olmayan YENİ ilanlar şoföre bildirilir.
 *   Aynı ilan iki kez bildirilmez; e-posta sefer başına belirli aralıkla, uygulama içi bildirim her seferinde.
 */
class DriverTripService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly LoadFilterService $filters,
    ) {}

    /** Dış kaynak ilanı için "Bu işi aldım": aynı ilan için açık sefer varsa onu döndürür. */
    public function takeExternal(DriverProfile $driver, ScrapedLoad $load, ?CarbonInterface $pickupDate, ?CarbonInterface $deliveryDate, bool $notifyReturn = true): DriverTrip
    {
        if (! $driver->isPremium()) {
            throw new RuntimeException('Dış kaynak ilanları yalnız premium üyelere açıktır.');
        }
        $existing = DriverTrip::query()->where('driver_profile_id', $driver->id)->where('scraped_load_id', $load->id)->open()->first();
        if ($existing) {
            return $existing;
        }
        $pickupDate ??= now();
        $deliveryDate ??= $pickupDate->copy()->addDay();
        if ($deliveryDate->lt($pickupDate)) {
            throw new RuntimeException('Teslim tarihi yükleme tarihinden önce olamaz.');
        }

        return DriverTrip::create([
            'driver_profile_id' => $driver->id,
            'source' => 'external',
            'scraped_load_id' => $load->id,
            'pickup_location' => $load->pickup_location,
            'pickup_province_code' => $load->pickup_province_code,
            'delivery_location' => $load->delivery_location,
            'delivery_province_code' => $load->delivery_province_code,
            'delivery_lat' => $load->delivery_lat,
            'delivery_lng' => $load->delivery_lng,
            'pickup_date' => $pickupDate->toDateString(),
            'delivery_date' => $deliveryDate->toDateString(),
            'status' => DriverTrip::STATUS_PLANNED,
            'notify_return' => $notifyReturn,
        ]);
    }

    /** Sistem ilanında teklif kabul edildi: sevkiyata bağlı sefer açılır. */
    public function fromShipment(Shipment $shipment): ?DriverTrip
    {
        $load = $shipment->cargoLoad ?? Load::query()->find($shipment->load_id);
        if (! $load || ! $shipment->driver_profile_id) {
            return null;
        }
        $existing = DriverTrip::query()->where('shipment_id', $shipment->id)->first();
        if ($existing) {
            return $existing;
        }
        $pickup = $load->pickup_date ? Carbon::parse($load->pickup_date) : now();
        $delivery = $load->delivery_date ? Carbon::parse($load->delivery_date) : $pickup->copy()->addDay();

        return DriverTrip::create([
            'driver_profile_id' => $shipment->driver_profile_id,
            'source' => 'system',
            'load_id' => $load->id,
            'shipment_id' => $shipment->id,
            'pickup_location' => $load->pickup_location,
            'pickup_province_code' => $load->pickup_province_code,
            'delivery_location' => $load->delivery_location,
            'delivery_province_code' => $load->delivery_province_code,
            'delivery_lat' => $load->delivery_lat,
            'delivery_lng' => $load->delivery_lng,
            'pickup_date' => $pickup->toDateString(),
            'delivery_date' => max($pickup, $delivery)->toDateString(),
            'status' => DriverTrip::STATUS_PLANNED,
            'notify_return' => true,
        ]);
    }

    /** Sevkiyat durumu değişince bağlı seferi aynı hizaya getirir. */
    public function syncShipment(Shipment $shipment, string $status): void
    {
        $trip = DriverTrip::query()->where('shipment_id', $shipment->id)->first();
        if ($trip) {
            $this->setStatus($trip, $status);
        }
    }

    /** İlan iptal edildi: bağlı açık seferler kapanır. */
    public function closeForLoad(int $loadId): int
    {
        return DriverTrip::query()->where('load_id', $loadId)->open()->update(['status' => DriverTrip::STATUS_CLOSED, 'closed_at' => now()]);
    }

    public function setStatus(DriverTrip $trip, string $status): DriverTrip
    {
        if (! array_key_exists($status, DriverTrip::STATUS_LABELS)) {
            throw new RuntimeException('Geçersiz sefer durumu.');
        }
        $data = ['status' => $status];
        if ($status === DriverTrip::STATUS_CLOSED) {
            $data['closed_at'] = now();
        } elseif ($status === DriverTrip::STATUS_DELIVERED && ($trip->delivery_date === null || $trip->delivery_date->isFuture())) {
            $data['delivery_date'] = now()->toDateString(); // erken teslim: dönüş yükü aramasında bugünden itibaren
        }
        $trip->forceFill($data)->save();

        return $trip;
    }

    /** Teslimden N gün sonra ve çok gecikmiş planlı seferler kendiliğinden kapanır. */
    public function autoClose(): int
    {
        $days = max(1, Settings::int('trip_auto_close_days'));
        $n = DriverTrip::query()->where('status', DriverTrip::STATUS_DELIVERED)
            ->where(fn (Builder $q) => $q->where('delivery_date', '<', now()->subDays($days)->toDateString())->orWhere('updated_at', '<', now()->subDays($days + 2)))
            ->update(['status' => DriverTrip::STATUS_CLOSED, 'closed_at' => now()]);
        $n += DriverTrip::query()->whereIn('status', [DriverTrip::STATUS_PLANNED, DriverTrip::STATUS_ON_THE_WAY])
            ->whereNotNull('delivery_date')->where('delivery_date', '<', now()->subDays(14)->toDateString())
            ->update(['status' => DriverTrip::STATUS_CLOSED, 'closed_at' => now()]);

        return $n;
    }

    /**
     * Seferin varış yeri çevresinden çıkan, araca uyan ilanlar.
     *
     * @return array{system: Collection<int, Load>, external: Collection<int, ScrapedLoad>}
     */
    public function returnLoadsFor(DriverTrip $trip, bool $onlyNew = false, int $limit = 10): array
    {
        $profile = $trip->driverProfile;
        $point = $trip->destinationPoint();
        $empty = ['system' => collect(), 'external' => collect()];
        if (! $profile || ! $profile->isKycApproved() || (! $trip->delivery_province_code && ! $point)) {
            return $empty;
        }
        $radius = max(0, Settings::int('return_load_radius_km'));
        $earliest = ($trip->delivery_date ?? now())->copy()->startOfDay();
        $filters = LoadFilterService::defaults(); // aracıma uygun (sınıf + kasa + dorse boyu)
        $since = $onlyNew ? ($trip->last_scanned_at ?? $trip->created_at) : null;
        $seen = $onlyNew ? $trip->matches()->get()->groupBy('kind')->map(fn ($g) => $g->pluck('matched_id')->all()) : collect();

        $system = Load::query()
            ->with('cargoOwnerProfile.user')
            ->where('status', Load::STATUS_ACTIVE)->where('visibility', 'public')
            ->when($trip->load_id, fn (Builder $q) => $q->whereKeyNot($trip->load_id))
            ->where(fn (Builder $q) => $q->whereNull('cargo_owner_profile_id')->orWhereHas('cargoOwnerProfile', fn ($o) => $o->where('user_id', '!=', $profile->user_id)))
            ->openTo($profile)
            ->where(fn (Builder $q) => $q->whereNull('pickup_date')->orWhere('pickup_date', '>=', $earliest))
            ->tap(fn (Builder $q) => $this->filters->applyPickupAround($q, $trip->delivery_province_code, $point['lat'] ?? null, $point['lng'] ?? null, $radius))
            ->tap(fn (Builder $q) => $this->filters->applyToLoads($q, $filters, $profile))
            ->when($since, fn (Builder $q) => $q->where('created_at', '>=', $since)) // aynı saniye: bildirilmişler zaten dışarıda
            ->when(($seen['system'] ?? []) !== [], fn (Builder $q) => $q->whereKeyNot($seen['system']))
            ->take($limit)->get();

        $external = collect();
        if ($profile->isPremium()) {
            $external = ScrapedLoad::query()
                ->where('status', 'parsed_success')->where('visibility', 'public')
                ->when($trip->scraped_load_id, fn (Builder $q) => $q->whereKeyNot($trip->scraped_load_id))
                ->tap(fn (Builder $q) => $this->filters->applyPickupAround($q, $trip->delivery_province_code, $point['lat'] ?? null, $point['lng'] ?? null, $radius))
                ->tap(fn (Builder $q) => $this->filters->applyToScraped($q, $filters, $profile))
                ->when($since, fn (Builder $q) => $q->where('created_at', '>=', $since)) // aynı saniye: bildirilmişler zaten dışarıda
                ->when(($seen['external'] ?? []) !== [], fn (Builder $q) => $q->whereKeyNot($seen['external']))
                ->take($limit)->get();
        }

        return ['system' => $system, 'external' => $external];
    }

    /**
     * Zamanlanmış tarama: açık seferler için yeni dönüş yüklerini bulur ve bildirir.
     *
     * @return array{trips:int, notified:int}
     */
    public function scanReturnLoads(): array
    {
        $trips = DriverTrip::query()->with('driverProfile.user')->open()->where('notify_return', true)
            ->where(fn (Builder $q) => $q->whereNotNull('delivery_province_code')->orWhereNotNull('delivery_lat'))
            ->orderBy('id')->get();
        $notified = 0;
        foreach ($trips as $trip) {
            try {
                $found = $this->returnLoadsFor($trip, onlyNew: true, limit: 10);
                $rows = $found['system']->map(fn (Load $l) => ['kind' => 'system', 'id' => $l->id, 'line' => $this->lineForLoad($l)])
                    ->concat($found['external']->map(fn (ScrapedLoad $l) => ['kind' => 'external', 'id' => $l->id, 'line' => $this->lineForScraped($l)]));
                $trip->forceFill(['last_scanned_at' => now()])->save();
                if ($rows->isEmpty()) {
                    continue;
                }
                foreach ($rows as $r) {
                    DriverTripMatch::query()->firstOrCreate(['driver_trip_id' => $trip->id, 'kind' => $r['kind'], 'matched_id' => $r['id']], ['created_at' => now()]);
                }
                $user = $trip->driverProfile?->user;
                if (! $user) {
                    continue;
                }
                $mailHours = max(0, Settings::int('return_load_mail_hours'));
                $sendMail = $trip->last_mailed_at === null || $trip->last_mailed_at->lte(now()->subHours($mailHours));
                $count = $rows->count();
                $lines = $rows->take(5)->pluck('line')->all();
                if ($count > 5) {
                    $lines[] = '… ve '.($count - 5).' ilan daha.';
                }
                $lines[] = 'Seferiniz: '.$trip->routeLabel().($trip->delivery_date ? ' · teslim '.$trip->delivery_date->format('d.m.Y') : '').'. Bildirimleri Seferlerim sayfasından kapatabilirsiniz.';
                $this->notifications->notify($user,
                    'Dönüş yükü: '.($trip->delivery_location ?: 'varış yeri').' çevresinde '.$count.' yeni ilan',
                    $lines, route('driver.trips.index', ['sefer' => $trip->id]), 'Dönüş yüklerini gör', 'return_load', $sendMail);
                $trip->forceFill(['match_count' => $trip->match_count + $count, 'last_mailed_at' => $sendMail ? now() : $trip->last_mailed_at])->save();
                $notified++;
            } catch (\Throwable $e) {
                Log::warning('Dönüş yükü taraması başarısız.', ['trip_id' => $trip->id, 'error' => $e->getMessage()]);
            }
        }

        return ['trips' => $trips->count(), 'notified' => $notified];
    }

    private function lineForLoad(Load $l): string
    {
        $kg = (int) ($l->weight ?? 0);
        $parts = array_filter([$l->goods_type, VehicleTypes::label($l->vehicle_type), $l->bodyLabel(), $kg > 0 ? ($kg >= 1000 ? rtrim(rtrim(number_format($kg / 1000, 1, ',', '.'), '0'), ',').' ton' : $kg.' kg') : null]);
        $price = (float) ($l->price ?? 0);

        return $l->pickup_location.' → '.$l->delivery_location.' · '.implode(' · ', $parts).($price > 0 ? ' · '.number_format($price, 0, ',', '.').' ₺' : '').' (NavlunIQ ilanı)';
    }

    private function lineForScraped(ScrapedLoad $l): string
    {
        $parts = array_filter([$l->goods_type, $l->vehicleSummary(), $l->weightLabel(), $l->priceLabel()]);

        return ($l->pickup_location ?: '?').' → '.($l->delivery_location ?: '?').' · '.implode(' · ', $parts).' (gruptan derlendi)';
    }

    /** Aracın ilana uygunluğu (kart üzerinde uyarı için). */
    public static function vehicleFits(?DriverProfile $profile, Load|ScrapedLoad $load): bool
    {
        $vehicle = $profile?->activeVehicle()->first();
        if (! $vehicle || ! $load->vehicle_type) {
            return true;
        }

        return VehicleTypes::canCarry((string) $vehicle->vehicle_type, (string) $load->vehicle_type)
            && BodyTypes::vehicleFits($vehicle->body_type, $vehicle->trailer_length, $load->body_types);
    }
}
