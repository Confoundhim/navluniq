<?php

namespace App\Services;

use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Support\GoodsCatalog;
use App\Support\TurkishCities;
use App\Support\VehicleClassifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dış kaynaklardan (WhatsApp, Telegram, bildirim iletici) gelen ham ilan metnini
 * kota harcamadan eler, tekrarları ayıklar ve yalnız gerçekten yeni ilanı yapay zekaya gönderir.
 *
 * Sıra: birebir tekrar → metin tekrarı (aynı anda gelenler dahil) → kaynak onayı →
 * ilan ön filtresi → ücretsiz kalıp ayrıştırma → rota tekrarı → yapay zeka → rota tekrarı → kayıt.
 */
class LoadIntakeService
{
    public const TEXT_DEDUPE_DAYS = 7;

    public const ROUTE_DEDUPE_HOURS = 48;

    public function __construct(private readonly AiParserService $parser, private readonly LoadStandardizer $standardizer) {}

    /**
     * @param  array{group_name:string, raw_message:string, sender_phone?:?string, message_id?:?string, source_jid?:?string, source_type?:string}  $payload
     * @return array{code:int, success:bool, message:string, status:string, scraped_load_id?:int}
     */
    public function intake(array $payload): array
    {
        $raw = trim((string) $payload['raw_message']);
        $sourceId = (string) ($payload['source_jid'] ?? $payload['group_name']);
        $sourceType = (string) ($payload['source_type'] ?? 'whatsapp');

        // 1) Birebir aynı teslimat (daemon yeniden bağlanınca aynı mesajı tekrar yollar).
        $contentHash = hash('sha256', $sourceId.'|'.($payload['message_id'] ?? '').'|'.$raw);
        $groupName = (string) $payload['group_name'];
        if ($existing = ScrapedLoad::query()->where('content_hash', $contentHash)->first()) {
            return $this->result(200, true, 'duplicate', 'Mesaj daha önce işlendi.', $existing->id);
        }

        // 2) Aynı metin farklı gruplardan (aynı anda bile gelse) yalnız bir kez işlenir; diğerleri sayaca yazılır.
        $normalizedHash = hash('sha256', self::normalizeText($raw));
        $seenKey = 'intake:seen:'.$normalizedHash;
        if (! Cache::add($seenKey, 1, now()->addHours(24))) {
            $first = ScrapedLoad::query()->where('normalized_hash', $normalizedHash)->latest('id')->first();
            $this->noteSighting($first, $groupName);

            return $this->result(200, true, 'duplicate', 'Aynı ilan başka bir kaynaktan işleniyor veya işlendi.', $first?->id);
        }

        try {
            $recent = ScrapedLoad::query()->where('normalized_hash', $normalizedHash)
                ->where('created_at', '>=', now()->subDays(self::TEXT_DEDUPE_DAYS))->first();
            if ($recent) {
                $this->noteSighting($recent, $groupName);

                return $this->result(200, true, 'duplicate', 'Aynı ilan başka bir kaynaktan daha önce alındı.', $recent->id);
            }

            // 3) Kaynak onaylı değilse hiçbir ayrıştırma yapılmaz; kota harcanmaz.
            $scraper = Scraper::firstOrCreate(
                ['source_identifier' => $sourceId],
                ['name' => (string) $payload['group_name'], 'type' => $sourceType, 'is_active' => false]
            );
            if (! $scraper->is_active) {
                Cache::forget($seenKey); // kaynak açıldığında aynı metin yeniden gelebilsin

                return $this->result(202, false, 'source_pending', 'Kaynak yönetici onayı bekliyor.');
            }

            // 4) Telefonu olmayan mesaj ilan olarak kullanılamaz; yapay zekaya da gitmez (kota).
            if (! self::hasPhone($raw) && empty($payload['sender_phone'])) {
                return $this->result(200, false, 'filtered', 'İlan ölçütleri karşılanmadı.', null, 'phone_missing');
            }
            // Yapay zeka öncelikli kipte ("Her ilanda") ilan mı sohbet mi kararını yapay zeka verir; kural ön eleme yalnız
            // yapay zeka kapalıyken/anahtarsızken uygulanır.
            if (! $this->parser->aiFirst() && ! self::looksLikeLoad($raw)) {
                return $this->result(200, false, 'filtered', 'İlan ölçütleri karşılanmadı.', null, self::filterReason($raw));
            }

            // 5) Ücretsiz kalıp ayrıştırma; sonra ayara göre yapay zeka (her ilanda ya da yalnız eksik alanlarda).
            $parsed = $this->parser->parseCheap($raw);
            $ai = ['status' => 'skipped', 'data' => null];

            // Kural sonucu zaten bilinen bir numara+rota ise yapay zekaya gitmeden tekrar sayılır.
            if ($sameRoute = $this->recentSameRoute($parsed)) {
                $this->noteSighting($sameRoute, $groupName);

                return $this->result(200, true, 'duplicate', 'Aynı numara ve rota yakın zamanda kaydedildi.', $sameRoute->id);
            }
            if ($this->parser->shouldUseAi($parsed)) {
                $ai = $this->parser->enrich($raw, $parsed);
                if ($ai['data'] !== null) {
                    $parsed = $this->parser->merge($parsed, $ai['data']);
                }
            }
        } catch (Throwable $e) {
            Cache::forget($seenKey); // yeniden denenebilsin
            Log::error('Dış kaynak ilanı ayrıştırılamadı.', ['exception' => $e::class, 'error' => $e->getMessage()]);

            return $this->result(503, false, 'failed', 'Mesaj işlenemedi.', null, $e::class);
        }

        // Yapay zeka yüksek güvenle "bu bir yük ilanı değil" dediyse (sohbet, araç satışı, iş ilanı…) elenir.
        if (($ai['data']['is_load'] ?? true) === false && (float) ($ai['data']['confidence'] ?? 0) >= 0.8) {
            return $this->result(200, false, 'filtered', 'Yapay zeka: yük ilanı değil.', null, 'ai_not_load');
        }

        $phone = $parsed['sender_phone'] ?? $payload['sender_phone'] ?? null;
        $hasPhone = is_string($phone) && $phone !== '' && $phone !== 'Bilinmiyor';
        if (! $hasPhone) {
            return $this->result(200, false, 'filtered', 'İlan ölçütleri karşılanmadı.', null, 'phone_missing');
        }
        if (($parsed['success'] ?? false) !== true) {
            return $this->result(200, false, 'filtered', 'İlan ölçütleri karşılanmadı.', null, $parsed['reason'] ?? 'route_missing');
        }

        // 6) Aynı numara aynı rotayı kısa aralıkla farklı sözcüklerle paylaşmışsa tek ilan kalır.
        $routeKey = self::routeKey($phone, $parsed['pickup_location'] ?? null, $parsed['delivery_location'] ?? null);
        if ($sameRoute = $this->recentSameRoute($parsed, $phone)) {
            $this->noteSighting($sameRoute, $groupName);

            return $this->result(200, true, 'duplicate', 'Aynı numara ve rota yakın zamanda kaydedildi.', $sameRoute->id);
        }

        // Standartlaştırma: konum kataloğu (yazım hatası toleranslı), yük kategorisi, araç tipi, tonaj, fiyat, aciliyet.
        $std = $this->standardizer->standardize($raw, $parsed);

        $scraper->update(['last_scraped_at' => now(), 'last_success_at' => now(), 'last_error' => null]);
        $scrapedLoad = ScrapedLoad::create([
            'scraper_id' => $scraper->id,
            'content_hash' => $contentHash,
            'normalized_hash' => $normalizedHash,
            'route_key' => $routeKey,
            'duplicate_count' => 1,
            'seen_sources' => [$groupName],
            'raw_message' => $raw,
            'sender_phone' => null,
            'encrypted_sender_phone' => Crypt::encryptString($phone),
            'pickup_location' => $std['pickup_location'],
            'pickup_province_code' => $std['pickup_province_code'],
            'pickup_district' => $std['pickup_district'],
            'pickup_lat' => $std['pickup_lat'],
            'pickup_lng' => $std['pickup_lng'],
            'delivery_location' => $std['delivery_location'],
            'delivery_province_code' => $std['delivery_province_code'],
            'delivery_district' => $std['delivery_district'],
            'delivery_lat' => $std['delivery_lat'],
            'delivery_lng' => $std['delivery_lng'],
            'goods_type' => $std['goods_type'],
            'vehicle_type' => $std['vehicle_type'],
            'vehicle_type_source' => $std['vehicle_type_source'],
            'weight' => $std['weight'],
            'price' => $std['price'],
            'currency' => 'TRY',
            'status' => ($std['pickup_province_code'] !== null && $std['delivery_province_code'] !== null) ? 'parsed_success' : 'parsed_partial',
            'parsed_by_llm' => $parsed['parsed_by_llm'] ?? 'unknown',
            'parse_confidence' => $ai['data']['confidence'] ?? null,
            'ai_status' => $ai['status'],
            'ai_checked_at' => in_array($ai['status'], ['done', 'failed'], true) ? now() : null,
            'parse_metadata' => array_merge($std['metadata'], array_filter([
                'ai' => $ai['data'] !== null ? array_intersect_key($ai['data'], array_flip(['provider', 'model', 'confidence', 'notes', 'pickup_date_text', 'multiple_loads'])) : null,
                'ai_conflict' => $parsed['ai_conflict'] ?? null,
            ])),
            'visibility' => 'private',
            'retention_expires_at' => now()->addDays(30),
        ]);

        return $this->result(201, true, 'created', 'İlan adayı kaydedildi.', $scrapedLoad->id);
    }

    /** looksLikeLoad() neden başarısız oldu: canlı akışta gösterilen kısa gerekçe. */
    public static function filterReason(string $text): string
    {
        $hasPhone = (bool) preg_match('/(?<!\d)(?:\+?90|0)?[\s\-.()]*5(?:[\s\-.()]*\d){9}(?!\d)/u', $text);

        return $hasPhone ? 'no_logistics_signal' : 'phone_missing';
    }

    /** Aynı ilan yeni bir kaynaktan görüldüyse sayacı ve kaynak listesini günceller; aynı kaynaktan tekrar sayılmaz. */
    private function noteSighting(?ScrapedLoad $load, string $groupName): void
    {
        if (! $load || $groupName === '') {
            return;
        }
        $sources = array_values(array_filter((array) ($load->seen_sources ?? [])));
        if (in_array($groupName, $sources, true)) {
            return;
        }
        $sources[] = $groupName;
        $load->forceFill(['seen_sources' => $sources, 'duplicate_count' => count($sources)])->save();
    }

    /** Emoji, noktalama, bağlantı ve büyük/küçük harf farklarını yok sayan karşılaştırma metni. */
    public static function normalizeText(string $text): string
    {
        $t = TurkishCities::lower($text);
        $t = preg_replace('~https?://\S+~u', ' ', $t) ?? $t;
        $t = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t) ?? $t;

        return trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
    }

    /** Telefon numarası ve en az bir lojistik işaret (rota, tonaj, fiyat ya da araç/yük sözcüğü) içermeli. */
    public static function hasPhone(string $text): bool
    {
        return (bool) preg_match('/(?<!\d)(?:\+?90|0)?[\s\-.()]*5(?:[\s\-.()]*\d){9}(?!\d)/u', $text);
    }

    public static function looksLikeLoad(string $text): bool
    {
        if (! self::hasPhone($text)) {
            return false;
        }

        $hasMoney = (bool) preg_match('/\d[\d.,]*\s*(?:tl|₺|lira|bin)(?!\p{L})/iu', $text);
        $hasWeight = (bool) preg_match('/\d[\d.,]*\s*(?:kg|ton|tn|t|palet|koli|adet|m3)(?!\p{L})/iu', $text);
        // "Ankara-İzmir", "Ankaradan İzmire", "Diyarbakr dan Ankaraya", "Ankara → İzmir"
        $hasRoute = (bool) preg_match('/\p{L}{3,}\s*(?:->|→|>|-|–|—)\s*\p{L}{3,}|\p{L}{3,}\s*(?:dan|den|tan|ten)\s+\p{L}{3,}/iu', $text);
        $hasKeyword = (bool) preg_match('/(?<!\p{L})(?:yük|yuk|yükü|nakliye|nakliyat|tır|tir|kamyon|kamyonet|dorse|tenteli|tente|parsiyel|komple|araç|arac|sevkiyat|yükleme|yukleme|teslim|palet|frigo|lowbed|kırkayak|panelvan|çekici|cekici|navlun|gidecek|taşınacak|tasinacak|lazım|lazim|aranıyor|araniyor|arayan|boşta|bosta|yükleyecek)(?!\p{L})/iu', $text);
        $norm = VehicleClassifier::normalize($text);
        $hasGoods = GoodsCatalog::detect($norm) !== null;
        $hasVehicle = VehicleClassifier::analyze($text)['type'] !== null;

        return $hasRoute || $hasMoney || $hasWeight || $hasKeyword || $hasGoods || $hasVehicle;
    }

    /** Aynı numara + aynı il çifti son saatlerde kaydedildiyse o ilanı döndürür. */
    private function recentSameRoute(array $parsed, ?string $phone = null): ?ScrapedLoad
    {
        $phone ??= $parsed['sender_phone'] ?? null;
        $routeKey = is_string($phone) && $phone !== '' ? self::routeKey($phone, $parsed['pickup_location'] ?? null, $parsed['delivery_location'] ?? null) : null;
        if (! $routeKey) {
            return null;
        }

        return ScrapedLoad::query()->where('route_key', $routeKey)
            ->where('created_at', '>=', now()->subHours(self::ROUTE_DEDUPE_HOURS))->first();
    }

    public static function routeKey(?string $phone, ?string $pickup, ?string $delivery): ?string
    {
        if (! $phone || ! $pickup || ! $delivery) {
            return null;
        }
        // İlçe/semt yazımı kaynağa göre değiştiği için il düzeyinde karşılaştırılır:
        // aynı numara, aynı il çifti, 48 saat içinde → aynı ilan. İl bulunamazsa ilk sözcük kullanılır.
        $city = fn (string $v) => TurkishCities::ascii(TurkishCities::fromText($v) ?? (string) strtok(self::normalizeText($v), ' '));

        return mb_substr($phone.'|'.$city($pickup).'|'.$city($delivery), 0, 191);
    }

    private function result(int $code, bool $success, string $status, string $message, ?int $id = null, ?string $reason = null): array
    {
        $out = ['code' => $code, 'success' => $success, 'status' => $status, 'message' => $message];
        if ($id !== null) {
            $out['scraped_load_id'] = $id;
        }
        if ($reason !== null) {
            $out['reason'] = $reason;
        }

        return $out;
    }
}
