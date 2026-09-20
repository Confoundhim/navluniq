<?php

namespace App\Services;

use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Support\GoodsCatalog;
use App\Support\Phone;
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
 * Bir mesajda birden fazla ilan (farklı rotalar) ve bir ilanda birden fazla numara olabilir:
 * mesaj önce ilan parçalarına ayrılır (yapay zeka öncelikli kipte yapay zeka ayırır, aksi halde kural),
 * her parça ayrı aday olarak işlenir; parçanın tüm numaraları adayla birlikte saklanır.
 *
 * Sıra: birebir tekrar → metin tekrarı (aynı anda gelenler dahil) → kaynak onayı → telefon kapısı →
 * ilanlara ayırma → her ilan için: tekrar → ücretsiz kalıp ayrıştırma → rota tekrarı → yapay zeka → kayıt.
 */
class LoadIntakeService
{
    public const TEXT_DEDUPE_DAYS = 7;

    public const ROUTE_DEDUPE_HOURS = 48;

    /** Bir mesajdan en fazla bu kadar ilan adayı açılır (kötü niyetli/uzun listelere karşı). */
    public const MAX_ADS_PER_MESSAGE = 15;

    public function __construct(private readonly AiParserService $parser, private readonly LoadStandardizer $standardizer) {}

    /**
     * @param  array{group_name:string, raw_message:string, sender_phone?:?string, message_id?:?string, source_jid?:?string, source_type?:string}  $payload
     * @return array{code:int, success:bool, message:string, status:string, scraped_load_id?:int, reason?:string, created_ids?:list<int>, segments?:list<array>}
     */
    public function intake(array $payload): array
    {
        $raw = trim((string) $payload['raw_message']);
        $sourceId = (string) ($payload['source_jid'] ?? $payload['group_name']);
        $sourceType = (string) ($payload['source_type'] ?? 'whatsapp');
        $messageId = (string) ($payload['message_id'] ?? '');

        // 1) Birebir aynı teslimat (daemon yeniden bağlanınca aynı mesajı tekrar yollar).
        $contentHash = hash('sha256', $sourceId.'|'.$messageId.'|'.$raw);
        $groupName = (string) $payload['group_name'];
        if ($existing = ScrapedLoad::query()->where('content_hash', $contentHash)->first()) {
            return $this->result(200, true, 'duplicate', 'Mesaj daha önce işlendi.', $existing->id);
        }

        // 2) Aynı metin farklı gruplardan (aynı anda bile gelse) yalnız bir kez işlenir; diğerleri sayaca yazılır.
        $normalizedHash = hash('sha256', self::normalizeText($raw));
        $seenKey = 'intake:seen:'.$normalizedHash;
        // Birden çok ilan barındıran bir mesajın kayıtları parça parça tutulur; mesajın tamamına ait kayıt bulunamazsa
        // parça düzeyindeki tekrar denetimi devreye girer (her ilan kendi sayacına yazılır).
        if (! Cache::add($seenKey, 1, now()->addHours(24))) {
            $first = ScrapedLoad::query()->where('normalized_hash', $normalizedHash)->latest('id')->first();
            if ($first) {
                $this->noteSighting($first, $groupName);

                return $this->result(200, true, 'duplicate', 'Aynı ilan başka bir kaynaktan işleniyor veya işlendi.', $first->id);
            }
        }

        try {
            $recent = ScrapedLoad::query()->where('normalized_hash', $normalizedHash)
                ->where('created_at', '>=', now()->subDays(self::TEXT_DEDUPE_DAYS))->first();
            if ($recent) {
                $this->noteSighting($recent, $groupName);

                return $this->result(200, true, 'duplicate', 'Aynı ilan başka bir kaynaktan daha önce alındı.', $recent->id);
            }

            // 3) Kaynak onaylı değilse hiçbir ayrıştırma yapılmaz; kota harcanmaz.
            // Panelden silinmiş kaynak: mesaj yok sayılır ama sayılır; yönetici "Silinenler" listesinde görüp
            // geri alır ya da kalıcı siler. (Aynı tanımlayıcıyla ikinci kayıt açılmaz.)
            $scraper = Scraper::withTrashed()->firstOrNew(
                ['source_identifier' => $sourceId],
                ['name' => (string) $payload['group_name'], 'type' => $sourceType, 'is_active' => false]
            );
            if ($scraper->exists && $scraper->trashed()) {
                Scraper::withTrashed()->whereKey($scraper->id)->update(['messages_since_deleted' => $scraper->messages_since_deleted + 1, 'last_message_at' => now()]);
                Cache::forget($seenKey);

                return $this->result(202, false, 'source_deleted', 'Kaynak silinmiş; mesaj yok sayıldı.');
            }
            if (! $scraper->exists) {
                $scraper->save();
            }
            $scraper->forceFill(['last_message_at' => now()])->saveQuietly();
            if (! $scraper->is_active) {
                Cache::forget($seenKey); // kaynak açıldığında aynı metin yeniden gelebilsin

                return $this->result(202, false, 'source_pending', 'Kaynak yönetici onayı bekliyor.');
            }

            // 4) Telefonu olmayan mesaj ilan olarak kullanılamaz; yapay zekaya da gitmez (kota).
            $fallbackPhone = Phone::normalize($payload['sender_phone'] ?? null);
            if (! self::hasPhone($raw) && $fallbackPhone === null) {
                return $this->result(200, false, 'filtered', 'İlan ölçütleri karşılanmadı.', null, 'phone_missing');
            }
            $aiFirst = $this->parser->aiFirst();
            // Yapay zeka öncelikli kipte ("Her ilanda") ilan mı sohbet mi kararını yapay zeka verir; kural ön eleme yalnız
            // yapay zeka kapalıyken/anahtarsızken uygulanır.
            if (! $aiFirst && ! self::looksLikeLoad($raw)) {
                return $this->result(200, false, 'filtered', 'İlan ölçütleri karşılanmadı.', null, self::filterReason($raw));
            }

            // 5) Mesajı ilanlara ayır. Kural: boş satır / rota satırı sınırları, ortak numara paylaşımı.
            $segments = self::splitSegments($raw, $fallbackPhone);
            $ctx = ['raw' => $raw, 'source_id' => $sourceId, 'message_id' => $messageId, 'group' => $groupName, 'scraper' => $scraper,
                'fallback_phone' => $fallbackPhone, 'ai_first' => $aiFirst, 'message_ai' => ['status' => 'skipped', 'data' => null]];

            // Bilinen numara+rota tekrarları yapay zekaya gitmeden elenir (kota); kalanlar için yapay zeka çağrılır.
            $results = [];
            $pending = [];
            foreach ($segments as $i => $segment) {
                $parsed = $this->parser->parseCheap($segment['text']);
                if (($parsed['sender_phone'] ?? null) === null && $segment['phones'] !== []) {
                    $parsed['sender_phone'] = $segment['phones'][0];
                }
                // Kural parçası hâlâ birden çok ilan barındırıyor olabilir (birden çok numara / ikiden çok il):
                // yapay zeka öncelikli kipte böyle bir parça tekrar sayılmaz, yapay zekanın ayırmasına bırakılır.
                $mayHoldSeveral = $aiFirst && (count($segment['phones']) > 1 || count(AiParserService::provincesIn($segment['text'], 3)) > 2);
                if (! $mayHoldSeveral && ($sameRoute = $this->recentSameRoute($parsed))) {
                    $this->noteSighting($sameRoute, $groupName);
                    $results[$i] = $this->result(200, true, 'duplicate', 'Aynı numara ve rota yakın zamanda kaydedildi.', $sameRoute->id) + ['excerpt' => $segment['text']];

                    continue;
                }
                $pending[$i] = $segment + ['parsed' => $parsed];
            }

            if ($pending !== [] && $aiFirst) {
                // Yapay zeka öncelikli kip: tüm mesaj bir kez gönderilir; ilanları ve numaraları yapay zeka ayırır.
                $ctx['message_ai'] = $this->parser->enrich($raw, []);
                $aiData = $ctx['message_ai']['data'];
                if ($aiData !== null) {
                    $ads = array_values(array_filter((array) ($aiData['ads'] ?? []), fn ($a) => is_array($a) && ($a['is_load'] ?? false)));
                    if ($ads === [] && ($aiData['is_load'] ?? true) === false && (float) ($aiData['confidence'] ?? 0) >= 0.8) {
                        return $this->result(200, false, 'filtered', 'Yapay zeka: yük ilanı değil.', null, 'ai_not_load');
                    }
                    if ($ads !== []) {
                        $pending = $this->segmentsFromAi($ads, array_values($pending), $raw, $fallbackPhone);
                    }
                }
            }

            foreach ($pending as $i => $segment) {
                $results[$i] = $this->processSegment($segment, $ctx);
            }
            ksort($results);
        } catch (Throwable $e) {
            Cache::forget($seenKey); // yeniden denenebilsin
            Log::error('Dış kaynak ilanı ayrıştırılamadı.', ['exception' => $e::class, 'error' => $e->getMessage()]);

            return $this->result(503, false, 'failed', 'Mesaj işlenemedi.', null, $e::class);
        }

        return $this->aggregate(array_values($results));
    }

    /**
     * Tek bir ilan parçasını işler: tekrar denetimi, kural + yapay zeka çözümleme, rota tekrarı, standartlaştırma, kayıt.
     *
     * @param  array{text:string, phones:list<string>, parsed?:array, ai?:array}  $segment
     */
    private function processSegment(array $segment, array $ctx): array
    {
        $text = $segment['text'];
        $raw = $ctx['raw'];
        $groupName = $ctx['group'];
        $isWhole = $text === $raw;
        $contentHash = hash('sha256', $ctx['source_id'].'|'.$ctx['message_id'].'|'.$text);
        $normalizedHash = hash('sha256', self::normalizeText($text));
        $seenKey = 'intake:seen:'.$normalizedHash;

        // Parça düzeyinde tekrar (mesajın tamamı için üstte bakıldı; alt parçalar için burada).
        if (! $isWhole) {
            if ($existing = ScrapedLoad::query()->where('content_hash', $contentHash)->first()) {
                return $this->result(200, true, 'duplicate', 'İlan daha önce işlendi.', $existing->id) + ['excerpt' => $text];
            }
            if (! Cache::add($seenKey, 1, now()->addHours(24))) {
                $first = ScrapedLoad::query()->where('normalized_hash', $normalizedHash)->latest('id')->first();
                $this->noteSighting($first, $groupName);

                return $this->result(200, true, 'duplicate', 'Aynı ilan başka bir kaynaktan işleniyor veya işlendi.', $first?->id) + ['excerpt' => $text];
            }
            $recent = ScrapedLoad::query()->where('normalized_hash', $normalizedHash)
                ->where('created_at', '>=', now()->subDays(self::TEXT_DEDUPE_DAYS))->first();
            if ($recent) {
                $this->noteSighting($recent, $groupName);

                return $this->result(200, true, 'duplicate', 'Aynı ilan başka bir kaynaktan daha önce alındı.', $recent->id) + ['excerpt' => $text];
            }
        }

        $parsed = $segment['parsed'] ?? $this->parser->parseCheap($text);
        $phones = array_values(array_unique(array_merge($segment['phones'], (array) ($parsed['phones'] ?? []))));
        if (($parsed['sender_phone'] ?? null) === null && $phones !== []) {
            $parsed['sender_phone'] = $phones[0];
        }
        $ai = ['status' => 'skipped', 'data' => null];

        if (isset($segment['ai'])) {
            // Yapay zeka öncelikli kip: bu ilanın alanları mesajın tamamına yapılan çağrıdan geldi.
            $ai = ['status' => 'done', 'data' => $segment['ai']];
            $parsed = $this->parser->merge($parsed, $segment['ai']);
        } elseif ($ctx['ai_first'] && $ctx['message_ai']['status'] !== 'skipped') {
            // Yapay zeka ulaşılamadı (kota/ağ) ya da ilanı ayıramadı: kural sonucu ile devam; durum kuyrukta yeniden denenir.
            $ai = ['status' => $ctx['message_ai']['status'] === 'done' ? 'pending' : $ctx['message_ai']['status'], 'data' => null];
        } elseif ($this->parser->shouldUseAi($parsed)) {
            $ai = $this->parser->enrich($text, $parsed);
            if ($ai['data'] !== null) {
                $ai['data'] = AiParserService::pickAd($ai['data'], $parsed['sender_phone'] ?? null, $parsed['pickup_location'] ?? null, $parsed['delivery_location'] ?? null);
                $parsed = $this->parser->merge($parsed, $ai['data']);
            }
        }

        // Yapay zeka yüksek güvenle "bu bir yük ilanı değil" dediyse (sohbet, araç satışı, iş ilanı…) elenir.
        if (($ai['data']['is_load'] ?? true) === false && (float) ($ai['data']['confidence'] ?? 0) >= 0.8) {
            return $this->result(200, false, 'filtered', 'Yapay zeka: yük ilanı değil.', null, 'ai_not_load') + ['excerpt' => $text];
        }

        $phones = array_values(array_unique(array_filter(array_merge(
            [$parsed['sender_phone'] ?? null], $phones, (array) ($parsed['phones'] ?? []), [$ctx['fallback_phone']]
        ), fn ($p) => is_string($p) && preg_match('/^5\d{9}$/', $p) === 1)));
        $phone = $phones[0] ?? null;
        if ($phone === null) {
            return $this->result(200, false, 'filtered', 'İlan ölçütleri karşılanmadı.', null, 'phone_missing') + ['excerpt' => $text];
        }
        $parsed['sender_phone'] = $phone;
        $parsed['success'] = ! empty($parsed['pickup_location']) && ! empty($parsed['delivery_location']);
        if ($parsed['success'] !== true) {
            return $this->result(200, false, 'filtered', 'İlan ölçütleri karşılanmadı.', null, $parsed['reason'] ?? 'route_missing') + ['excerpt' => $text];
        }

        // 6) Aynı numara aynı rotayı kısa aralıkla farklı sözcüklerle paylaşmışsa tek ilan kalır.
        $routeKey = self::routeKey($phone, $parsed['pickup_location'] ?? null, $parsed['delivery_location'] ?? null);
        if ($sameRoute = $this->recentSameRoute($parsed, $phone)) {
            $this->noteSighting($sameRoute, $groupName);

            return $this->result(200, true, 'duplicate', 'Aynı numara ve rota yakın zamanda kaydedildi.', $sameRoute->id) + ['excerpt' => $text];
        }

        // Standartlaştırma: konum kataloğu (yazım hatası toleranslı), yük kategorisi, araç tipi, tonaj, fiyat, aciliyet.
        $std = $this->standardizer->standardize($text, $parsed);
        $extraPhones = array_values(array_slice($phones, 1));

        /** @var Scraper $scraper */
        $scraper = $ctx['scraper'];
        $scraper->update(['last_scraped_at' => now(), 'last_success_at' => now(), 'last_error' => null]);
        $scrapedLoad = ScrapedLoad::create([
            'scraper_id' => $scraper->id,
            'content_hash' => $contentHash,
            'normalized_hash' => $normalizedHash,
            'route_key' => $routeKey,
            'duplicate_count' => 1,
            'seen_sources' => [$groupName],
            'raw_message' => $text,
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
                'ai' => $ai['data'] !== null ? array_intersect_key($ai['data'], array_flip(['provider', 'model', 'confidence', 'notes', 'pickup_date_text', 'multiple_loads', 'ad_index', 'ad_count'])) : null,
                'ai_conflict' => $parsed['ai_conflict'] ?? null,
                // Aynı ilandaki diğer numaralar (şifreli); ilk numara ana kolonda.
                'extra_phones_enc' => $extraPhones !== [] ? array_map(fn (string $p) => Crypt::encryptString($p), $extraPhones) : null,
                'phone_count' => count($phones) > 1 ? count($phones) : null,
                'message_part' => $isWhole ? null : ['index' => $segment['index'] ?? null, 'count' => $segment['count'] ?? null, 'hash' => substr(hash('sha256', $raw), 0, 16)],
            ])),
            'visibility' => 'private',
            'retention_expires_at' => now()->addDays(30),
        ]);

        return $this->result(201, true, 'created', 'İlan adayı kaydedildi.', $scrapedLoad->id) + ['excerpt' => $text];
    }

    /**
     * Parça sonuçlarını tek yanıta indirger: biri bile kaydedildiyse "created" (ilk kimlik + tüm kimlikler),
     * yoksa tekrar, yoksa elendi (ilk gerekçe). Her parçanın sonucu "segments" altında (canlı akış satırları).
     */
    private function aggregate(array $results): array
    {
        $created = array_values(array_filter($results, fn ($r) => $r['status'] === 'created'));
        $duplicates = array_values(array_filter($results, fn ($r) => $r['status'] === 'duplicate'));
        $segments = array_map(fn (array $r) => array_intersect_key($r, array_flip(['status', 'message', 'reason', 'scraped_load_id', 'excerpt'])), $results);
        $count = count($results);

        if ($created !== []) {
            $ids = array_map(fn ($r) => $r['scraped_load_id'], $created);
            $message = count($ids) > 1 ? count($ids).' ilan adayı kaydedildi (mesajda '.$count.' ilan bulundu).' : ($count > 1 ? 'İlan adayı kaydedildi (mesajdaki diğer '.($count - 1).' ilan tekrar/elendi).' : 'İlan adayı kaydedildi.');

            return $this->result(201, true, 'created', $message, $ids[0]) + ['created_ids' => $ids, 'segments' => $segments];
        }
        if ($duplicates !== []) {
            return $this->result(200, true, 'duplicate', $count > 1 ? 'Mesajdaki ilanlar daha önce alınmış.' : $duplicates[0]['message'], $duplicates[0]['scraped_load_id'] ?? null) + ['segments' => $segments];
        }
        $first = $results[0] ?? $this->result(200, false, 'filtered', 'İlan ölçütleri karşılanmadı.', null, 'route_missing');

        return array_intersect_key($first, array_flip(['code', 'success', 'status', 'message', 'reason', 'scraped_load_id'])) + ['segments' => $segments];
    }

    /**
     * Yapay zekanın ayırdığı ilanları aday parçalara çevirir. Tek ilan → mesajın tamamı (gruplar arası tekrar
     * denetimi bozulmasın). Birden çok ilan → yapay zekanın aynen alıntısı; alıntı yoksa aynı sıradaki kural parçası;
     * o da yoksa alanlardan kurulan kısa metin. Parça metni ilanın numarasını taşımıyorsa numara satırı eklenir.
     *
     * @param  list<array>  $ads  normalizeAd() çıktıları (yalnız yük olanlar)
     * @param  list<array>  $ruleSegments
     * @return list<array{text:string, phones:list<string>, ai:array, index:int, count:int}>
     */
    private function segmentsFromAi(array $ads, array $ruleSegments, string $raw, ?string $fallbackPhone): array
    {
        $ads = array_slice($ads, 0, self::MAX_ADS_PER_MESSAGE);
        $count = count($ads);
        $messagePhones = AiParserService::phonesIn($raw);
        $out = [];
        $usedExcerpts = [];
        foreach ($ads as $i => $ad) {
            $text = null;
            $excerpt = is_string($ad['excerpt'] ?? null) ? trim($ad['excerpt']) : '';
            $excerptKey = self::normalizeText($excerpt);
            if ($count === 1) {
                $text = $raw;
            } elseif (mb_strlen($excerpt) >= 8 && ! in_array($excerptKey, $usedExcerpts, true)) {
                // Yapay zeka aynı alıntıyı iki ilana yazdıysa (ayıramadıysa) ikinci ilan yedek metni kullanır.
                $text = $excerpt;
                $usedExcerpts[] = $excerptKey;
            } elseif (count($ruleSegments) === $count) {
                $text = $ruleSegments[$i]['text'];
            } else {
                $text = trim(implode(' ', array_filter([
                    $ad['pickup_location'] ?? null, '→', $ad['delivery_location'] ?? null,
                    $ad['goods_type'] ?? null,
                    ($ad['weight'] ?? null) ? number_format((int) $ad['weight'], 0, ',', '.').' kg' : null,
                    ($ad['price'] ?? null) ? number_format((float) $ad['price'], 0, ',', '.').' TL' : null,
                    $ad['notes'] ?? null,
                ])));
            }
            $phones = array_values(array_unique(array_filter(array_merge(
                (array) ($ad['phones'] ?? []),
                AiParserService::phonesIn($text),
                $count === 1 ? $messagePhones : [],
                count($ruleSegments) === $count ? $ruleSegments[$i]['phones'] : [],
                count($messagePhones) === 1 ? $messagePhones : [], // tek ortak numara: her ilana
                [$fallbackPhone],
            ))));
            $inText = AiParserService::phonesIn($text);
            $missing = array_values(array_diff($phones, $inText));
            if ($missing !== [] && $inText === []) {
                $text .= "\n☎️ ".implode(', ', array_map(fn (string $p) => Phone::format($p), $missing));
            }
            $out[] = ['text' => $text, 'phones' => $phones, 'ai' => $ad, 'index' => $i, 'count' => $count];
        }

        return $out;
    }

    /**
     * Mesajı kural ile ilan parçalarına ayırır (yapay zeka yokken ya da yedek olarak).
     *
     * - Boş satırla ayrılmış bloklar ayrı parçadır.
     * - Aynı blok içinde, parça zaten bir il çifti taşıyorken yeni bir il çifti satırı gelirse yeni parça açılır
     *   ("Ankara-İzmir 24 ton 0532…" ↵ "Bursa-Konya 10 ton 0533…").
     * - Numarasız rota parçası, en yakın numaralı parçanın numaralarını devralır (ortak irtibat satırı).
     * - Rotasız/numarasız artıklar (selam, imza, reklam) komşu parçaya eklenir; yalnız numara olan blok
     *   ("☎️ 0505…") komşu rota parçasına eklenir.
     *
     * @return list<array{text:string, phones:list<string>, index:int, count:int}>
     */
    public static function splitSegments(string $raw, ?string $fallbackPhone = null): array
    {
        $raw = trim(str_replace(["\r\n", "\r"], "\n", $raw));
        if ($raw === '') {
            return [];
        }
        $units = [];
        foreach (preg_split('/\n[ \t]*\n+/u', $raw) ?: [] as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }
            $current = [];
            $provinces = []; // parçanın şimdiye kadarki farklı illeri (yazım sırasıyla)
            $hasPhone = false;
            foreach (preg_split('/\n/u', $block) ?: [] as $line) {
                $lineProvinces = AiParserService::provincesIn($line, 2);
                $split = false;
                if ($current !== [] && count($provinces) >= 2) {
                    // Parça zaten bir il çifti taşıyor: farklı bir il çifti satırı ("Bursa-Konya 10 ton") yeni ilan;
                    // numarası da yazılmış tam bir ilandan sonra yeni bir il satırı ("📍 Bursa") yeni ilan.
                    if (count($lineProvinces) === 2 && $lineProvinces !== array_slice($provinces, 0, 2)) {
                        $split = true;
                    } elseif ($hasPhone && $lineProvinces !== [] && ! in_array($lineProvinces[0], $provinces, true)) {
                        $split = true;
                    }
                }
                if ($split) {
                    $units[] = self::unit(implode("\n", $current));
                    $current = [];
                    $provinces = [];
                    $hasPhone = false;
                }
                $current[] = $line;
                foreach ($lineProvinces as $province) {
                    if (! in_array($province, $provinces, true)) {
                        $provinces[] = $province;
                    }
                }
                $hasPhone = $hasPhone || self::hasPhone($line);
            }
            if ($current !== []) {
                $units[] = self::unit(implode("\n", $current));
            }
        }
        if ($units === []) {
            return [];
        }
        if (count($units) === 1) {
            return [['text' => $raw, 'phones' => self::withFallback($units[0]['phones'], $fallbackPhone), 'index' => 0, 'count' => 1]];
        }

        // Artıklar: rotasız parçalar komşuya eklenir (numaralı olan numaralarını da taşır).
        $merged = [];
        foreach ($units as $unit) {
            if ($unit['route']) {
                $merged[] = $unit;

                continue;
            }
            $last = array_key_last($merged);
            if ($last !== null && ($unit['phones'] === [] || $merged[$last]['phones'] === [] || $unit['phones'] === $merged[$last]['phones'])) {
                $merged[$last] = self::join($merged[$last], $unit);
            } else {
                $merged[] = $unit; // önde numara/başlık bloğu: sıradaki rota parçasına eklenecek
            }
        }
        $final = [];
        foreach ($merged as $unit) {
            $last = array_key_last($final);
            if (! $unit['route'] && $last !== null && $unit['phones'] !== [] && $final[$last]['phones'] !== $unit['phones']) {
                // Rotasız numaralı blok (irtibat satırı): rota taşıyan sonraki parçaya eklenir, yoksa öncekine.
                $final[] = $unit;

                continue;
            }
            if (! $unit['route'] && $last === null) {
                $final[] = $unit;

                continue;
            }
            if ($last !== null && ! $final[$last]['route']) {
                $final[$last] = self::join($final[$last], $unit);

                continue;
            }
            $final[] = $unit;
        }
        // Sonda kalan rotasız blok öncekine eklenir.
        $last = array_key_last($final);
        if ($last !== null && $last > 0 && ! $final[$last]['route']) {
            $tail = array_pop($final);
            $final[array_key_last($final)] = self::join($final[array_key_last($final)], $tail);
        }
        if (count($final) === 1) {
            return [['text' => $raw, 'phones' => self::withFallback($final[0]['phones'], $fallbackPhone), 'index' => 0, 'count' => 1]];
        }

        // Numarasız rota parçaları en yakın numaralı parçanın numaralarını devralır (ortak irtibat).
        $count = count($final);
        $out = [];
        foreach ($final as $i => $unit) {
            $phones = $unit['phones'];
            $inherited = false;
            if ($phones === []) {
                foreach ([1, -1, 2, -2, 3, -3, 4, -4, 5, -5] as $offset) {
                    if (isset($final[$i + $offset]) && $final[$i + $offset]['phones'] !== []) {
                        $phones = $final[$i + $offset]['phones'];
                        $inherited = true;
                        break;
                    }
                }
            }
            $phones = self::withFallback($phones, $fallbackPhone);
            $text = $unit['text'];
            if ($inherited && $phones !== []) {
                $text .= "\n☎️ ".implode(', ', array_map(fn (string $p) => Phone::format($p), $phones));
            }
            $out[] = ['text' => $text, 'phones' => $phones, 'index' => $i, 'count' => $count];
        }

        return array_slice($out, 0, self::MAX_ADS_PER_MESSAGE);
    }

    private static function unit(string $text): array
    {
        $text = trim($text);

        return ['text' => $text, 'phones' => AiParserService::phonesIn($text), 'route' => count(AiParserService::firstTwoProvinces($text)) === 2];
    }

    private static function join(array $a, array $b): array
    {
        return [
            'text' => trim($a['text']."\n".$b['text']),
            'phones' => array_values(array_unique(array_merge($a['phones'], $b['phones']))),
            'route' => $a['route'] || $b['route'],
        ];
    }

    /** @return list<string> */
    private static function withFallback(array $phones, ?string $fallback): array
    {
        if ($fallback !== null && ! in_array($fallback, $phones, true)) {
            $phones[] = $fallback;
        }

        return array_values($phones);
    }

    /** looksLikeLoad() neden başarısız oldu: canlı akışta gösterilen kısa gerekçe. */
    public static function filterReason(string $text): string
    {
        return self::hasPhone($text) ? 'no_logistics_signal' : 'phone_missing';
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
        return (bool) preg_match(AiParserService::PHONE_PATTERN, $text);
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
