<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\CargoOwnerProfile;
use App\Models\Load;
use App\Models\PaymentOrder;
use App\Models\Shipment;
use App\Models\User;
use App\Support\BodyTypes;
use App\Support\Phone;
use App\Support\Settings;
use App\Support\TurkishLocations;
use App\Support\UploadName;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
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

        $load = DB::transaction(function () use ($owner, $data, $eIrsaliyeFile): Load {
            $load = Load::create([
                'cargo_owner_profile_id' => $owner->id,
                'source_type' => 'internal',
                'visibility' => 'public',
                'currency' => 'TRY',
                'status' => Load::STATUS_ACTIVE,
                'pickup_date' => $data['pickup_date'],
                'delivery_date' => $data['delivery_date'] ?? null,
            ] + self::routeAttributes($data) + self::cargoAttributes($data) + self::privateAttributes($data) + [
                'price' => round((float) $data['price'], 2),
                'e_irsaliye_no' => $data['e_irsaliye_no'] ?? null,
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

    /**
     * Rota alanları: il/ilçe seçiciden kod geldiyse (`pickup_province_code`, `pickup_district`) herkese açık etiket
     * "İl İlçe" olur ve koordinat katalogdan dolar; kod yoksa (eski serbest metin, API) metin çözümlenir.
     * Açıkça verilen koordinat korunur.
     */
    public static function routeAttributes(array $data): array
    {
        $out = [];
        foreach (['pickup', 'delivery'] as $side) {
            $code = isset($data[$side.'_province_code']) && $data[$side.'_province_code'] !== '' ? (int) $data[$side.'_province_code'] : null;
            $province = $code ? TurkishLocations::province($code) : null;
            if ($province) {
                $district = trim((string) ($data[$side.'_district'] ?? ''));
                $district = $district !== '' && in_array($district, TurkishLocations::districtsOf($code), true) ? $district : null;
                $geo = ($district ? TurkishLocations::resolveCatalog($province['name'].' '.$district) : null)
                    ?? ['lat' => $province['lat'], 'lng' => $province['lng']];
                $out[$side.'_location'] = TurkishLocations::label(['province' => $province['name'], 'district' => $district]);
                $out[$side.'_province_code'] = $code;
                $out[$side.'_district'] = $district;
            } else {
                $text = trim((string) ($data[$side.'_location'] ?? ''));
                $geo = TurkishLocations::resolve($text);
                $out[$side.'_location'] = $text;
                $out[$side.'_province_code'] = $geo['province_code'] ?? null;
                $out[$side.'_district'] = $geo['district'] ?? null;
            }
            $out[$side.'_lat'] = $data[$side.'_lat'] ?? $geo['lat'] ?? null;
            $out[$side.'_lng'] = $data[$side.'_lng'] ?? $geo['lng'] ?? null;
        }

        return $out;
    }

    /** Yük alanları (araç, kasa, biçim, cins, ağırlık, hacim). */
    public static function cargoAttributes(array $data): array
    {
        return [
            'vehicle_type' => $data['vehicle_type'],
            'body_types' => ($bodies = BodyTypes::clean($data['body_types'] ?? [])) !== [] ? $bodies : null,
            'load_kind' => in_array($data['load_kind'] ?? null, ['komple', 'parca'], true) ? $data['load_kind'] : null,
            'goods_type' => trim((string) $data['goods_type']),
            'weight' => isset($data['weight']) && $data['weight'] !== '' ? (int) $data['weight'] : null,
            'volume' => isset($data['volume']) && $data['volume'] !== '' ? (int) $data['volume'] : null,
        ];
    }

    /** Yalnız yük sahibi, ödemesi alınmış şoför ve yöneticinin gördüğü alanlar: açık adresler, yükleme yetkilisi, şoföre not. */
    public static function privateAttributes(array $data): array
    {
        $text = fn (string $key, int $max): ?string => isset($data[$key]) && trim((string) $data[$key]) !== '' ? mb_substr(trim((string) $data[$key]), 0, $max) : null;

        return [
            'pickup_address_private' => $text('pickup_address_private', 1000),
            'delivery_address_private' => $text('delivery_address_private', 1000),
            'pickup_contact_name' => $text('pickup_contact_name', 120),
            'pickup_contact_phone' => Phone::normalizeContact($data['pickup_contact_phone'] ?? null),
            'notes' => $text('notes', Load::NOTES_MAX),
        ];
    }

    /**
     * Yük sahibi teklif bekleyen ilanını düzenler. Bekleyen teklif yoksa her alan; teklif varsa yalnız tarihler, açık adresler,
     * yükleme yetkilisi ve not değişir (şoförler teklifi rota/araç/bedele göre verdi). Durum ve görünürlük değişmez.
     *
     * @return array<int, string> değişen alan adları
     */
    public function update(Load $load, CargoOwnerProfile $owner, array $data): array
    {
        if ($owner->is_staff_view) {
            throw new RuntimeException('Yönetici görünümünde işlem yapılamaz.');
        }
        $changed = [];
        DB::transaction(function () use ($load, $owner, $data, &$changed): void {
            $locked = Load::query()->lockForUpdate()->findOrFail($load->id);
            if ($locked->cargo_owner_profile_id !== $owner->id) {
                throw new RuntimeException('Bu ilan size ait değil.');
            }
            if (! $locked->isEditableByOwner()) {
                throw new RuntimeException('Yalnız teklif bekleyen ilan düzenlenebilir.');
            }
            $restricted = $locked->offers()->where('status', 'pending')->exists();

            $attributes = self::privateAttributes($data);
            if (array_key_exists('pickup_date', $data)) {
                $attributes['pickup_date'] = $data['pickup_date'];
            }
            if (array_key_exists('delivery_date', $data)) {
                $attributes['delivery_date'] = $data['delivery_date'];
            }
            if (! $restricted) {
                $minPrice = Settings::float('min_load_price');
                if (array_key_exists('price', $data) && (float) $data['price'] < $minPrice) {
                    throw new RuntimeException('Navlun bedeli en az '.number_format($minPrice, 0, ',', '.').' ₺ olmalıdır.');
                }
                $attributes += self::routeAttributes($data + $locked->only(['pickup_location', 'delivery_location']))
                    + self::cargoAttributes($data + $locked->only(['vehicle_type', 'goods_type']));
                if (array_key_exists('price', $data)) {
                    $attributes['price'] = round((float) $data['price'], 2);
                }
                if (array_key_exists('e_irsaliye_no', $data)) {
                    $attributes['e_irsaliye_no'] = $data['e_irsaliye_no'] !== null && trim((string) $data['e_irsaliye_no']) !== '' ? trim((string) $data['e_irsaliye_no']) : null;
                }
            }
            $locked->fill($attributes);
            $changed = array_keys($locked->getDirty());
            if ($changed === []) {
                return;
            }
            $locked->save();
            ActivityLog::record('load.updated', "İlan #{$locked->id} yük sahibi tarafından düzenlendi: ".implode(', ', $changed).($restricted ? ' (teklif varken sınırlı düzenleme)' : ''), $owner->user_id, $locked, ['fields' => $changed]);
        });
        if ($changed !== []) {
            app(LoadStatsService::class)->forget();
        }

        return $changed;
    }

    /** Tamamlanmış veya iptal edilmiş bir ilanı yeni tarihle yeniden yayınlar (tarih verilmezse yarın). */
    public function repeat(Load $source, CargoOwnerProfile $owner, ?Carbon $pickupDate = null): Load
    {
        if ($source->cargo_owner_profile_id !== $owner->id) {
            throw new RuntimeException('Bu ilan size ait değil.');
        }
        $pickupDate = ($pickupDate ?? now()->addDay())->copy()->startOfDay();
        if ($pickupDate->lt(today())) {
            throw new RuntimeException('Yükleme tarihi bugünden önce olamaz.');
        }

        // Kasa tipi, yük biçimi, il/ilçe kodları, açık adres ve not da taşınır; aksi halde "tenteli / 13.60" şartı kaybolup yanlış şoförlere bildirim giderdi.
        $data = $source->only(['pickup_location', 'delivery_location', 'pickup_province_code', 'pickup_district', 'delivery_province_code', 'delivery_district',
            'pickup_lat', 'pickup_lng', 'delivery_lat', 'delivery_lng', 'vehicle_type', 'goods_type', 'weight', 'volume', 'price', 'body_types', 'load_kind',
            'pickup_address_private', 'delivery_address_private', 'pickup_contact_name', 'pickup_contact_phone', 'notes']);
        $data['pickup_date'] = $pickupDate;
        $data['delivery_date'] = $source->delivery_date && $source->pickup_date
            ? $pickupDate->copy()->addDays(max(0, (int) $source->pickup_date->diffInDays($source->delivery_date)))->endOfDay()
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
        }, 3); // eşzamanlı işlemde kilitlenme olursa 3 kez denenir
        app(DriverTripService::class)->closeForLoad($load->id);

        return $driverUser;
    }

    /**
     * "Şoför gelmedi" (zamanlanmış görev): ödenmiş, yola çıkılmamış ilanda yükleme tarihi ayarlı gün kadar geçmişse yük sahibine
     * (iptal + iade düğmesiyle), şoföre ve operasyon ekibine bir kez haber verilir. Karar yük sahibinde ya da yöneticide kalır.
     */
    /**
     * Yolda takılan sevkiyat (2026-10-06): teslim (yoksa yükleme) tarihi + bekleme süresi geçmiş, hâlâ "teslim ettim" denmemiş
     * ilanlarda şoföre kanıt yüklemesi, yük sahibine durumu ve uyuşmazlık yolunu, operasyona uyarıyı bir kez bildirir.
     */
    public function notifyOverdueTransit(): int
    {
        $grace = max(0, Settings::int('transit_overdue_grace_days'));
        $count = 0;
        $notifications = app(NotificationService::class);
        Load::query()->with(['cargoOwnerProfile.user', 'driverProfile.user'])
            ->where('status', Load::STATUS_ON_THE_WAY)
            ->whereNull('transit_overdue_notified_at')
            ->where(fn ($q) => $q->where('delivery_date', '<', today()->subDays($grace))
                ->orWhere(fn ($w) => $w->whereNull('delivery_date')->where('pickup_date', '<', today()->subDays($grace + 2))))
            ->orderBy('id')->limit(100)->get()
            ->each(function (Load $load) use (&$count, $notifications): void {
                $load->forceFill(['transit_overdue_notified_at' => now()])->save();
                $count++;
                $route = "{$load->pickup_location} → {$load->delivery_location}";
                $due = $load->delivery_date?->format('d.m.Y') ?? $load->pickup_date?->format('d.m.Y');
                if ($driver = $load->driverProfile?->user) {
                    $notifications->notify($driver, 'Teslimat bildirimi bekleniyor',
                        ["{$route} işinde teslim tarihi ({$due}) geçti ve henüz \"Teslim ettim\" demediniz. Yükü teslim ettiyseniz teslimat kanıtını yükleyin; navlun ödemeniz yük sahibinin onayıyla başlar.",
                            'Yolda bir sorun varsa yük sahibiyle görüşün; gecikme uzarsa yük sahibi uyuşmazlık açabilir.'],
                        route('driver.jobs.show', $load->id), 'İşi aç', 'shipment');
                }
                if ($owner = $load->cargoOwnerProfile?->user) {
                    $notifications->notify($owner, 'Sevkiyat teslim tarihini geçti',
                        ["{$route} sevkiyatında teslim tarihi ({$due}) geçti, şoför henüz teslimat bildirmedi. Navlun bedeliniz ödeme kuruluşunda bekliyor.",
                            'Şoförle görüşün; yük teslim edilmediyse sevkiyat sayfasından uyuşmazlık açabilirsiniz, karar verilince bedeliniz iade edilir.'],
                        route('cargo-owner.shipments.show', $load->id), 'Sevkiyatı aç', 'shipment');
                }
                $notifications->notifyAdmins('manage operations', 'Yolda takılan sevkiyat',
                    ["İlan #{$load->id} ({$route}): teslim tarihi {$due} geçti, teslimat bildirilmedi. İki taraf bilgilendirildi."],
                    route('admin.operations'), 'Operasyon ekranı', 'admin');
            });

        return $count;
    }

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
