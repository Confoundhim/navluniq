<?php

namespace App\Services;

use App\Models\DriverProfile;
use App\Models\Load;
use App\Models\Shipment;
use App\Models\ShipmentEvidence;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ShipmentService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly PayoutService $payouts,
    ) {}

    /** Şoför yükü aldı ve yola çıktı. Navlun ödemesi alınmadan başlatılamaz. */
    public function startTransit(Shipment $shipment, DriverProfile $driver): void
    {
        DB::transaction(function () use ($shipment, $driver): void {
            $locked = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);
            $load = Load::query()->lockForUpdate()->findOrFail($locked->load_id);

            if ($locked->driver_profile_id !== $driver->id) {
                throw new RuntimeException('Bu sevkiyat size atanmamış.');
            }
            if ($locked->status !== Shipment::STATUS_AWAITING_PICKUP || $load->status !== Load::STATUS_ASSIGNED) {
                throw new RuntimeException('Sevkiyat yola çıkarılabilir durumda değil.');
            }
            if ($load->escrow_status !== Load::ESCROW_PAID) {
                throw new RuntimeException('Yük sahibi navlun ödemesini yapmadan yola çıkamazsınız.');
            }

            $locked->update([
                'status' => Shipment::STATUS_IN_TRANSIT,
                'pickup_confirmed_at' => now(),
                'in_transit_at' => now(),
            ]);
            $load->update(['status' => Load::STATUS_ON_THE_WAY]);
        });

        if ($ownerUser = $shipment->cargoLoad?->cargoOwnerProfile?->user) {
            $this->notifications->notify($ownerUser, 'Yükünüz yola çıktı',
                ['Şoför yükü teslim aldı ve yola çıktı. Canlı konumu sevkiyat sayfasından takip edebilirsiniz.'],
                route('cargo-owner.shipments.show', $shipment->load_id), 'Sevkiyatı takip et');
        }
    }

    /** Şoför teslimat kanıtını (POD) yükler; yük sahibi onayı beklenir. */
    public function markDelivered(Shipment $shipment, DriverProfile $driver, UploadedFile $proof, ?string $note = null): ShipmentEvidence
    {
        $evidence = DB::transaction(function () use ($shipment, $driver, $proof, $note): ShipmentEvidence {
            $locked = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);
            $load = Load::query()->lockForUpdate()->findOrFail($locked->load_id);

            if ($locked->driver_profile_id !== $driver->id) {
                throw new RuntimeException('Bu sevkiyat size atanmamış.');
            }
            if ($locked->status !== Shipment::STATUS_IN_TRANSIT || $load->status !== Load::STATUS_ON_THE_WAY) {
                throw new RuntimeException('Teslimat kanıtı yalnız yoldaki sevkiyatlar için yüklenebilir.');
            }

            $path = $proof->storeAs(
                'evidence/'.$locked->id,
                'pod-'.now()->format('YmdHis').'.'.strtolower($proof->getClientOriginalExtension()),
                'private'
            );

            $evidence = ShipmentEvidence::create([
                'shipment_id' => $locked->id,
                'uploaded_by' => $driver->user_id,
                'type' => 'pod',
                'storage_disk' => 'private',
                'storage_path' => $path,
                'sha256' => hash_file('sha256', $proof->getRealPath()),
                'metadata' => ['note' => $note ? mb_substr($note, 0, 500) : null, 'original_name' => $proof->getClientOriginalName(), 'size' => $proof->getSize()],
                'captured_at' => now(),
            ]);

            $hours = max(1, Settings::int('delivery_auto_approval_hours'));
            $locked->update([
                'status' => Shipment::STATUS_DELIVERED,
                'delivered_at' => now(),
                'auto_approval_due_at' => now()->addHours($hours),
            ]);
            $load->update(['status' => Load::STATUS_DELIVERED]);

            return $evidence;
        });

        if ($ownerUser = $shipment->cargoLoad?->cargoOwnerProfile?->user) {
            $hours = max(1, Settings::int('delivery_auto_approval_hours'));
            $this->notifications->notify($ownerUser, 'Teslimat kanıtı yüklendi',
                ["Şoför teslimatı tamamladı ve kanıt yükledi. Lütfen {$hours} saat içinde teslimatı onaylayın; aksi halde ödeme otomatik olarak serbest bırakılır."],
                route('cargo-owner.shipments.show', $shipment->load_id), 'Teslimatı onayla');
        }

        return $evidence;
    }

    /** Yük sahibi teslimatı onaylar; şoför hakedişi ödeme sırasına alınır. */
    public function approveDelivery(Shipment $shipment, ?User $owner, bool $automatic = false): void
    {
        DB::transaction(function () use ($shipment, $owner, $automatic): void {
            $locked = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);
            $load = Load::query()->lockForUpdate()->findOrFail($locked->load_id);

            if (! $automatic && $load->cargoOwnerProfile?->user_id !== $owner?->id) {
                throw new RuntimeException('Bu sevkiyat size ait değil.');
            }
            if ($locked->status !== Shipment::STATUS_DELIVERED || $load->status !== Load::STATUS_DELIVERED) {
                throw new RuntimeException('Onaylanacak bir teslimat bulunmuyor.');
            }
            if ($load->escrow_status !== Load::ESCROW_PAID) {
                throw new RuntimeException('Teslimat onayı bekleyen bir navlun ödemesi bulunmadığından onay verilemez.');
            }
            if ($load->openDispute()) {
                throw new RuntimeException('Açık bir uyuşmazlık varken teslimat onaylanamaz.');
            }

            $locked->update(['status' => Shipment::STATUS_COMPLETED, 'owner_approved_at' => now()]);
            $load->update(['status' => Load::STATUS_COMPLETED, 'escrow_status' => Load::ESCROW_RELEASE_APPROVED]);

            $this->payouts->createForLoad($load->fresh());
        });

        if ($driverUser = $shipment->driverProfile?->user) {
            $this->notifications->notify($driverUser, 'Teslimat onaylandı, ödemeniz sıraya alındı',
                ['Yük sahibi teslimatı onayladı. Ödemeniz platform hizmet bedeli düşüldükten sonra kayıtlı IBAN adresinize yapılacaktır.'],
                route('driver.wallet.index'), 'Cüzdanı görüntüle');
        }
    }

    /** Onay süresi dolan teslimatları otomatik onaylar (zamanlanmış görev). */
    public function autoApproveDue(): int
    {
        $count = 0;
        Shipment::query()->where('status', Shipment::STATUS_DELIVERED)
            ->whereNotNull('auto_approval_due_at')->where('auto_approval_due_at', '<=', now())
            ->with('cargoLoad')->each(function (Shipment $shipment) use (&$count): void {
                try {
                    $this->approveDelivery($shipment, null, automatic: true);
                    $count++;
                } catch (RuntimeException) {
                    // Uyuşmazlık veya ödeme durumu nedeniyle atlanır; bir sonraki çalışmada tekrar denenir.
                }
            });

        return $count;
    }
}
