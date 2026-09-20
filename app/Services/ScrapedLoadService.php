<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ScrapedLoad;
use App\Support\Settings;
use App\Support\TurkishLocations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Dış kaynak ilan adaylarının yayın kararları: elle onay, kriterlere göre otomatik onay, ret.
 */
class ScrapedLoadService
{
    public function freeDelayMinutes(): int
    {
        return max(0, Settings::int('scraper_free_delay_minutes'));
    }

    /**
     * Telefon (bildirim iletici) bağlantı anahtarı: panel ayarı → .env SCRAPER_API_TOKEN → yoksa üretilip panele yazılır.
     * Böylece sunucuda dosya düzenlemeden anahtar yönetici ekranından kopyalanır.
     */
    public static function apiToken(): string
    {
        $panel = Settings::string('scraper_api_token');
        if ($panel !== '') {
            return $panel;
        }
        $env = trim((string) config('services.scraper.token', ''));
        if ($env !== '') {
            return $env;
        }
        $generated = bin2hex(random_bytes(24));
        Settings::set('scraper_api_token', $generated);

        return $generated;
    }

    public static function regenerateApiToken(?int $userId = null): string
    {
        $token = bin2hex(random_bytes(24));
        Settings::set('scraper_api_token', $token, $userId);
        ActivityLog::record('scraper.token_regenerated', 'Bildirim iletici bağlantı anahtarı yenilendi', $userId);

        return $token;
    }

    /** MacroDroid'e yapıştırılacak hazır istek gövdesi. */
    public static function phoneRequestBody(): string
    {
        return json_encode([
            'title' => '{not_title}', 'text' => '{notification}', 'ticker' => '{not_ticker}', 'app' => '{not_app_name}', 'token' => self::apiToken(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }

    /** Zamanlayıcının son nabzı (saniye önce); hiç yoksa null. */
    public static function schedulerAgeSeconds(): ?int
    {
        $ts = Cache::get('scheduler.heartbeat');

        return $ts ? max(0, now()->timestamp - (int) $ts) : null;
    }

    /** Adayı kalıcı olarak siler (reddedilenler ve çöp kayıtlar için). */
    public function delete(ScrapedLoad $load, ?int $userId = null): void
    {
        ActivityLog::record('scraped_load.deleted', "Dış kaynak ilanı #{$load->id} silindi", $userId, null);
        $load->forceDelete();
    }

    /** Belirtilen günden eski reddedilenleri kalıcı siler; sayısını döndürür. */
    public function purgeRejected(?int $olderThanDays = null, ?int $userId = null): int
    {
        $days = $olderThanDays ?? max(0, Settings::int('scraper_rejected_retention_days'));
        $count = 0;
        ScrapedLoad::query()->withTrashed()->where('status', 'rejected')
            ->when($days > 0, fn ($q) => $q->where('updated_at', '<', now()->subDays($days)))
            ->orderBy('id')->limit(5000)->get()->each(function (ScrapedLoad $load) use (&$count): void {
                $load->forceDelete();
                $count++;
            });
        if ($count > 0) {
            ActivityLog::record('scraped_load.purged', "Reddedilen {$count} dış kaynak ilanı kalıcı silindi", $userId);
        }

        return $count;
    }

    /** Yapay zeka sırası bekleyen (kota/ağ hatası) adayları çözümler; işlenen sayısını döndürür. */
    public function aiEnrichPending(int $limit = 50): int
    {
        $parser = app(AiParserService::class);
        if (! $parser->isEnabled()) {
            return 0;
        }
        $done = 0;
        ScrapedLoad::query()->where('ai_status', 'pending')->where('visibility', 'private')->where('status', '!=', 'rejected')
            ->orderBy('id')->limit($limit)->get()->each(function (ScrapedLoad $load) use ($parser, &$done): void {
                if ($this->reparseWithAi($load, $parser)) {
                    $done++;
                }
            });

        return $done;
    }

    /**
     * Adayı yapay zeka ile yeniden çözümler ve alanları birleştirir (yönetici düzenlemesi korunur).
     * Başarıda true; kota/ağ hatasında ai_status "pending" kalır ve false döner.
     */
    public function reparseWithAi(ScrapedLoad $load, ?AiParserService $parser = null, bool $force = false): bool
    {
        $parser ??= app(AiParserService::class);
        if (! $parser->isConfigured()) {
            $load->forceFill(['ai_status' => 'failed', 'ai_checked_at' => now()])->save();

            return false;
        }
        $meta = (array) ($load->parse_metadata ?? []);
        if (! empty($meta['admin_edited']) && ! $force) {
            $load->forceFill(['ai_status' => 'skipped', 'ai_checked_at' => now()])->save();

            return false;
        }

        $current = [
            'success' => true,
            'sender_phone' => $load->plainPhone(),
            'pickup_location' => $load->pickup_location,
            'delivery_location' => $load->delivery_location,
            'goods_type' => $load->goods_type,
            'weight' => $load->weight,
            'price' => $load->price !== null ? (float) $load->price : null,
            'vehicle_type' => $load->vehicle_type,
            'vehicle_type_source' => $load->vehicle_type_source,
            'parsed_by_llm' => $load->parsed_by_llm,
        ];
        $ai = $parser->enrich((string) $load->raw_message, $current, true);
        if ($ai['data'] === null) {
            $load->forceFill(['ai_status' => $ai['status'], 'ai_checked_at' => $ai['status'] === 'failed' ? now() : null])->save();

            return false;
        }

        $merged = $parser->merge($current, $ai['data']);
        $std = app(LoadStandardizer::class)->standardize((string) $load->raw_message, $merged);
        $load->forceFill(array_merge(array_intersect_key($std, array_flip([
            'pickup_location', 'pickup_province_code', 'pickup_district', 'pickup_lat', 'pickup_lng',
            'delivery_location', 'delivery_province_code', 'delivery_district', 'delivery_lat', 'delivery_lng',
            'goods_type', 'vehicle_type', 'vehicle_type_source', 'weight', 'price',
        ])), [
            'status' => $load->status === 'rejected' ? 'rejected' : (($std['pickup_province_code'] && $std['delivery_province_code']) ? 'parsed_success' : 'parsed_partial'),
            'parsed_by_llm' => $merged['parsed_by_llm'] ?? $load->parsed_by_llm,
            'parse_confidence' => $ai['data']['confidence'] ?? null,
            'ai_status' => 'done',
            'ai_checked_at' => now(),
            'parse_metadata' => array_merge($meta, $std['metadata'], ['ai' => array_intersect_key($ai['data'], array_flip(['provider', 'model', 'confidence', 'notes', 'pickup_date_text', 'multiple_loads', 'is_load']))]),
        ]))->save();

        return true;
    }

    public function approve(ScrapedLoad $load, ?int $userId = null, bool $auto = false): void
    {
        if ($load->visibility === 'public') {
            throw new RuntimeException('İlan adayı zaten yayında.');
        }
        if ($load->status === 'rejected') {
            throw new RuntimeException('Reddedilmiş aday yayınlanamaz.');
        }
        if (! $load->pickup_location || ! $load->delivery_location) {
            throw new RuntimeException('Kalkış ve varış bilgisi olmayan aday yayınlanamaz.');
        }
        // Yayın öncesi son standartlaştırma: eski kayıtlar ve sonradan iyileşen sözlükler için.
        app(LoadStandardizer::class)->restandardize($load);
        $load->refresh();
        if (! $load->pickup_province_code || ! $load->delivery_province_code) {
            throw new RuntimeException('Kalkış ya da varış ili çözülemedi; ilanı düzenleyip ili seçin.');
        }

        $load->update([
            'status' => 'parsed_success',
            'visibility' => 'public',
            'available_to_free_at' => now()->addMinutes($this->freeDelayMinutes()),
            'auto_approved_at' => $auto ? now() : null,
        ]);

        ActivityLog::record(
            $auto ? 'scraped_load.auto_approved' : 'scraped_load.approved',
            ($auto ? 'Dış kaynak ilanı otomatik yayınlandı' : 'Dış kaynak ilanı yayınlandı')." #{$load->id}",
            $userId,
            $load
        );
    }

    public function reject(ScrapedLoad $load, ?int $userId = null): void
    {
        $load->update(['status' => 'rejected', 'visibility' => 'private']);
        ActivityLog::record('scraped_load.rejected', "Dış kaynak ilanı #{$load->id} reddedildi", $userId, $load);
    }

    /** Otomatik onay kriterlerini sağlamıyorsa nedenini, sağlıyorsa null döndürür. */
    public function autoApprovalBlocker(ScrapedLoad $load): ?string
    {
        if ($load->visibility === 'public' || $load->status === 'rejected') {
            return 'durum';
        }
        if (! $load->scraper || ! $load->scraper->is_active) {
            return 'kaynak pasif';
        }
        if (! $load->pickup_location || ! $load->delivery_location) {
            return 'rota eksik';
        }
        // İl kodu kayıtta yoksa kataloğa bakılır (eski kayıtlar onay sırasında standartlaştırılır).
        $pickupOk = $load->pickup_province_code || TurkishLocations::resolve($load->pickup_location) !== null;
        $deliveryOk = $load->delivery_province_code || TurkishLocations::resolve($load->delivery_location) !== null;
        if (! $pickupOk || ! $deliveryOk) {
            return 'il çözülemedi';
        }
        if (Settings::bool('scraper_auto_approve_require_vehicle') && ! $load->vehicle_type) {
            return 'araç tipi yok';
        }
        if (! $load->encrypted_sender_phone && ! $load->sender_phone) {
            return 'telefon yok';
        }
        if (Settings::bool('scraper_auto_approve_require_price') && (float) $load->price <= 0) {
            return 'fiyat yok';
        }
        if (Settings::bool('scraper_auto_approve_require_weight') && (int) $load->weight <= 0) {
            return 'tonaj yok';
        }

        return null;
    }

    /**
     * Saklama süresi dolan adayları havuzdan kaldırır (yumuşak silme). Yayındakiler de dahil:
     * 30 günden eski bir WhatsApp ilanı artık güncel değildir. Arşiv kaydı ActivityLog'a düşer.
     */
    public function purgeExpired(): int
    {
        $count = 0;
        ScrapedLoad::query()->whereNotNull('retention_expires_at')->where('retention_expires_at', '<', now())
            ->orderBy('id')->limit(2000)->get()
            ->each(function (ScrapedLoad $load) use (&$count): void {
                $load->update(['visibility' => 'private']);
                $load->delete();
                $count++;
            });
        if ($count > 0) {
            ActivityLog::record('scraped_load.purged', "Saklama süresi dolan {$count} dış kaynak ilanı arşivlendi", null);
        }

        return $count;
    }

    /** Ayar açıksa bekleyen adayları tarar; kriterleri sağlayanları yayınlar ve sayısını döndürür. */
    public function autoApproveDue(): int
    {
        if (! Settings::bool('scraper_auto_approve')) {
            return 0;
        }

        $approved = 0;
        ScrapedLoad::query()->with('scraper')
            ->where('visibility', 'private')->where('status', '!=', 'rejected')
            ->where('created_at', '>=', now()->subDays(3))
            ->orderBy('id')->limit(200)->get()
            ->each(function (ScrapedLoad $load) use (&$approved): void {
                if ($this->autoApprovalBlocker($load) !== null) {
                    return;
                }
                try {
                    $this->approve($load, null, true);
                    $approved++;
                } catch (\Throwable $e) {
                    Log::warning('Otomatik onay başarısız.', ['scraped_load_id' => $load->id, 'error' => $e->getMessage()]);
                }
            });

        return $approved;
    }
}
