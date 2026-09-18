<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Dispute;
use App\Models\DriverProfile;
use App\Models\Load;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DisputeService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly PayoutService $payouts,
        private readonly PaymentService $payments,
    ) {}

    /** Yük sahibi uyuşmazlık açar; navlun ödemesi karar verilene kadar askıya alınır. */
    public function open(Load $load, User $owner, string $claim, ?UploadedFile $photo = null): Dispute
    {
        $dispute = DB::transaction(function () use ($load, $owner, $claim, $photo): Dispute {
            $locked = Load::query()->lockForUpdate()->findOrFail($load->id);

            if ($locked->cargoOwnerProfile?->user_id !== $owner->id) {
                throw new RuntimeException('Bu ilan size ait değil.');
            }
            if (! in_array($locked->status, [Load::STATUS_ON_THE_WAY, Load::STATUS_DELIVERED], true)) {
                throw new RuntimeException('Uyuşmazlık yalnız yoldaki veya teslim edilmiş sevkiyatlar için açılabilir.');
            }
            if ($locked->escrow_status !== Load::ESCROW_PAID) {
                throw new RuntimeException('Teslimat onayı bekleyen bir navlun ödemesi bulunmadığından uyuşmazlık açılamaz.');
            }
            if ($locked->openDispute()) {
                throw new RuntimeException('Bu sevkiyat için zaten açık bir uyuşmazlık var.');
            }

            $photoPath = $photo?->storeAs('disputes/'.$locked->id, 'claim-'.now()->format('YmdHis').'.'.strtolower($photo->getClientOriginalExtension()), 'private');

            $dispute = Dispute::create([
                'load_id' => $locked->id,
                'opened_by' => $owner->id,
                'cargo_owner_claim' => mb_substr(trim($claim), 0, 3000),
                'claim_photo_path' => $photoPath,
                'status' => 'open',
            ]);

            $locked->update(['status' => Load::STATUS_DISPUTED, 'escrow_status' => Load::ESCROW_ON_HOLD, 'dispute_reason' => mb_substr(trim($claim), 0, 1000)]);
            $locked->shipment()->update(['status' => Shipment::STATUS_DISPUTED]);

            return $dispute;
        });

        if ($driverUser = $load->driverProfile?->user) {
            $this->notifications->notify($driverUser, 'Sevkiyatınız için uyuşmazlık açıldı',
                ['Yük sahibi sevkiyat hakkında bir uyuşmazlık bildirdi. Navlun ödemesi karar verilene kadar askıya alındı. Lütfen savunmanızı ve kanıtlarınızı yükleyin.'],
                route('driver.disputes.index'), 'Savunma yap');
        }

        return $dispute;
    }

    public function defend(Dispute $dispute, DriverProfile $driver, string $defense, ?UploadedFile $photo = null): void
    {
        $load = $dispute->cargoLoad;
        if (! $load || $load->driver_profile_id !== $driver->id) {
            throw new RuntimeException('Bu uyuşmazlık size ait bir sevkiyata bağlı değil.');
        }
        if ($dispute->status !== 'open') {
            throw new RuntimeException('Karara bağlanmış uyuşmazlığa savunma eklenemez.');
        }

        $photoPath = $photo?->storeAs('disputes/'.$load->id, 'defense-'.now()->format('YmdHis').'.'.strtolower($photo->getClientOriginalExtension()), 'private');

        $dispute->update([
            'driver_defense' => mb_substr(trim($defense), 0, 3000),
            'driver_proof_photo_path' => $photoPath ?? $dispute->driver_proof_photo_path,
        ]);
    }

    /**
     * Hakem kararı. $resolution: 'driver_paid' | 'owner_refunded'
     */
    public function resolve(Dispute $dispute, User $admin, string $resolution, string $notes): void
    {
        if (! in_array($resolution, ['driver_paid', 'owner_refunded'], true)) {
            throw new RuntimeException('Geçersiz karar.');
        }

        DB::transaction(function () use ($dispute, $admin, $resolution, $notes): void {
            $locked = Dispute::query()->lockForUpdate()->findOrFail($dispute->id);
            if ($locked->status !== 'open') {
                throw new RuntimeException('Bu uyuşmazlık zaten karara bağlanmış.');
            }

            $load = Load::query()->lockForUpdate()->findOrFail($locked->load_id);

            $locked->update([
                'status' => $resolution === 'driver_paid' ? 'resolved_driver_paid' : 'resolved_owner_refunded',
                'resolution' => $resolution,
                'arbitration_notes' => mb_substr(trim($notes), 0, 3000),
                'resolved_by' => $admin->id,
                'resolved_at' => now(),
            ]);

            if ($resolution === 'driver_paid') {
                $load->update(['status' => Load::STATUS_COMPLETED, 'escrow_status' => Load::ESCROW_RELEASE_APPROVED]);
                $load->shipment()->update(['status' => Shipment::STATUS_COMPLETED, 'owner_approved_at' => now()]);
                $this->payouts->createForLoad($load->fresh());
            } else {
                $load->update(['status' => Load::STATUS_COMPLETED, 'escrow_status' => Load::ESCROW_REFUNDED]);
                $load->shipment()->update(['status' => Shipment::STATUS_COMPLETED, 'owner_rejected_at' => now()]);
                $order = $load->paymentOrders()->where('status', 'paid')->latest()->first();
                if ($order) {
                    $this->payments->refund($order, (float) $order->amount, 'Uyuşmazlık kararı #'.$locked->id);
                }
            }

            ActivityLog::record('dispute.resolved', "Uyuşmazlık #{$locked->id}: {$resolution}", $admin->id, $locked);
        });

        $load = $dispute->cargoLoad?->fresh();
        foreach (array_filter([$load?->cargoOwnerProfile?->user, $load?->driverProfile?->user]) as $user) {
            $this->notifications->notify($user, 'Uyuşmazlık karara bağlandı',
                [$resolution === 'driver_paid' ? 'Hakem kararı şoför lehine sonuçlandı; navlun ödemesi şoföre yapılmak üzere sıraya alındı.' : 'Hakem kararı yük sahibi lehine sonuçlandı; navlun bedeli iade edilecek.', 'Karar notu: '.mb_substr($notes, 0, 300)]);
        }
    }
}
