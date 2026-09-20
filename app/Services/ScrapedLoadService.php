<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Support\Settings;
use App\Support\TurkishLocations;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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

    // ---- Telefon kurulum bağlantısı ve MacroDroid şablonu ----

    public const MACRO_TEMPLATE_PATH = 'macrodroid/template.macro';

    /** Herkese açık kurulum sayfasının gizli kodu (bağlantıyı bilen kurar; yenilenince eski bağlantı ölür). */
    public static function setupCode(): string
    {
        $code = Settings::string('scraper_setup_code');
        if ($code === '') {
            $code = bin2hex(random_bytes(12));
            Settings::set('scraper_setup_code', $code);
        }

        return $code;
    }

    public static function regenerateSetupCode(?int $userId = null): string
    {
        $code = bin2hex(random_bytes(12));
        Settings::set('scraper_setup_code', $code, $userId);
        ActivityLog::record('scraper.setup_link_regenerated', 'Telefon kurulum bağlantısı yenilendi', $userId);

        return $code;
    }

    public static function setupUrl(): string
    {
        return route('phone-setup.show', ['code' => self::setupCode()]);
    }

    /** Kurulum bağlantısının QR kodu (satır içi SVG; dış betik gerekmez). */
    public static function setupQrSvg(): string
    {
        try {
            $options = new QROptions;
            $options->outputInterface = QRMarkupSVG::class;
            $options->outputBase64 = false;
            $options->svgAddXmlHeader = false;
            $options->addQuietzone = true;
            $options->quietzoneSize = 2;

            return (new QRCode($options))->render(self::setupUrl());
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Telefondan dışa aktarılan MacroDroid makrosunu (.macro, JSON) şablon olarak saklar.
     * İndirilirken içindeki anahtar ve adres güncel değerlerle değiştirilir.
     *
     * @return array{token:?string, url_found:bool}
     */
    public static function storeMacroTemplate(string $content, ?int $userId = null): array
    {
        $content = trim($content);
        if ($content === '' || strlen($content) > 2_000_000 || json_decode($content) === null) {
            throw new \InvalidArgumentException('Dosya MacroDroid dışa aktarımı (JSON) değil.');
        }
        $urlFound = (bool) preg_match('~api\\\\?/v1\\\\?/webhook\\\\?/notification~', $content);
        if (! $urlFound) {
            throw new \InvalidArgumentException('Makroda NavlunIQ bildirim adresi yok; önce telefonda çalışan makroyu dışa aktarın.');
        }
        $token = preg_match('~token\\\\*"\\s*:\\s*\\\\*"([A-Za-z0-9_\\-]{8,})~', $content, $m) ? $m[1]
            : (preg_match('~\\b([a-f0-9]{48})\\b~', $content, $m) ? $m[1] : null); // form alanı kurulumunda 48 karakterlik anahtar
        Storage::disk('local')->put(self::MACRO_TEMPLATE_PATH, $content);
        Settings::set('macrodroid_template_token', $token ?? '', $userId);
        Settings::set('macrodroid_template_at', now()->toDateTimeString(), $userId);
        ActivityLog::record('scraper.macro_template_uploaded', 'MacroDroid şablonu yüklendi', $userId);

        return ['token' => $token, 'url_found' => $urlFound];
    }

    public static function hasMacroTemplate(): bool
    {
        return Storage::disk('local')->exists(self::MACRO_TEMPLATE_PATH);
    }

    public static function deleteMacroTemplate(?int $userId = null): void
    {
        Storage::disk('local')->delete(self::MACRO_TEMPLATE_PATH);
        Settings::set('macrodroid_template_token', '', $userId);
        Settings::set('macrodroid_template_at', '', $userId);
    }

    /** Şablonun güncel anahtar ve adresle yamalanmış hâli; şablon yoksa null. */
    public static function macroTemplate(): ?string
    {
        if (! self::hasMacroTemplate()) {
            return null;
        }
        $content = (string) Storage::disk('local')->get(self::MACRO_TEMPLATE_PATH);
        $oldToken = Settings::string('macrodroid_template_token');
        if ($oldToken !== '') {
            $content = str_replace($oldToken, self::apiToken(), $content);
        }
        $target = url('/api/v1/webhook/notification');
        $content = preg_replace('~https?:(\\\\?/){2}[^"\\\\\\s]+?(\\\\?/)api(\\\\?/)v1(\\\\?/)webhook(\\\\?/)notification~', str_replace('/', '${1}', $target), $content) ?? $content;

        return $content;
    }

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
                'ai' => array_intersect_key($ai['data'], array_flip(['provider', 'model', 'confidence', 'notes', 'pickup_date_text', 'multiple_loads', 'is_load'])),
                'ai_conflict' => $merged['ai_conflict'] ?? null,
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
        $meta = (array) ($load->parse_metadata ?? []);
        if (! empty($meta['ai_conflict']) && empty($meta['admin_edited'])) {
            return 'kural ve yapay zeka farklı il buldu; elle kontrol';
        }
        if ($load->parse_confidence !== null && (float) $load->parse_confidence < 0.5 && empty($meta['admin_edited'])) {
            return 'yapay zeka güveni düşük; elle kontrol';
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
