<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\CargoOwnerProfile;
use App\Models\Load;
use App\Models\PaymentOrder;
use App\Models\Shipment;
use App\Models\User;
use App\Support\BodyTypes;
use App\Support\Settings;
use App\Support\TurkishLocations;
use App\Support\UploadName;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class LoadService
{
    /** Yeni ilanı doğrudan şoför havuzuna açık olarak yayınlar. */
    public function publish(CargoOwnerProfile $owner, array $data, ?UploadedFile $eIrsaliyeFile = null): Load
    {
        if ($owner->is_staff_view) {
            throw new RuntimeException('Yönetici görünümünde işlem yapılamaz.');
        }
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
                $path = $eIrsaliyeFile->storeAs('loads/'.$load->id, 'e-irsaliye-'.$load->id.'.'.UploadName::extension($eIrsaliyeFile), 'private');
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

        // Kasa tipi, yük biçimi ve teslim noktaları da taşınır; aksi halde "tenteli / 13.60" şartı kaybolup yanlış şoförlere bildirim giderdi.
        $data = $source->only(['pickup_location', 'delivery_location', 'pickup_lat', 'pickup_lng', 'delivery_lat', 'delivery_lng', 'vehicle_type', 'goods_type', 'weight', 'volume', 'price', 'body_types', 'load_kind']);
        $data['pickup_date'] = now()->addDay()->startOfDay();
        $data['delivery_date'] = $source->delivery_date && $source->pickup_date
            ? $data['pickup_date']->copy()->addDays(max(0, $source->pickup_date->diffInDays($source->delivery_date)))
            : null;

        return $this->publish($owner, $data);
    }

    /** Ödeme ekranı açılmış (sipariş "pending") ilan bu kadar dakika iptal edilemez: sağlayıcı sonucu gelmeden iptal, parayı havada bırakırdı. */
    public const PAYMENT_GRACE_MINUTES = 15;

    /** Ödeme alınmamış bir ilanı iptal eder; bekleyen teklifler reddedilir, açık ödeme emirleri kapatılır. */
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
                throw new RuntimeException($locked->canBeCancelledBeforeTransit()
                    ? 'Ödemesi alınmış sevkiyat sevkiyat sayfasındaki "İptal et ve iade al" düğmesiyle iptal edilir.'
                    : 'Yola çıkmış bir sevkiyat buradan iptal edilemez. Sorun varsa uyuşmazlık açın ya da destek ekibiyle iletişime geçin.');
            }
            self::closeOpenOrders($locked);

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

    /**
     * Açık ödeme emirlerini kapatır. Ödeme ekranı yeni açılmış bir sipariş varsa (sağlayıcı sonucu daha gelmemiş olabilir) iptal
     * reddedilir; daha eski açık emirler "cancelled" olur. Sonradan yine de "başarılı" bildirimi gelirse PaymentService bunu
     * iptal edilmiş ilana gelen ödeme sayıp iadeye sokar.
     */
    public static function closeOpenOrders(Load $locked): void
    {
        $open = PaymentOrder::query()->where('load_id', $locked->id)->where('purpose', PaymentService::PURPOSE_ESCROW)->whereIn('status', ['created', 'pending'])->get();
        foreach ($open as $order) {
            if ($order->status === 'pending' && $order->updated_at?->gt(now()->subMinutes(self::PAYMENT_GRACE_MINUTES))) {
                throw new RuntimeException('Bu ilan için ödeme işlemi başlatılmış; sağlayıcının sonucu gelmeden iptal edilemez. Ödemeyi tamamlamadıysanız '.self::PAYMENT_GRACE_MINUTES.' dakika sonra yeniden deneyin.');
            }
        }
        PaymentOrder::query()->whereIn('id', $open->pluck('id'))->update(['status' => 'cancelled', 'failed_at' => now()]);
    }

    /**
     * Yönetici (destek) iptali: ödemesi alınmış ama yükü henüz alınmamış sevkiyat iptal edilir, navlun bedeli yük sahibine iade
     * edilir (kuruluş reddederse havuz "iade bekleniyor" olur, finans tamamlar), şoförün işi kapanır, iki taraf bilgilendirilir.
     * Yola çıkmış sevkiyat buradan iptal edilmez (uyuşmazlık süreci).
     */
    public function cancelPaid(Load $load, User $admin, string $reason): bool
    {
        $driverUser = $this->cancelPaidLoad($load, 'Yönetici kararı: '.mb_substr($reason, 0, 900), $admin->id, 'load.cancelled_paid', "İlan #{$load->id} ödeme sonrası yönetici tarafından iptal edildi: {$reason}");
        $refunded = app(PaymentService::class)->refundLoad($load->fresh(), 'Yönetici iptali #'.$load->id.': '.$reason);

        $notifications = app(NotificationService::class);
        if ($owner = $load->cargoOwnerProfile?->user) {
            $notifications->notify($owner, 'Sevkiyat iptal edildi, navlun bedeli iade ediliyor',
                ["#{$load->id} numaralı sevkiyat destek ekibi tarafından iptal edildi. Gerekçe: ".mb_substr($reason, 0, 300),
                    $refunded ? 'Navlun bedeli kartınıza iade edildi; bankanıza göre 1-10 iş günü içinde hesabınızda görünür.' : 'Navlun bedelinin iadesi finans ekibi tarafından tamamlanacak; sonuç size bildirilecek.'],
                route('cargo-owner.loads.index'), 'İlanlarım', 'load');
        }
        if ($driverUser) {
            $notifications->notify($driverUser, 'Sevkiyat iptal edildi',
                ["{$load->pickup_location} → {$load->delivery_location} sevkiyatı destek ekibi tarafından iptal edildi; iş kaydınız kapandı.", 'Gerekçe: '.mb_substr($reason, 0, 300)],
                route('driver.loads.index'), 'İlan havuzuna git', 'load');
        }

        return $refunded;
    }

    /**
     * Yük sahibi, ödemesi alınmış ama yola çıkılmamış sevkiyatı iptal eder (karar 4: yola çıkılmadan önce tam iade). Şoförün işi
     * kapanır, navlun bedeli kartına iade edilir (kuruluş reddederse "iade bekleniyor"), şoför bilgilendirilir.
     */
    public function cancelByOwnerPaid(Load $load, CargoOwnerProfile $owner, ?string $reason = null): bool
    {
        if ($load->cargo_owner_profile_id !== $owner->id) {
            throw new RuntimeException('Bu ilan size ait değil.');
        }
        $reasonText = $reason ? mb_substr(trim($reason), 0, 900) : 'Yük sahibi yola çıkılmadan iptal etti.';
        $driverUser = $this->cancelPaidLoad($load, $reasonText, $owner->user_id, 'load.cancelled_by_owner_paid', "İlan #{$load->id} ödeme sonrası yük sahibi tarafından iptal edildi");
        $refunded = app(PaymentService::class)->refundLoad($load->fresh(), 'Yük sahibi iptali #'.$load->id);

        $notifications = app(NotificationService::class);
        if ($ownerUser = $owner->user) {
            $notifications->notify($ownerUser, $refunded ? 'Sevkiyat iptal edildi, navlun bedeli iade edildi' : 'Sevkiyat iptal edildi, iade başlatıldı',
                ["#{$load->id} numaralı sevkiyatı iptal ettiniz; şoförün işi kapandı.",
                    $refunded ? 'Navlun bedeli kartınıza iade edildi; bankanıza göre 1-10 iş günü içinde hesabınızda görünür.' : 'Navlun bedelinin iadesi finans ekibi tarafından tamamlanacak; sonuç size bildirilecek.'],
                route('cargo-owner.loads.index'), 'İlanlarım', 'load');
        }
        if ($driverUser) {
            $notifications->notify($driverUser, 'Yük sahibi sevkiyatı iptal etti',
                ["{$load->pickup_location} → {$load->delivery_location} sevkiyatı yola çıkılmadan yük sahibi tarafından iptal edildi; iş kaydınız kapandı, navlun yük sahibine iade ediliyor.",
                    $reason ? 'Yük sahibinin açıklaması: '.mb_substr($reason, 0, 300) : 'İlan havuzunda size uygun başka yükler sizi bekliyor.'],
                route('driver.loads.index'), 'İlan havuzuna git', 'load');
        }

        return $refunded;
    }

    /** Ödenmiş, yola çıkılmamış ilanı kilit altında iptal eder (teklifler, sevkiyat, sefer); şoför kullanıcısını döndürür. */
    private function cancelPaidLoad(Load $load, string $reasonText, ?int $actorId, string $logEvent, string $logText): ?User
    {
        $driverUser = null;
        DB::transaction(function () use ($load, $reasonText, $actorId, $logEvent, $logText, &$driverUser): void {
            $locked = Load::query()->lockForUpdate()->findOrFail($load->id);
            if (! $locked->canBeCancelledBeforeTransit()) {
                throw new RuntimeException('Yalnız ödemesi alınmış ve henüz yola çıkmamış sevkiyatlar iade ile iptal edilebilir.');
            }
            $driverUser = $locked->driverProfile?->user;
            $locked->offers()->whereIn('status', ['pending', 'accepted'])->update(['status' => 'rejected', 'responded_at' => now()]);
            $locked->shipment()->update(['status' => Shipment::STATUS_CANCELLED]);
            $locked->update([
                'status' => Load::STATUS_CANCELLED,
                'rejection_reason' => $reasonText,
                'cancelled_at' => now(),
                'visibility' => 'private',
            ]);
            ActivityLog::record($logEvent, $logText, $actorId, $locked);
        });
        app(DriverTripService::class)->closeForLoad($load->id);

        return $driverUser;
    }

    /**
     * "Şoför gelmedi" (zamanlanmış görev): ödenmiş, yola çıkılmamış ilanda yükleme tarihi ayarlı gün kadar geçmişse yük sahibine
     * (iptal + iade düğmesiyle), şoföre ve operasyon ekibine bir kez haber verilir. Karar yük sahibinde ya da yöneticide kalır.
     */
    public function notifyNoShows(): int
    {
        $grace = max(0, Settings::int('no_show_grace_days'));
        $count = 0;
        $notifications = app(NotificationService::class);
        Load::query()->with(['cargoOwnerProfile.user', 'driverProfile.user'])
            ->where('status', Load::STATUS_ASSIGNED)->where('escrow_status', Load::ESCROW_PAID)
            ->whereNotNull('pickup_date')->where('pickup_date', '<', today()->subDays($grace))
            ->whereNull('no_show_notified_at')->orderBy('id')->limit(100)->get()
            ->each(function (Load $load) use (&$count, $notifications): void {
                $load->forceFill(['no_show_notified_at' => now()])->save();
                $count++;
                $route = "{$load->pickup_location} → {$load->delivery_location}";
                if ($owner = $load->cargoOwnerProfile?->user) {
                    $notifications->notify($owner, 'Şoför yükü henüz almadı',
                        ["{$route} ilanında yükleme tarihi ({$load->pickup_date->format('d.m.Y')}) geçti, şoför hâlâ yola çıkmadı.",
                            'Şoförle görüşün; gelmeyecekse sevkiyat sayfasındaki "İptal et ve iade al" düğmesiyle sevkiyatı iptal edip navlun bedelinizi geri alabilirsiniz.'],
                        route('cargo-owner.shipments.show', $load->id), 'Sevkiyatı aç', 'shipment');
                }
                if ($driver = $load->driverProfile?->user) {
                    $notifications->notify($driver, 'Yükleme tarihi geçti, yola çıkmadınız',
                        ["{$route} işinde yükleme tarihi geçti ve hâlâ \"Yola çıktım\" demediniz. Yükü aldıysanız İşlerim sayfasından yola çıktığınızı bildirin; işi yapamayacaksanız vazgeçin ki yük sahibi başka şoför bulsun.",
                            'Yük sahibi sevkiyatı iptal edip iade alabilir; ödeme sonrası vazgeçmeler hesabınızda sayılır.'],
                        route('driver.jobs.show', $load->id), 'İşi aç', 'shipment');
                }
                $notifications->notifyAdmins('manage operations', 'Şoför gelmedi şüphesi',
                    ["İlan #{$load->id} ({$route}): ödeme alındı, yükleme tarihi {$load->pickup_date->format('d.m.Y')} geçti, şoför yola çıkmadı. İki taraf bilgilendirildi; gerekirse Operasyon ekranından \"İptal et ve iade et\"."],
                    route('admin.operations'), 'Operasyon ekranı', 'admin');
            });

        return $count;
    }

    /**
     * Yükleme tarihi geçmiş, hâlâ teklif bekleyen ilanları kapatır (zamanlanmış görev): bekleyen teklifler kapanır,
     * yük sahibine "Tekrar yayınla" bağlantısıyla haber verilir. Havuzda geçmiş tarihli ilan kalmaz.
     */
    public function expireStale(): int
    {
        $grace = max(0, Settings::int('load_expiry_grace_days'));
        $count = 0;
        Load::query()->with(['cargoOwnerProfile.user', 'offers.driverProfile.user'])
            ->where('status', Load::STATUS_ACTIVE)->whereNotNull('pickup_date')
            ->where('pickup_date', '<', today()->subDays($grace))
            ->orderBy('id')->limit(200)->get()
            ->each(function (Load $load) use (&$count): void {
                $pending = $load->offers->where('status', 'pending');
                DB::transaction(function () use ($load): void {
                    $locked = Load::query()->lockForUpdate()->findOrFail($load->id);
                    if ($locked->status !== Load::STATUS_ACTIVE) {
                        return;
                    }
                    $locked->offers()->where('status', 'pending')->update(['status' => 'expired', 'responded_at' => now()]);
                    $locked->update(['status' => Load::STATUS_CANCELLED, 'rejection_reason' => 'Yükleme tarihi geçti; ilan kendiliğinden kapandı.', 'cancelled_at' => now(), 'visibility' => 'private']);
                });
                $count++;
                $notifications = app(NotificationService::class);
                if ($owner = $load->cargoOwnerProfile?->user) {
                    $notifications->notify($owner, 'İlanınızın yükleme tarihi geçti',
                        ["{$load->pickup_location} → {$load->delivery_location} ilanı yükleme tarihi geçtiği için kapandı.", 'Yük hâlâ taşınacaksa ilanı yeni tarihle tek dokunuşla tekrar yayınlayabilirsiniz.'],
                        route('cargo-owner.loads.index'), 'Tekrar yayınla', 'load');
                }
                foreach ($pending as $offer) {
                    if ($driverUser = $offer->driverProfile?->user) {
                        $notifications->notify($driverUser, 'İlan kapandı, teklifiniz düştü',
                            ["{$load->pickup_location} → {$load->delivery_location} ilanı yükleme tarihi geçtiği için kapandı."],
                            route('driver.loads.index'), 'İlan havuzuna git', 'offer', sendMail: false);
                    }
                }
            });
        if ($count > 0) {
            app(LoadStatsService::class)->forget();
        }

        return $count;
    }

    public function eIrsaliyeExists(Load $load): bool
    {
        return $load->e_irsaliye_path && Storage::disk('private')->exists($load->e_irsaliye_path);
    }
}
