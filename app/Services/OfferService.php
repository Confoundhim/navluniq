<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\DriverProfile;
use App\Models\Load;
use App\Models\Offer;
use App\Models\OutboxEvent;
use App\Models\Shipment;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class OfferService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /** Şoför bir ilana teklif verir; ilan başına tek aktif teklif tutulur. */
    public function submit(DriverProfile $driver, Load $load, float $amount, ?string $message = null, ?int $estimatedDays = null): Offer
    {
        if (! $driver->isKycApproved()) {
            throw new RuntimeException('Teklif verebilmek için belgelerinizin onaylanmış olması gerekir.');
        }

        if (! $driver->activeVehicle()->exists()) {
            throw new RuntimeException('Teklif verebilmek için en az bir aktif aracınız olmalı.');
        }

        if ($load->cargoOwnerProfile?->user_id === $driver->user_id) {
            throw new RuntimeException('Kendi ilanınıza teklif veremezsiniz.');
        }

        return DB::transaction(function () use ($driver, $load, $amount, $message, $estimatedDays): Offer {
            $locked = Load::query()->lockForUpdate()->findOrFail($load->id);

            if ($locked->status !== Load::STATUS_ACTIVE || $locked->visibility !== 'public') {
                throw new RuntimeException('Bu ilan artık teklif kabul etmiyor.');
            }

            $offer = Offer::query()->where('load_id', $locked->id)->where('driver_profile_id', $driver->id)->first();

            if ($offer && in_array($offer->status, ['pending', 'accepted'], true)) {
                throw new RuntimeException('Bu ilana zaten aktif bir teklifiniz var.');
            }

            $attributes = [
                'amount' => round($amount, 2),
                'currency' => 'TRY',
                'message' => $message ? mb_substr(trim($message), 0, 1000) : null,
                'estimated_days' => $estimatedDays,
                'status' => 'pending',
                'expires_at' => now()->addDays(max(1, Settings::int('offer_validity_days'))),
                'responded_at' => null,
            ];

            $offer = $offer
                ? tap($offer)->update($attributes)
                : Offer::create($attributes + ['load_id' => $locked->id, 'driver_profile_id' => $driver->id]);

            if ($ownerUser = $locked->cargoOwnerProfile?->user) {
                $this->notifications->notify(
                    $ownerUser,
                    'İlanınıza yeni teklif geldi',
                    ["{$locked->pickup_location} → {$locked->delivery_location} ilanınıza ".number_format($offer->amount, 2, ',', '.').' ₺ tutarında yeni bir teklif verildi.'],
                    route('cargo-owner.loads.offers', $locked->id),
                    'Teklifleri incele'
                );
            }

            return $offer;
        }, 3);
    }

    public function withdraw(Offer $offer, DriverProfile $driver): void
    {
        if ($offer->driver_profile_id !== $driver->id || $offer->status !== 'pending') {
            throw new RuntimeException('Yalnızca değerlendirme aşamasındaki kendi teklifinizi geri çekebilirsiniz.');
        }

        $offer->update(['status' => 'withdrawn', 'responded_at' => now()]);
    }

    public function reject(Offer $offer, int $ownerUserId): void
    {
        if ($offer->cargoLoad?->cargoOwnerProfile?->user_id !== $ownerUserId || $offer->status !== 'pending') {
            throw new RuntimeException('Bu teklif reddedilemez.');
        }

        $offer->update(['status' => 'rejected', 'responded_at' => now()]);
    }

    /** Teklif kabulü: şoför atanır, diğer teklifler reddedilir, sevkiyat ve mesajlaşma kaydı açılır. */
    public function accept(Load $load, Offer $offer, int $ownerUserId): Shipment
    {
        $shipment = DB::transaction(function () use ($load, $offer, $ownerUserId): Shipment {
            $lockedLoad = Load::query()->lockForUpdate()->findOrFail($load->id);
            $lockedOffer = Offer::query()->lockForUpdate()->findOrFail($offer->id);

            if ($lockedLoad->status !== Load::STATUS_ACTIVE || $lockedOffer->status !== 'pending' || $lockedOffer->load_id !== $lockedLoad->id) {
                throw new RuntimeException('Teklif artık kabul edilebilir durumda değil.');
            }

            if ($lockedLoad->cargoOwnerProfile?->user_id !== $ownerUserId) {
                throw new RuntimeException('Bu ilan size ait değil.');
            }

            $driver = $lockedOffer->driverProfile;
            if (! $driver || ! $driver->isKycApproved()) {
                throw new RuntimeException('Şoförün belge doğrulaması tamamlanmadığı için teklif kabul edilemez.');
            }

            if ($lockedOffer->expires_at && $lockedOffer->expires_at->isPast()) {
                $lockedOffer->update(['status' => 'expired', 'responded_at' => now()]);
                throw new RuntimeException('Teklifin süresi dolmuş.');
            }

            $lockedOffer->update(['status' => 'accepted', 'responded_at' => now()]);
            Offer::query()->where('load_id', $lockedLoad->id)->whereKeyNot($lockedOffer->id)->where('status', 'pending')
                ->update(['status' => 'rejected', 'responded_at' => now()]);

            $lockedLoad->update([
                'driver_profile_id' => $lockedOffer->driver_profile_id,
                'price' => $lockedOffer->amount,
                'status' => Load::STATUS_ASSIGNED,
                'escrow_status' => Load::ESCROW_PENDING,
                'visibility' => 'private',
            ]);

            $shipment = Shipment::create([
                'load_id' => $lockedLoad->id,
                'accepted_offer_id' => $lockedOffer->id,
                'driver_profile_id' => $lockedOffer->driver_profile_id,
                'vehicle_id' => $driver->activeVehicle?->id,
                'status' => Shipment::STATUS_AWAITING_PICKUP,
            ]);

            $conversation = Conversation::create(['load_id' => $lockedLoad->id, 'shipment_id' => $shipment->id, 'opened_at' => now()]);
            foreach (array_unique([$ownerUserId, (int) $driver->user_id]) as $userId) {
                ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $userId]);
            }

            OutboxEvent::create([
                'event_id' => (string) Str::uuid(),
                'aggregate_type' => 'shipment',
                'aggregate_id' => (string) $shipment->id,
                'event_type' => 'offer.accepted',
                'payload' => ['load_id' => $lockedLoad->id, 'offer_id' => $lockedOffer->id],
                'available_at' => now(),
            ]);

            return $shipment;
        }, 3);

        if ($driverUser = $shipment->driverProfile?->user) {
            $this->notifications->notify(
                $driverUser,
                'Teklifiniz kabul edildi',
                ['Teklifiniz yük sahibi tarafından kabul edildi. Yük sahibi navlun ödemesini yaptığında sevkiyat sayfasından yola çıkabileceksiniz.'],
                route('driver.shipments.show', $shipment->load_id),
                'Sevkiyatı görüntüle'
            );
        }

        return $shipment;
    }

    /** Süresi geçmiş bekleyen teklifleri kapatır (zamanlanmış görev). */
    public function expireStale(): int
    {
        return Offer::query()->where('status', 'pending')->whereNotNull('expires_at')->where('expires_at', '<', now())
            ->update(['status' => 'expired', 'responded_at' => now()]);
    }
}
