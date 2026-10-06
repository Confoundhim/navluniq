<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Dispute;
use App\Models\DriverProfile;
use App\Models\DriverTrip;
use App\Models\Load;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Settings;
use App\Support\UploadName;
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

            $photoPath = $photo?->storeAs('disputes/'.$locked->id, 'claim-'.now()->format('YmdHis').'.'.UploadName::extension($photo), 'private');

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
        }, 3); // eşzamanlı işlemde kilitlenme olursa 3 kez denenir

        if ($driverUser = $load->driverProfile?->user) {
            $this->notifications->notify($driverUser, 'Sevkiyatınız için uyuşmazlık açıldı',
                ['Yük sahibi sevkiyat hakkında bir uyuşmazlık bildirdi. Navlun ödemesi karar verilene kadar askıya alındı. Lütfen savunmanızı ve kanıtlarınızı yükleyin.'],
                route('driver.disputes.index'), 'Savunma yap', 'dispute');
        }
        $this->notifications->notifyAdmins('manage disputes', 'Yeni uyuşmazlık açıldı',
            ["Sevkiyat #{$load->id} ({$load->pickup_location} → {$load->delivery_location}) için yük sahibi uyuşmazlık bildirdi; navlun ödemesi askıya alındı.", 'Şoför savunmasını sunduktan sonra karar bekliyor.'],
            route('admin.disputes'), 'Uyuşmazlıkları incele', 'admin');

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

        $photoPath = $photo?->storeAs('disputes/'.$load->id, 'defense-'.now()->format('YmdHis').'.'.UploadName::extension($photo), 'private');

        $dispute->update([
            'driver_defense' => mb_substr(trim($defense), 0, 3000),
            'driver_proof_photo_path' => $photoPath ?? $dispute->driver_proof_photo_path,
        ]);

        if ($ownerUser = $load->cargoOwnerProfile?->user) {
            $this->notifications->notify($ownerUser, 'Şoför savunmasını sundu',
                ["Sevkiyat #{$load->id} için açtığınız uyuşmazlığa şoför savunma ve kanıt ekledi. Hakem ekibi iki tarafın belgelerini inceleyip karar verecek."],
                route('cargo-owner.disputes.index'), 'Uyuşmazlığı görüntüle', 'dispute');
        }
        $this->notifications->notifyAdmins('manage disputes', 'Uyuşmazlık karar için hazır',
            ["Sevkiyat #{$load->id} uyuşmazlığında şoför savunmasını sundu; iki tarafın beyanı ve kanıtları tamamlandı."],
            route('admin.disputes'), 'Karar ver', 'admin');
    }

    /**
     * Sevkiyatın durumuna göre verilebilecek kararlar (hakem ekranı bunlardan birini seçer).
     * Teslim edilmemiş (yolda) sevkiyatta: "devam" ya da "iptal + iade". Teslim edilmişte: "şoföre öde" ya da "iade".
     *
     * @return array<string,string> karar => etiket
     */
    public static function allowedResolutions(Dispute $dispute): array
    {
        $delivered = $dispute->cargoLoad?->shipment?->delivered_at !== null;

        return $delivered
            ? [Dispute::RESOLUTION_DRIVER_PAID => 'Şoför haklı: hakediş ödenir', Dispute::RESOLUTION_OWNER_REFUNDED => 'Yük sahibi haklı: navlun iade edilir']
            : [Dispute::RESOLUTION_CONTINUE => 'Sevkiyat devam eder: uyuşmazlık kapanır, yük yola döner', Dispute::RESOLUTION_OWNER_REFUNDED => 'İptal + iade: sevkiyat iptal edilir, navlun yük sahibine iade edilir'];
    }

    /**
     * Hakem kararı. $resolution: 'continue' | 'driver_paid' | 'owner_refunded'. Yük kamyondayken (teslim edilmemiş) "şoföre öde"
     * verilemez: hakediş ancak teslimattan sonra açılır; yoldaki uyuşmazlıkta seçenekler "devam" ve "iptal + iade"dir.
     */
    public function resolve(Dispute $dispute, User $admin, string $resolution, string $notes): void
    {
        $allowed = self::allowedResolutions($dispute);
        if (! array_key_exists($resolution, $allowed)) {
            throw new RuntimeException($dispute->cargoLoad?->shipment?->delivered_at
                ? 'Teslim edilmiş sevkiyatta karar "şoföre öde" ya da "iade" olabilir.'
                : 'Yük henüz teslim edilmedi: karar "sevkiyat devam eder" ya da "iptal + iade" olabilir; hakediş teslimattan önce ödenmez.');
        }

        // Durumlar kilit altında yazılır; ödeme kuruluşu çağrıları (hakediş aktarımı, iade) kilit DIŞINDA yapılır:
        // yavaş sağlayıcı ilan/uyuşmazlık satırlarını kilitli tutmasın, para hareketi veritabanı geri alınırken kaybolmasın.
        $delivered = false;
        DB::transaction(function () use ($dispute, $admin, $resolution, $notes, &$delivered): void {
            $locked = Dispute::query()->lockForUpdate()->findOrFail($dispute->id);
            if ($locked->status !== 'open') {
                throw new RuntimeException('Bu uyuşmazlık zaten karara bağlanmış.');
            }

            $load = Load::query()->lockForUpdate()->findOrFail($locked->load_id);
            $shipment = Shipment::query()->lockForUpdate()->where('load_id', $load->id)->first();
            $delivered = $shipment?->delivered_at !== null;

            $locked->update([
                'status' => match ($resolution) {
                    Dispute::RESOLUTION_DRIVER_PAID => 'resolved_driver_paid',
                    Dispute::RESOLUTION_OWNER_REFUNDED => 'resolved_owner_refunded',
                    default => 'dismissed',
                },
                'resolution' => $resolution,
                'arbitration_notes' => mb_substr(trim($notes), 0, 3000),
                'resolved_by' => $admin->id,
                'resolved_at' => now(),
            ]);

            if ($resolution === Dispute::RESOLUTION_CONTINUE) {
                // Yük yola döner: ilan "yolda", sevkiyat "taşınıyor", para yine teslimat onayını bekler.
                $load->update(['status' => Load::STATUS_ON_THE_WAY, 'escrow_status' => Load::ESCROW_PAID]);
                $shipment?->update(['status' => Shipment::STATUS_IN_TRANSIT]);
            } elseif ($resolution === Dispute::RESOLUTION_DRIVER_PAID) {
                $load->update(['status' => Load::STATUS_COMPLETED, 'escrow_status' => Load::ESCROW_RELEASE_APPROVED]);
                $shipment?->update(['status' => Shipment::STATUS_COMPLETED, 'owner_approved_at' => now()]);
            } elseif ($delivered) {
                // Teslim edilmiş ama yük sahibi haklı: sevkiyat kapanır, iade sonucu gelene kadar havuz "askıda" kalır.
                $load->update(['status' => Load::STATUS_COMPLETED, 'escrow_status' => Load::ESCROW_ON_HOLD]);
                $shipment?->update(['status' => Shipment::STATUS_COMPLETED, 'owner_rejected_at' => now()]);
            } else {
                // Yoldayken iptal + iade: sevkiyat ve iş kapanır, ilan iptal olur.
                $load->update(['status' => Load::STATUS_CANCELLED, 'escrow_status' => Load::ESCROW_ON_HOLD, 'cancelled_at' => now(), 'visibility' => 'private',
                    'rejection_reason' => 'Hakem kararı: sevkiyat yolda iptal edildi, navlun iade ediliyor.']);
                $shipment?->update(['status' => Shipment::STATUS_CANCELLED]);
                $load->offers()->where('status', 'accepted')->update(['status' => 'rejected', 'responded_at' => now()]);
            }

            ActivityLog::record('dispute.resolved', "Uyuşmazlık #{$locked->id}: {$resolution}", $admin->id, $locked);
        });

        $load = $dispute->cargoLoad?->fresh();
        $refunded = null;
        if ($load && $resolution === Dispute::RESOLUTION_DRIVER_PAID) {
            $this->payouts->createForLoad($load);
        } elseif ($load && $resolution === Dispute::RESOLUTION_OWNER_REFUNDED) {
            $refunded = $this->payments->refundLoad($load, 'Uyuşmazlık kararı #'.$dispute->id);
        }
        if ($shipment = $load?->shipment) {
            app(DriverTripService::class)->syncShipment($shipment, $resolution === Dispute::RESOLUTION_CONTINUE ? DriverTrip::STATUS_ON_THE_WAY : DriverTrip::STATUS_CLOSED);
        }
        $ownerUser = $load?->cargoOwnerProfile?->user;
        foreach (array_filter([$ownerUser, $load?->driverProfile?->user]) as $user) {
            $isOwner = $user->id === $ownerUser?->id;
            $line = match (true) {
                $resolution === Dispute::RESOLUTION_CONTINUE => 'Hakem kararı: sevkiyat devam eder. Uyuşmazlık kapandı, yük yolda sayılır; navlun ödemesi teslimat onayıyla şoföre tamamlanır.',
                $resolution === Dispute::RESOLUTION_DRIVER_PAID => 'Hakem kararı şoför lehine sonuçlandı; navlun ödemesi şoföre yapılmak üzere sıraya alındı.',
                $refunded === true => 'Hakem kararı yük sahibi lehine sonuçlandı; '.($delivered ? '' : 'sevkiyat iptal edildi, ').'navlun bedeli yük sahibine iade edildi (bankaya göre 1-10 iş günü).',
                default => 'Hakem kararı yük sahibi lehine sonuçlandı; '.($delivered ? '' : 'sevkiyat iptal edildi, ').'navlun bedelinin iadesi finans ekibi tarafından tamamlanacak.',
            };
            $this->notifications->notify($user, 'Uyuşmazlık karara bağlandı',
                [$line, 'Karar notu: '.mb_substr($notes, 0, 300)],
                route($isOwner ? 'cargo-owner.disputes.index' : 'driver.disputes.index'), 'Uyuşmazlığı görüntüle', 'dispute');
        }
    }

    /**
     * Yük sahibi açtığı uyuşmazlığı geri çeker: kayıt "geri çekildi" olur, sevkiyat önceki durumuna döner (yolda ya da teslim
     * edildi; teslim edildiyse otomatik onay süresi yeniden başlar), navlun yine teslimat onayını bekler.
     */
    public function withdraw(Dispute $dispute, User $owner): void
    {
        $restoredDelivered = false;
        DB::transaction(function () use ($dispute, $owner, &$restoredDelivered): void {
            $locked = Dispute::query()->lockForUpdate()->findOrFail($dispute->id);
            if ($locked->status !== 'open') {
                throw new RuntimeException('Yalnız açık uyuşmazlık geri çekilebilir.');
            }
            $load = Load::query()->lockForUpdate()->findOrFail($locked->load_id);
            if ($load->cargoOwnerProfile?->user_id !== $owner->id || $locked->opened_by !== $owner->id) {
                throw new RuntimeException('Bu uyuşmazlık size ait değil.');
            }
            $shipment = Shipment::query()->lockForUpdate()->where('load_id', $load->id)->first();
            $restoredDelivered = $shipment?->delivered_at !== null;

            $locked->update(['status' => 'cancelled', 'resolution' => null, 'resolved_at' => now(), 'arbitration_notes' => 'Yük sahibi uyuşmazlığı geri çekti.']);
            if ($restoredDelivered) {
                $hours = max(1, Settings::int('delivery_auto_approval_hours'));
                $shipment->update(['status' => Shipment::STATUS_DELIVERED, 'auto_approval_due_at' => now()->addHours($hours)]);
                $load->update(['status' => Load::STATUS_DELIVERED, 'escrow_status' => Load::ESCROW_PAID]);
            } else {
                $shipment?->update(['status' => Shipment::STATUS_IN_TRANSIT]);
                $load->update(['status' => Load::STATUS_ON_THE_WAY, 'escrow_status' => Load::ESCROW_PAID]);
            }
            ActivityLog::record('dispute.withdrawn', "Uyuşmazlık #{$locked->id} yük sahibi tarafından geri çekildi", $owner->id, $locked);
        });

        $load = $dispute->cargoLoad?->fresh();
        if ($shipment = $load?->shipment) {
            app(DriverTripService::class)->syncShipment($shipment, $restoredDelivered ? DriverTrip::STATUS_DELIVERED : DriverTrip::STATUS_ON_THE_WAY);
        }
        if ($driverUser = $load?->driverProfile?->user) {
            $this->notifications->notify($driverUser, 'Uyuşmazlık geri çekildi',
                ["Sevkiyat #{$load->id} için açılan uyuşmazlığı yük sahibi geri çekti. ".($restoredDelivered ? 'Teslimat yük sahibinin onayını bekliyor; onay süresi yeniden başladı.' : 'Sevkiyat yolda sayılıyor; teslimatta kanıt yükleyin.')],
                route('driver.jobs.show', $load->id), 'İşi görüntüle', 'dispute');
        }
        $this->notifications->notifyAdmins('manage disputes', 'Uyuşmazlık geri çekildi',
            ["Sevkiyat #{$load?->id} uyuşmazlığını yük sahibi geri çekti; sevkiyat ".($restoredDelivered ? '"teslim edildi, onay bekliyor"' : '"yolda"').' durumuna döndü.'],
            route('admin.disputes'), 'Uyuşmazlıklar', 'admin');
    }
}
