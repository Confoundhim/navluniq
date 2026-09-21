<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Support\Settings;
use App\Support\TurkishLocations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
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

    // ---- Telefon (bildirim iletici) bağlantı bilgileri ----

    /** MacroDroid'e yapıştırılacak hazır istek gövdesi. */
    /** MacroDroid "Parametreler" (form alanları) kurulumu: metindeki tırnak/satır sonu JSON'u bozamaz. Önerilen yol. */
    public static function phoneRequestParams(): array
    {
        return ['title' => '{not_title}', 'text' => '{notification}', 'ticker' => '{not_ticker}', 'app' => '{not_app_name}', 'token' => self::apiToken()];
    }

    /** Telefonun tarayıcısından açılınca Canlı akışa "Bağlantı sınaması" düşüren adres. */
    public static function pingUrl(): string
    {
        return route('api.notification.ping', ['token' => self::apiToken()]);
    }

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
    /** Kaynağı ve ondan gelen tüm adayları/ilanları kalıcı siler: grup hiç okunmamış gibi olur. */
    public function purgeSource(Scraper $source, ?int $userId = null): int
    {
        $count = 0;
        ScrapedLoad::query()->withTrashed()->where('scraper_id', $source->id)->orderBy('id')->chunkById(500, function ($loads) use (&$count): void {
            foreach ($loads as $load) {
                $load->forceDelete();
                $count++;
            }
        });
        ActivityLog::record('scraper.purged', "Kaynak kalıcı silindi: {$source->name} ({$count} aday ile)", $userId);
        $source->forceDelete();

        return $count;
    }

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

        // Mesajda birden çok ilan varsa bu adayın numarasına ve rotasına uyan ilan alınır (yoksa ilki).
        $ai['data'] = AiParserService::pickAd($ai['data'], $current['sender_phone'], $load->pickup_location, $load->delivery_location);
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
            'parse_metadata' => array_merge(array_diff_key($meta, ['ai_conflict' => 1]), $std['metadata'], array_filter([
                'ai' => array_intersect_key($ai['data'], array_flip(['provider', 'model', 'confidence', 'notes', 'pickup_date_text', 'multiple_loads', 'is_load', 'ad_index', 'ad_count'])),
                'ai_conflict' => $merged['ai_conflict'] ?? null,
                // Yapay zeka bu ilanda başka numara da bulduysa yedek numaralara eklenir (şifreli).
                'extra_phones_enc' => ($extra = array_values(array_diff(array_unique(array_merge($load->extraPhones(), (array) ($merged['phones'] ?? []))), [$current['sender_phone']]))) !== [] ? array_map(fn (string $p) => Crypt::encryptString($p), $extra) : null,
                'phone_count' => count($extra) > 0 ? count($extra) + 1 : null,
            ])),
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
        // Yayın anında son tekrar denetimi: aynı metin ya da aynı numara+rota zaten yayındaysa ikinci ilan açılmaz.
        if ($twin = $this->publishedDuplicateOf($load)) {
            $this->markDuplicate($load, $twin, $userId);
            throw new RuntimeException("Aynı ilan zaten yayında (#{$twin->id}); bu aday tekrar olarak işaretlendi.");
        }
        // Yayın öncesi son standartlaştırma: eski kayıtlar ve sonradan iyileşen sözlükler için.
        app(LoadStandardizer::class)->restandardize($load);
        $load->refresh();
        $intl = (array) $load->meta('international', []);
        if ((! $load->pickup_province_code && empty($intl['pickup'])) || (! $load->delivery_province_code && empty($intl['delivery']))) {
            throw new RuntimeException('Kalkış ya da varış ili çözülemedi; ilanı düzenleyip ili seçin.');
        }

        $load->update([
            'status' => 'parsed_success',
            'visibility' => 'public',
            'available_to_free_at' => null, // dış kaynak ilanları yalnız premium üyelere görünür; herkese açılmaz
            'auto_approved_at' => $auto ? now() : null,
        ]);

        ActivityLog::record(
            $auto ? 'scraped_load.auto_approved' : 'scraped_load.approved',
            ($auto ? 'Dış kaynak ilanı otomatik yayınlandı' : 'Dış kaynak ilanı yayınlandı')." #{$load->id}",
            $userId,
            $load
        );
        app(LearningService::class)->onApproved($load, byAdmin: ! $auto);
    }

    public function reject(ScrapedLoad $load, ?int $userId = null): void
    {
        $load->update(['status' => 'rejected', 'visibility' => 'private']);
        ActivityLog::record('scraped_load.rejected', "Dış kaynak ilanı #{$load->id} reddedildi", $userId, $load);
        if ($userId !== null) {
            app(LearningService::class)->onRejected($load); // yalnız insan kararı eğitir
        }
    }

    /**
     * Bu adayla aynı metni (7 gün) ya da aynı numara + il çiftini (48 saat) taşıyan, yayındaki başka ilan.
     */
    public function publishedDuplicateOf(ScrapedLoad $load): ?ScrapedLoad
    {
        $query = ScrapedLoad::query()->where('visibility', 'public')->whereKeyNot($load->id);

        return $query->where(function ($q) use ($load): void {
            $q->whereRaw('1 = 0');
            if ($load->normalized_hash) {
                $q->orWhere(fn ($w) => $w->where('normalized_hash', $load->normalized_hash)
                    ->where('created_at', '>=', now()->subDays(LoadIntakeService::TEXT_DEDUPE_DAYS)));
            }
            if ($load->route_key) {
                $q->orWhere(fn ($w) => $w->where('route_key', $load->route_key)
                    ->where('created_at', '>=', now()->subHours(LoadIntakeService::ROUTE_DEDUPE_HOURS)));
            }
        })->orderBy('id')->first();
    }

    /** Adayı tekrar olarak reddeder; görüldüğü kaynak yayındaki ilanın sayacına eklenir. */
    private function markDuplicate(ScrapedLoad $load, ScrapedLoad $twin, ?int $userId): void
    {
        $sources = array_values(array_unique(array_filter(array_merge(
            (array) ($twin->seen_sources ?? []), [$twin->scraper?->name], (array) ($load->seen_sources ?? []), [$load->scraper?->name]
        ))));
        $twin->forceFill(['seen_sources' => $sources, 'duplicate_count' => max(1, count($sources))])->save();
        $load->update([
            'status' => 'rejected', 'visibility' => 'private',
            'parse_metadata' => array_merge((array) $load->parse_metadata, ['duplicate_of' => $twin->id]),
        ]);
        ActivityLog::record('scraped_load.duplicate', "Dış kaynak ilanı #{$load->id} yayındaki #{$twin->id} ilanının tekrarı; reddedildi", $userId, $load);
    }

    /** Otomatik onay kriterlerini sağlamıyorsa nedenini, sağlıyorsa null döndürür. */
    public function autoApprovalBlocker(ScrapedLoad $load): ?string
    {
        if ($load->visibility === 'public' || $load->status === 'rejected') {
            return 'durum';
        }
        if ($twin = $this->publishedDuplicateOf($load)) {
            $this->markDuplicate($load, $twin, null);

            return "tekrar (#{$twin->id} yayında)";
        }
        if (! $load->scraper || ! $load->scraper->is_active) {
            return 'kaynak pasif';
        }
        if (! $load->pickup_location || ! $load->delivery_location) {
            return 'rota eksik';
        }
        // İl kodu kayıtta yoksa kataloğa bakılır (eski kayıtlar onay sırasında standartlaştırılır).
        $intl = (array) $load->meta('international', []);
        $pickupOk = $load->pickup_province_code || ! empty($intl['pickup']) || TurkishLocations::resolve($load->pickup_location) !== null;
        $deliveryOk = $load->delivery_province_code || ! empty($intl['delivery']) || TurkishLocations::resolve($load->delivery_location) !== null;
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
        $meta = (array) ($load->parse_metadata ?? []);
        if (! empty($meta['ai_conflict']) && empty($meta['admin_edited'])) {
            return 'kural ve yapay zeka farklı il buldu; elle kontrol';
        }
        if (empty($meta['admin_edited'])) {
            if ($blocker = $this->aiApprovalBlocker($load)) {
                return $blocker;
            }
        }

        return null;
    }

    /**
     * Yapay zeka doğrulaması: ayar açıkken ve yapay zeka kullanılabilirken aday ancak yapay zeka bakıp
     * yeterli güven verdiyse kendiliğinden yayınlanır. Yapay zeka kapalı/anahtarsızsa uygulanmaz.
     */
    private function aiApprovalBlocker(ScrapedLoad $load): ?string
    {
        $parser = app(AiParserService::class);
        $minConfidence = max(0, min(100, Settings::int('scraper_auto_approve_min_confidence'))) / 100;
        if (! Settings::bool('scraper_auto_approve_require_ai') || ! $parser->isEnabled() || ! $parser->isConfigured()) {
            // Zorunlu değilse yine de belirgin düşük güven elle kontrole düşer.
            return $load->ai_status === 'done' && $load->parse_confidence !== null && (float) $load->parse_confidence < 0.5 ? 'yapay zeka güveni düşük; elle kontrol' : null;
        }
        if ($load->ai_status !== 'done') {
            // Dış yapay zeka ulaşılamadı: yerel sınıflandırıcı yeterince öğrendiyse ve güveni eşiğin üstündeyse o karar verir.
            $local = $this->localConfidence($load);
            if ($local !== null && $local >= max(0, min(100, Settings::int('scraper_local_min_confidence'))) / 100) {
                return null;
            }
        }
        if ($load->ai_status === 'pending') {
            return $load->created_at && $load->created_at->lt(now()->subHours(3)) ? 'yapay zeka ulaşılamadı; elle kontrol' : 'yapay zeka doğrulaması bekleniyor';
        }
        if ($load->ai_status === 'failed' || ($load->ai_status !== 'done' && $parser->mode() === 'always')) {
            return 'yapay zeka doğrulayamadı; elle kontrol';
        }
        if ($load->ai_status !== 'done') {
            // Kural yeterli sayıldı, yapay zeka çağrılmadı ("Kural eksik bırakınca" kipi): yerel sınıflandırıcı öğrenmişse
            // onun kararı gerekir; öğrenmemişse kural kanıtı güçlü olmalı (il çifti + araç adı ya da tonaj ya da fiyat).
            $local = $this->localConfidence($load);
            if ($local !== null) {
                return 'yerel güven düşük (%'.(int) round($local * 100).'); elle kontrol';
            }
            $strong = $load->pickup_province_code && $load->delivery_province_code
                && (in_array($load->vehicle_type_source, ['keyword', 'admin'], true) || (int) $load->weight > 0 || (float) $load->price > 0);
            if (! $strong) {
                return 'kural kanıtı zayıf (araç adı, tonaj ya da fiyat yok); elle kontrol';
            }
        }
        if ($load->ai_status === 'done' && ($load->parse_confidence === null || (float) $load->parse_confidence < $minConfidence)) {
            return 'yapay zeka güveni düşük (%'.(int) round((float) $load->parse_confidence * 100).'); elle kontrol';
        }

        return null;
    }

    /** Yerel sınıflandırıcının bu aday için olasılığı (alımda yazılmışsa o, yoksa şimdi hesaplanır). */
    public function localConfidence(ScrapedLoad $load): ?float
    {
        if (! Settings::bool('scraper_local_enabled')) {
            return null;
        }
        $stored = $load->meta('local_confidence');
        if (is_numeric($stored)) {
            return (float) $stored;
        }

        return app(LocalClassifier::class)->score((string) $load->raw_message);
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
