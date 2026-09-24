<?php

namespace App\Services;

use App\Models\CargoOwnerProfile;
use App\Models\Load;
use App\Models\Shipment;
use App\Support\BodyTypes;
use App\Support\Settings;
use App\Support\TurkishLocations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class LoadService
{
    /** Yeni ilanı doğrudan şoför havuzuna açık olarak yayınlar. */
    public function publish(CargoOwnerProfile $owner, array $data, ?UploadedFile $eIrsaliyeFile = null): Load
    {
        $minPrice = Settings::float('min_load_price');
        if ((float) $data['price'] < $minPrice) {
            throw new RuntimeException('Navlun bedeli en az '.number_format($minPrice, 0, ',', '.').' ₺ olmalıdır.');
        }

        // Adres metninden il/ilçe ve koordinat çözümlenir; koordinat açıkça verildiyse o korunur.
        $pickupGeo = TurkishLocations::resolve($data['pickup_location'] ?? null);
        $deliveryGeo = TurkishLocations::resolve($data['delivery_location'] ?? null);

        $load = DB::transaction(function () use ($owner, $data, $eIrsaliyeFile, $pickupGeo, $deliveryGeo): Load {
            $load = Load::create([
                'cargo_owner_profile_id' => $owner->id,
                'source_type' => 'internal',
                'visibility' => 'public',
                'pickup_location' => trim($data['pickup_location']),
                'pickup_province_code' => $pickupGeo['province_code'] ?? null,
                'pickup_district' => $pickupGeo['district'] ?? null,
                'delivery_location' => trim($data['delivery_location']),
                'delivery_province_code' => $deliveryGeo['province_code'] ?? null,
                'delivery_district' => $deliveryGeo['district'] ?? null,
                'pickup_lat' => $data['pickup_lat'] ?? $pickupGeo['lat'] ?? null,
                'pickup_lng' => $data['pickup_lng'] ?? $pickupGeo['lng'] ?? null,
                'delivery_lat' => $data['delivery_lat'] ?? $deliveryGeo['lat'] ?? null,
                'delivery_lng' => $data['delivery_lng'] ?? $deliveryGeo['lng'] ?? null,
                'pickup_date' => $data['pickup_date'],
                'delivery_date' => $data['delivery_date'] ?? null,
                'vehicle_type' => $data['vehicle_type'],
                'body_types' => ($bodies = BodyTypes::clean($data['body_types'] ?? [])) !== [] ? $bodies : null,
                'load_kind' => in_array($data['load_kind'] ?? null, ['komple', 'parca'], true) ? $data['load_kind'] : null,
                'goods_type' => trim($data['goods_type']),
                'weight' => isset($data['weight']) && $data['weight'] !== '' ? (int) $data['weight'] : null,
                'volume' => isset($data['volume']) && $data['volume'] !== '' ? (int) $data['volume'] : null,
                'price' => round((float) $data['price'], 2),
                'currency' => 'TRY',
                'e_irsaliye_no' => $data['e_irsaliye_no'] ?? null,
                'status' => Load::STATUS_ACTIVE,
                'escrow_status' => Load::ESCROW_PENDING,
                'published_at' => now(),
                // Premium şoförler anında görür; süre dolunca herkese ve Telegram kanalına açılır.
                'available_to_free_at' => now()->addMinutes(app(LoadReleaseService::class)->delayMinutes()),
            ]);

            if ($eIrsaliyeFile) {
                $path = $eIrsaliyeFile->storeAs('loads/'.$load->id, 'e-irsaliye-'.$load->id.'.'.$eIrsaliyeFile->getClientOriginalExtension(), 'private');
                $load->update(['e_irsaliye_path' => $path]);
            }

            return $load;
        });
        app(LoadReleaseService::class)->onPublished($load);

        app(LoadStatsService::class)->forget();

        return $load;
    }

    /** Tamamlanmış veya iptal edilmiş bir ilanı yeni tarihle yeniden yayınlar. */
    public function repeat(Load $source, CargoOwnerProfile $owner): Load
    {
        if ($source->cargo_owner_profile_id !== $owner->id) {
            throw new RuntimeException('Bu ilan size ait değil.');
        }

        $data = $source->only(['pickup_location', 'delivery_location', 'pickup_lat', 'pickup_lng', 'delivery_lat', 'delivery_lng', 'vehicle_type', 'goods_type', 'weight', 'volume', 'price']);
        $data['pickup_date'] = now()->addDay()->startOfDay();
        $data['delivery_date'] = $source->delivery_date && $source->pickup_date
            ? $data['pickup_date']->copy()->addDays(max(0, $source->pickup_date->diffInDays($source->delivery_date)))
            : null;

        return $this->publish($owner, $data);
    }

    /** Ödeme alınmamış bir ilanı iptal eder; bekleyen teklifler reddedilir. */
    public function cancel(Load $load, CargoOwnerProfile $owner, ?string $reason = null): void
    {
        $affected = collect();
        DB::transaction(function () use ($load, $owner, $reason, &$affected): void {
            $locked = Load::query()->lockForUpdate()->findOrFail($load->id);

            if ($locked->cargo_owner_profile_id !== $owner->id) {
                throw new RuntimeException('Bu ilan size ait değil.');
            }

            $cancellable = $locked->status === Load::STATUS_ACTIVE
                || ($locked->status === Load::STATUS_ASSIGNED && $locked->escrow_status === Load::ESCROW_PENDING);

            if (! $cancellable) {
                throw new RuntimeException('Ödemesi yapılmış veya yola çıkmış bir sevkiyat buradan iptal edilemez. Lütfen destek ekibiyle iletişime geçin.');
            }

            $affected = $locked->offers()->with('driverProfile.user')->whereIn('status', ['pending', 'accepted'])->get();
            $locked->offers()->whereIn('status', ['pending', 'accepted'])->update(['status' => 'rejected', 'responded_at' => now()]);
            $locked->shipment()->update(['status' => Shipment::STATUS_CANCELLED]);
            $locked->update([
                'status' => Load::STATUS_CANCELLED,
                'rejection_reason' => $reason ? mb_substr($reason, 0, 1000) : null,
                'cancelled_at' => now(),
                'visibility' => 'private',
            ]);
        });
        app(DriverTripService::class)->closeForLoad($load->id);

        $notifications = app(NotificationService::class);
        foreach ($affected as $offer) {
            if ($driverUser = $offer->driverProfile?->user) {
                $notifications->notify($driverUser, 'İlan iptal edildi',
                    ["{$load->pickup_location} → {$load->delivery_location} ilanı yük sahibi tarafından iptal edildi; ".($offer->status === 'accepted' ? 'kabul edilmiş teklifiniz ve sevkiyat kaydı kapandı.' : 'teklifiniz kapandı.'),
                        $reason ? 'Yük sahibinin açıklaması: '.mb_substr($reason, 0, 300) : 'İlan havuzunda size uygun başka yükler sizi bekliyor.'],
                    route('driver.loads.index'), 'İlan havuzuna git', 'load');
            }
        }
    }

    public function eIrsaliyeExists(Load $load): bool
    {
        return $load->e_irsaliye_path && Storage::disk('private')->exists($load->e_irsaliye_path);
    }
}
