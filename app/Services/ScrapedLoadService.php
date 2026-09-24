<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\DriverProfile;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Support\Settings;
use App\Support\TurkishLocations;
use App\Support\VehicleTypes;
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
            'vehicle_any' => (bool) $load->vehicle_any,
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
            'goods_type', 'vehicle_type', 'vehicle_type_source', 'vehicle_any', 'weight', 'price',
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

    public function approve(ScrapedLoad $load, ?int $userId = null, bool $auto = false, bool $incomplete = false): void
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

        $metaAfter = (array) $load->parse_metadata;
        unset($metaAfter['auto_approve_error']);
        $load->update([
            'status' => 'parsed_success',
            'visibility' => 'public',
            'is_incomplete' => $incomplete,
            'completed_by' => $incomplete ? null : ($load->is_incomplete ? ($userId ? 'admin' : $load->completed_by) : $load->completed_by),
            'available_to_free_at' => null, // dış kaynak ilanları yalnız premium üyelere görünür; herkese açılmaz
            'auto_approved_at' => $auto ? now() : null,
            'published_at' => $load->published_at ?? now(),
            // Listede kalma süresi yayından itibaren sayılır; sonra arşivlenir (silinmez, sayaçta kalır)
            'retention_expires_at' => now()->addDays(max(1, Settings::int('scraper_list_days'))),
            'parse_metadata' => $metaAfter,
        ]);
        app(LoadStatsService::class)->forget();

        ActivityLog::record(
            $auto ? ($incomplete ? 'scraped_load.auto_published_incomplete' : 'scraped_load.auto_approved') : 'scraped_load.approved',
            ($auto ? ($incomplete ? 'Dış kaynak ilanı eksik bilgili olarak yayınlandı' : 'Dış kaynak ilanı otomatik yayınlandı') : 'Dış kaynak ilanı yayınlandı')." #{$load->id}",
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
        $meta = (array) ($load->parse_metadata ?? []);
        if (! empty($meta['auto_approve_error']) && empty($meta['admin_edited'])) {
            return 'onay hatası: '.($meta['auto_approve_error']['message'] ?? 'bilinmiyor');
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
        if (Settings::bool('scraper_auto_approve_require_vehicle') && ! $load->vehicle_type && ! $load->vehicle_any) {
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
        if (! empty($meta['ai_conflict']) && empty($meta['admin_edited'])) {
            return 'kural ve yapay zeka farklı il buldu; elle kontrol';
        }
        if (! empty($meta['admin_edited'])) {
            return null; // yönetici düzeltip kaydettiyse karar puanı aranmaz
        }
        $d = $this->decision($load);
        if ($d['wait']) {
            return 'yapay zeka doğrulaması bekleniyor';
        }
        $pct = (int) round($d['score'] * 100);
        if ($d['score'] >= self::pct('scraper_auto_approve_min_confidence')) {
            return null;
        }
        if ($d['score'] <= self::pct('scraper_auto_reject_max_score')) {
            return "karar puanı çok düşük (%{$pct}); otomatik ret";
        }

        return "karar puanı %{$pct} (eşik %".Settings::int('scraper_auto_approve_min_confidence').'); elle kontrol';
    }

    /** Yüzde ayarını 0-1 aralığına çevirir. */
    private static function pct(string $key): float
    {
        return max(0, min(100, Settings::int($key))) / 100;
    }

    /**
     * Birleşik karar puanı (0-1). Üç kaynak birleşir:
     * - kural kanıtı: il çifti (0,55) + telefon (0,10) + açık araç adı / şablon / yönetici (0,15) + tonaj (0,10) + fiyat (0,10)
     *   + yük türü (0,05) + doğrulanmış gönderen şablonu (0,15); en çok 1,0
     * - yapay zeka güveni (baktıysa)
     * - yerel sınıflandırıcı olasılığı (yeterince öğrendiyse)
     * Yapay zeka baktıysa puan = (kural + yapay zeka) / 2; bakmadıysa yerel varsa (kural + yerel) / 2; yoksa kural.
     * "wait": yapay zeka zorunlu, yapılandırılmış ve aday henüz bekleme süresi içindeyse (varsayılan 15 dk) beklenir;
     * süre dolunca yapay zeka beklenmez, kural/yerel puanla karar verilir (eski sürüm 3 saat sonra "elle kontrol"e düşürüyordu).
     *
     * @return array{score: float, rule: float, ai: ?float, local: ?float, wait: bool, basis: string}
     */
    /**
     * Eksik bilgili yayın: kalkış-varış ili ve telefon belli, kural/yapay zeka çelişmiyor, yapay zeka beklenmiyor;
     * karar puanı otomatik ret sınırının üstünde ve "eksik bilgili" üst sınırının altında. Araç, kasa, yük, tonaj,
     * fiyat eksik olabilir; şoför arayıp sorar. Üst sınırın üstündekiler kuyrukta kalır (sistemi eğitir).
     */
    public function incompleteEligible(ScrapedLoad $load, ?string $blocker = null): bool
    {
        if (! Settings::bool('scraper_incomplete_publish')) {
            return false;
        }
        $blocker ??= $this->autoApprovalBlocker($load);
        if ($blocker === null) {
            return false; // normal otomatik yayın
        }
        $soft = in_array($blocker, ['araç tipi yok', 'fiyat yok', 'tonaj yok'], true) || str_starts_with($blocker, 'karar puanı %');
        if (! $soft) {
            return false; // durum, kaynak, rota, il, telefon, çelişki, bekleme: bunlar eksik bilgiyle kapatılamaz
        }
        $d = $this->decision($load);

        return ! $d['wait']
            && $d['score'] > self::pct('scraper_auto_reject_max_score')
            && $d['score'] <= self::pct('scraper_incomplete_max_score')
            && $d['score'] < self::pct('scraper_auto_approve_min_confidence');
    }

    /** Şoför ilan sahibini arayıp öğrendi: araç tipi (ya da "fark etmez") girilince ilan tamamlanır. */
    public function completeByDriver(ScrapedLoad $load, DriverProfile $driver, string $vehicleType): void
    {
        if (! $load->is_incomplete || $load->visibility !== 'public') {
            throw new RuntimeException('Bu ilan tamamlanmayı beklemiyor.');
        }
        if ($vehicleType !== 'any' && ! VehicleTypes::isValid($vehicleType)) {
            throw new RuntimeException('Geçersiz araç tipi.');
        }
        $load->update([
            'vehicle_type' => $vehicleType === 'any' ? null : $vehicleType,
            'vehicle_any' => $vehicleType === 'any',
            'vehicle_type_source' => 'driver',
            'is_incomplete' => false,
            'completed_by' => 'driver',
        ]);
        ActivityLog::record('scraped_load.completed_by_driver', "Dış kaynak ilanı #{$load->id} şoför tarafından tamamlandı (araç: {$vehicleType})", $driver->user_id, $load);
    }

    public function decision(ScrapedLoad $load): array
    {
        $intl = (array) $load->meta('international', []);
        $pickupOk = $load->pickup_province_code || ! empty($intl['pickup']) || TurkishLocations::resolve($load->pickup_location) !== null;
        $deliveryOk = $load->delivery_province_code || ! empty($intl['delivery']) || TurkishLocations::resolve($load->delivery_location) !== null;
        $rule = 0.0;
        if ($pickupOk && $deliveryOk) {
            $rule = 0.55;
            $rule += ($load->encrypted_sender_phone || $load->sender_phone) ? 0.10 : 0.0;
            $rule += in_array($load->vehicle_type_source, ['keyword', 'admin', 'template'], true) ? 0.15 : 0.0;
            $rule += (int) $load->weight > 0 ? 0.10 : 0.0;
            $rule += (float) $load->price > 0 ? 0.10 : 0.0;
            $rule += $load->goods_type ? 0.05 : 0.0;
            $rule += ($load->parsed_by_llm === 'template' || $load->meta('template_id') || ($load->meta('ai')['template_id'] ?? null)) ? 0.15 : 0.0;
            $rule = min(1.0, $rule);
        }
        $ai = $load->ai_status === 'done' && $load->parse_confidence !== null ? max(0.0, min(1.0, (float) $load->parse_confidence)) : null;
        $local = $this->localConfidence($load);

        $parser = app(AiParserService::class);
        $aiActive = Settings::bool('scraper_auto_approve_require_ai') && $parser->isEnabled() && $parser->isConfigured();
        $wait = $aiActive && $load->ai_status === 'pending' && $load->created_at && $load->created_at->gt(now()->subMinutes(max(1, Settings::int('scraper_ai_wait_minutes'))));

        if ($ai !== null) {
            $score = ($rule + $ai) / 2;
            $basis = 'kural + yapay zeka';
        } elseif ($local !== null) {
            $score = ($rule + $local) / 2;
            $basis = 'kural + yerel';
        } else {
            $score = $rule;
            $basis = 'kural';
        }

        return ['score' => round($score, 4), 'rule' => round($rule, 4), 'ai' => $ai, 'local' => $local, 'wait' => $wait, 'basis' => $basis];
    }

    /** Adayı kendiliğinden reddeder (yönetici kararı değildir; sınıflandırıcıya öğretilmez). */
    public function autoReject(ScrapedLoad $load, string $reason): void
    {
        $meta = (array) ($load->parse_metadata ?? []);
        $meta['auto_rejected'] = ['reason' => mb_substr($reason, 0, 200), 'at' => now()->toDateTimeString()];
        $load->update(['status' => 'rejected', 'visibility' => 'private', 'parse_metadata' => $meta]);
        ActivityLog::record('scraped_load.auto_rejected', "Dış kaynak ilanı #{$load->id} kendiliğinden reddedildi: {$reason}", null, $load);
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
    /** Bekleyen adaylar en çok bu kadar gün geriye taranır (kuyruk yaşı ayarı bundan küçüktür). */
    public const AUTO_APPROVE_MAX_AGE_DAYS = 7;

    /** Bir çalıştırmada en çok bu kadar aday yayınlanır; kalan bir sonraki dakikada devam eder. */
    public const AUTO_APPROVE_BATCH = 500;

    /**
     * Her dakika çalışır. Bekleyen TÜM adaylar (eskiden yeniye) taranır; önceden yalnız en eski 200 aday bakılıyordu ve
     * onlar engelliyse (yapay zeka bekliyor vb.) daha yeni, uygun adaylar hiç sıraya gelmiyordu. Onay sırasında hata
     * çıkarsa neden adaya yazılır ve listede "uygun" yerine o neden görünür.
     */
    public function autoApproveDue(): int
    {
        if (! Settings::bool('scraper_auto_approve')) {
            return 0;
        }

        $approved = 0;
        $started = microtime(true);
        ScrapedLoad::query()->with('scraper')
            ->where('visibility', 'private')->where('status', '!=', 'rejected')
            ->where('created_at', '>=', now()->subDays(self::AUTO_APPROVE_MAX_AGE_DAYS))
            ->chunkById(200, function ($loads) use (&$approved, $started): bool {
                foreach ($loads as $load) {
                    if ($approved >= self::AUTO_APPROVE_BATCH || microtime(true) - $started > 50) {
                        return false;
                    }
                    $blocker = $this->autoApprovalBlocker($load);
                    if ($blocker !== null && $this->incompleteEligible($load, $blocker)) {
                        // Rotası ve telefonu belli, puanı ret ile onay arasında: kuyrukta bekletmeden eksik bilgili yayın
                        try {
                            $this->approve($load, null, true, incomplete: true);
                            $approved++;
                        } catch (\Throwable $e) {
                            Log::warning('Eksik bilgili yayın başarısız.', ['scraped_load_id' => $load->id, 'error' => $e->getMessage()]);
                        }

                        continue;
                    }
                    if ($blocker !== null) {
                        if (str_starts_with($blocker, 'karar puanı çok düşük')) {
                            $this->autoReject($load, $blocker);
                        } elseif ($load->created_at && $load->created_at->lt(now()->subHours(max(1, Settings::int('scraper_queue_max_age_hours'))))) {
                            $this->autoReject($load, 'kuyrukta '.Settings::int('scraper_queue_max_age_hours').' saatten uzun bekledi; ilan güncelliğini yitirdi ('.$blocker.')');
                        }

                        continue;
                    }
                    try {
                        $this->approve($load, null, true);
                        $approved++;
                    } catch (\Throwable $e) {
                        $meta = (array) ($load->parse_metadata ?? []);
                        $meta['auto_approve_error'] = ['message' => mb_substr($e->getMessage(), 0, 300), 'at' => now()->toDateTimeString(), 'attempts' => (int) ($meta['auto_approve_error']['attempts'] ?? 0) + 1];
                        $load->forceFill(['parse_metadata' => $meta])->save();
                        Log::warning('Otomatik onay başarısız.', ['scraped_load_id' => $load->id, 'error' => $e->getMessage()]);
                    }
                }

                return true;
            });

        return $approved;
    }
}
