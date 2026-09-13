<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Load;
use App\Models\Offer;
use App\Models\OutboxEvent;
use App\Models\Shipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class OfferAcceptanceService
{
    public function accept(Load $load, Offer $offer, int $ownerUserId): Shipment
    {
        return DB::transaction(function () use ($load, $offer, $ownerUserId): Shipment {
            $lockedLoad = Load::query()->lockForUpdate()->findOrFail($load->id);
            $lockedOffer = Offer::query()->lockForUpdate()->findOrFail($offer->id);
            if ($lockedLoad->status !== 'active_seeking' || $lockedOffer->status !== 'pending' || $lockedOffer->load_id !== $lockedLoad->id) {
                throw new RuntimeException('Teklif artık kabul edilebilir durumda değil.');
            }
            if ($lockedLoad->cargoOwnerProfile?->user_id !== $ownerUserId || $lockedOffer->driverProfile?->kyc_status !== 'approved') {
                throw new RuntimeException('Teklif kabul yetkisi veya şoför doğrulaması geçersiz.');
            }
            $lockedOffer->update(['status'=>'accepted','responded_at'=>now()]);
            Offer::query()->where('load_id',$lockedLoad->id)->whereKeyNot($lockedOffer->id)->where('status','pending')->update(['status'=>'rejected','responded_at'=>now()]);
            $lockedLoad->update(['driver_profile_id'=>$lockedOffer->driver_profile_id,'price'=>$lockedOffer->amount,'status'=>'driver_assigned','escrow_status'=>'pending_payment']);
            $shipment=Shipment::create(['load_id'=>$lockedLoad->id,'accepted_offer_id'=>$lockedOffer->id,'driver_profile_id'=>$lockedOffer->driver_profile_id,'vehicle_id'=>$lockedOffer->driverProfile?->activeVehicle?->id,'status'=>'awaiting_pickup']);
            $conversation=Conversation::create(['load_id'=>$lockedLoad->id,'shipment_id'=>$shipment->id,'opened_at'=>now()]);
            foreach (array_unique([$ownerUserId,(int)$lockedOffer->driverProfile->user_id]) as $userId) ConversationParticipant::create(['conversation_id'=>$conversation->id,'user_id'=>$userId]);
            OutboxEvent::create(['event_id'=>(string)Str::uuid(),'aggregate_type'=>'shipment','aggregate_id'=>(string)$shipment->id,'event_type'=>'offer.accepted','payload'=>['load_id'=>$lockedLoad->id,'offer_id'=>$lockedOffer->id],'available_at'=>now()]);
            return $shipment;
        }, 3);
    }
}
