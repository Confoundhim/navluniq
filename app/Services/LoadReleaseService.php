<?php

namespace App\Services;

use App\Models\DriverProfile;
use App\Models\Load;
use App\Support\BodyTypes;
use App\Support\Settings;
use App\Support\VehicleTypes;
use Illuminate\Support\Facades\Log;

/**
 * Sistem ilanlarında premium önceliği: ilan yayınlanınca premium şoförlere anında bildirim gider;
 * bekleme süresi dolunca ilan herkese açılır, ücretsiz şoförlere bildirim gider ve Telegram kanalına düşer.
 * Dış kaynak ilanlar Telegram'a gönderilmez.
 */
class LoadReleaseService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly TelegramPublisher $telegram,
    ) {}

    /** Ücretsiz üyelere açılma gecikmesi (dakika); dış kaynak ilanlarla aynı ayar. */
    public function delayMinutes(): int
    {
        return max(0, Settings::int('scraper_free_delay_minutes'));
    }

    /** Yayın anı: premium şoförlere bildirim; gecikme sıfırsa doğrudan herkese açılır. */
    public function onPublished(Load $load): void
    {
        if ($load->isAvailableToFree()) {
            $this->release($load);

            return;
        }
        $this->notifyDrivers($load, premium: true);
    }

    /** Süresi dolan ilanları herkese açar; işlenen sayısını döndürür (her dakika çalışır). */
    public function releaseDue(): int
    {
        $count = 0;
        Load::query()
            ->where('status', Load::STATUS_ACTIVE)->where('visibility', 'public')
            ->whereNull('released_at')
            ->where(fn ($q) => $q->whereNull('available_to_free_at')->orWhere('available_to_free_at', '<=', now()))
            ->where('published_at', '>=', now()->subDays(3))
            ->orderBy('id')->limit(50)->get()
            ->each(function (Load $load) use (&$count): void {
                $this->release($load);
                $count++;
            });

        return $count;
    }

    private function release(Load $load): void
    {
        $load->forceFill(['released_at' => now(), 'available_to_free_at' => $load->available_to_free_at ?? now()])->save();
        $this->notifyDrivers($load, premium: false);
        $this->postToTelegram($load);
    }

    /** Aracı bu yükü taşıyabilen, belgesi onaylı şoförlere uygulama içi bildirim. */
    private function notifyDrivers(Load $load, bool $premium): int
    {
        $sent = 0;
        DriverProfile::query()->with(['user', 'vehicles' => fn ($q) => $q->where('is_active', true)])
            ->where('kyc_status', 'approved')
            ->when($premium, fn ($q) => $q->where('premium_until', '>', now()))
            ->when(! $premium, fn ($q) => $q->where(fn ($w) => $w->whereNull('premium_until')->orWhere('premium_until', '<=', now())))
            ->where('user_id', '!=', $load->cargoOwnerProfile?->user_id ?? 0)
            ->orderBy('id')->chunk(200, function ($profiles) use ($load, $premium, &$sent): void {
                foreach ($profiles as $profile) {
                    if (! $profile->user || ! $this->vehicleFits($profile, $load)) {
                        continue;
                    }
                    // E-posta yalnız premium şoföre ve tercihinde açıksa (Profil → Bildirim tercihleri / Premium sayfası).
                    $mail = $premium && self::wantsLoadMail($profile);
                    $title = ($premium ? '⭐ Erken erişim: ' : 'Yeni ilan: ').$load->pickup_location.' → '.$load->delivery_location;
                    $lines = array_values(array_filter([
                        $load->goods_type.($load->weight ? ' · '.number_format((int) $load->weight / 1000, 1, ',', '.').' ton' : '').' · '.implode(' · ', array_filter([VehicleTypes::label($load->vehicle_type), $load->bodyLabel(), $load->loadKindLabel()])),
                        (float) $load->price > 0 ? 'Navlun: '.number_format((float) $load->price, 0, ',', '.').' ₺' : null,
                        $load->pickup_date ? 'Yükleme: '.$load->pickup_date->format('d.m.Y') : null,
                        $premium ? 'Premium üyelere '.$this->delayMinutes().' dakika önce açıldı; teklifinizi şimdi verin.' : null,
                        $mail ? self::MAIL_OPT_OUT_LINE : null,
                    ]));
                    $this->notifications->notify($profile->user, $title, $lines, route('driver.loads.index', ['ilan' => $load->id]), 'İlanı gör', 'load', sendMail: $mail);
                    $sent++;
                }
            });

        return $sent;
    }

    public const MAIL_OPT_OUT_LINE = 'Bu e-posta premium üyeliğinizin bir parçasıdır; kontrol sizde: şoför panelinizde Premium sayfasından veya Profil → Bildirim tercihleri\'nden yeni ilan e-postalarını istediğiniz zaman kapatıp yeniden açabilirsiniz. Uygulama içi bildirimler devam eder.';

    /** Şoför yeni ilan e-postası istiyor mu? (varsayılan açık) */
    public static function wantsLoadMail(DriverProfile $profile): bool
    {
        return (bool) (($profile->preferences ?? [])['notify_new_loads'] ?? true);
    }

    private function vehicleFits(DriverProfile $profile, Load $load): bool
    {
        $vehicle = $profile->vehicles->first();
        if (! $vehicle || ! $load->vehicle_type) {
            return true; // araç bilgisi yoksa ilanı gizleme
        }

        return VehicleTypes::canCarry((string) $vehicle->vehicle_type, (string) $load->vehicle_type)
            && BodyTypes::vehicleFits($vehicle->body_type, $vehicle->trailer_length, $load->body_types);
    }

    private function postToTelegram(Load $load): void
    {
        if (! $this->telegram->isConfigured() || $load->telegram_posted_at || $load->telegram_attempts >= TelegramPublisher::MAX_ATTEMPTS) {
            return;
        }
        $load->increment('telegram_attempts');
        try {
            $this->telegram->send($this->telegram->messageForLoad($load));
            $load->forceFill(['telegram_posted_at' => now()])->save();
        } catch (\Throwable $e) {
            Log::warning('Telegram paylaşımı başarısız.', ['load_id' => $load->id, 'error' => $e->getMessage()]);
        }
    }

    /** Telegram'a gönderilemeyen (geçici hata) sistem ilanlarını yeniden dener. */
    public function retryTelegram(): int
    {
        if (! $this->telegram->isConfigured()) {
            return 0;
        }
        $sent = 0;
        Load::query()->where('status', Load::STATUS_ACTIVE)->where('visibility', 'public')
            ->whereNotNull('released_at')->whereNull('telegram_posted_at')
            ->where('telegram_attempts', '>', 0)->where('telegram_attempts', '<', TelegramPublisher::MAX_ATTEMPTS)
            ->where('published_at', '>=', now()->subDays(3))
            ->orderBy('id')->limit(20)->get()
            ->each(function (Load $load) use (&$sent): void {
                $this->postToTelegram($load);
                if ($load->fresh()->telegram_posted_at) {
                    $sent++;
                }
            });

        return $sent;
    }
}
