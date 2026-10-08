<?php

namespace App\Services;

use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Support\GoodsCatalog;
use App\Support\IntakeLayers;
use App\Support\Lexicon;
use App\Support\Phone;
use App\Support\SenderPickupMemory;
use App\Support\SeriesAd;
use App\Support\Settings;
use App\Support\TextPrep;
use App\Support\TurkishCities;
use App\Support\TurkishLocations;
use App\Support\VehicleClassifier;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
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

    /** Aynı yük başka numarayla: bu kadar saat içindeki kayıtlar "benzer ilan" diye eşlenir. */
    public const SIMILAR_HOURS = 48;

    /** Bir mesajdan en fazla bu kadar ilan adayı açılır (kötü niyetli/uzun listelere karşı); 15 iken 20-30 rotalı firma listeleri kesiliyordu. */
    /** Bir mesajdan en çok kaç ilan açılır. 40 iken 70 ilanlık lojistik listeleri yarım kalıyordu (Engin Abi, 2026-10-08). */
    public const MAX_ADS_PER_MESSAGE = 100;

    /** Son splitSegments çağrısında sınır yüzünden düşen ilan sayısı (canlı akış satırına yazılır). */
    public static int $lastTruncated = 0;

    public function __construct(private readonly AiParserService $parser, private readonly LoadStandardizer $standardizer, private readonly LocalClassifier $classifier, private readonly TemplateMemory $templates) {}

    /**
     * @param  array{group_name:string, raw_message:string, sender_phone?:?string, message_id?:?string, source_jid?:?string, source_type?:string}  $payload
     * @return array{code:int, success:bool, message:string, status:string, scraped_load_id?:int, reason?:string, created_ids?:list<int>, segments?:list<array>}
     */
    /** Bu mesajda açılan kayıtlar: aynı mesajın ikinci ilanı (aynı il çifti, farklı ilçe) birincinin tekrarı sayılmasın. */
    private array $messageIds = [];

    /** @var list<string> Bu mesajın parçaları için yazılan 'görüldü' anahtarları (hata olursa geri alınır) */
    private array $segmentSeenKeys = [];

    public function intake(array $payload): array
    {
        $this->messageIds = [];
        $this->segmentSeenKeys = [];
        // Aynı metin iki gruptan aynı saniyede gelince (bildirim iletici her grubu ayrı yollar) iki istek yan yana
        // işlenir ve tekrar denetimi henüz yazılmamış kaydı göremezdi. Mesaj başına kilit: ikinci istek ilkinin
        // bitmesini bekler, sonra kaydı bulur ve "tekrar" der. Kilit alınamazsa (aşırı bekleme) kilitsiz devam edilir.
        $raw = trim(TextPrep::foldFonts(TextPrep::stripInvisible((string) $payload['raw_message'])));
        $lock = Cache::lock('intake:lock:'.hash('sha256', self::normalizeText($raw)), self::LOCK_TTL_SECONDS);
        try {
            return $lock->block(self::LOCK_WAIT_SECONDS, fn () => $this->intakeUnlocked($payload, $raw));
        } catch (LockTimeoutException) {
            return $this->intakeUnlocked($payload, $raw);
        }
    }

    /** Tek kayıt bu kilidi bekler; yapay zeka çağrısı dahil bir mesajın işlenmesi bundan uzun sürmez. */
    public const LOCK_TTL_SECONDS = 90;

    public const LOCK_WAIT_SECONDS = 25;

    /**
     * @param  array{group_name:string, raw_message:string, sender_phone?:?string, message_id?:?string, source_jid?:?string, source_type?:string}  $payload
     * @return array{code:int, success:bool, message:string, status:string, scraped_load_id?:int, reason?:string, created_ids?:list<int>, segments?:list<array>}
     */
    private function intakeUnlocked(array $payload, string $raw): array
    {
        $sourceId = (string) ($payload['source_jid'] ?? $payload['group_name']);
        $sourceType = (string) ($payload['source_type'] ?? 'whatsapp');
        $messageId = (string) ($payload['message_id'] ?? '');

        // 1) Birebir aynı teslimat (daemon yeniden bağlanınca aynı mesajı tekrar yollar).
        $contentHash = hash('sha256', $sourceId.'|'.$messageId.'|'.$raw);
        $groupName = (string) $payload['group_name'];
        if ($existing = ScrapedLoad::query()->where('content_hash', $contentHash)->first()) {
            $this->noteSighting($existing, $groupName); // aynı grupta aynı metin yeniden paylaşıldı: kayıt tazelenir

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
            $recent = $this->recentByText($normalizedHash);
            if ($recent) {
                $this->noteSighting($recent, $groupName);

                return $this->result(200, true, 'duplicate', 'Aynı ilan yeniden paylaşıldı; kayıt tazelendi.', $recent->id);
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

                Cache::put(NotificationIntakeParser::screenSeenKey($groupName, $raw), 1, now()->addMinutes(10)); // ekran dökümü gönderisi kaynak onaylanınca yeniden alınsın (10 dk içinde tekrar değil)

                return $this->result(202, false, 'source_deleted', 'Kaynak silinmiş; mesaj yok sayıldı.');
            }
            if (! $scraper->exists) {
                // Aynı yeni gruptan iki mesaj aynı anda gelince ikisi de kaynağı açmaya çalışır; ikincisi
                // tekil anahtara takılır. Hata yerine ilkinin açtığı kayıt okunur, mesaj normal işlenir.
                try {
                    $scraper->save();
                } catch (UniqueConstraintViolationException) {
                    $scraper = Scraper::withTrashed()->where('source_identifier', $sourceId)->firstOrFail();
                }
            }
            $scraper->forceFill(['last_message_at' => now()])->saveQuietly();
            if (! $scraper->is_active) {
                Cache::forget($seenKey); // kaynak açıldığında aynı metin yeniden gelebilsin

                Cache::put(NotificationIntakeParser::screenSeenKey($groupName, $raw), 1, now()->addMinutes(10)); // ekran dökümü gönderisi kaynak onaylanınca yeniden alınsın (10 dk içinde tekrar değil)

                return $this->result(202, false, 'source_pending', 'Kaynak yönetici onayı bekliyor.');
            }

            // 3b) Facebook ekranından gelen gönderi kesik ("… diğer") ya da aynı gönderinin tam hâli olabilir: tam hâli kuyruktaki
            // kesik kaydın yerini alır, kesik hâli tam kaydın tekrarı sayılır (kullanıcı geri kaydırınca yeniden görünür).
            if ($sourceType === 'facebook' && ($merged = $this->mergeTruncatedFacebookPost($scraper, $raw, $groupName)) !== null) {
                return $merged;
            }

            // 4) Telefonu olmayan mesaj ilan olarak kullanılamaz; yapay zekaya da gitmez (kota).
            $fallbackPhone = Phone::normalizeContact($payload['sender_phone'] ?? null);
            if (! self::hasPhone($raw) && $fallbackPhone === null) {
                return $this->result(200, false, 'filtered', 'İlan ölçütleri karşılanmadı.', null, 'phone_missing');
            }
            // Kiril/Arap alfabesiyle yazılmış (Rusça, Arapça…) mesaj Türkiye ilanı değildir; kota harcamadan elenir.
            if (IntakeLayers::enabled('foreign_script') && TextPrep::isForeignScript($raw)) {
                return $this->result(200, false, 'filtered', 'Yabancı alfabe; ilan değil.', null, 'foreign_script');
            }
            // Yöneticinin öğrettiği "ilan değil" ifadesi (satılık, iş arıyorum…) her kipte kota harcamadan eler.
            if (IntakeLayers::enabled('lexicon_not_load') && Lexicon::isNotLoad($raw)) {
                return $this->result(200, false, 'filtered', 'Sözlük: ilan değil ifadesi.', null, 'lexicon_not_load');
            }
            // Gruplardan öğrenilen sabit kalıplar: boş araç / şoför arayan / fatura reklamı / satılık → ilan değil.
            if (IntakeLayers::enabled('not_load_pattern') && self::isNotLoadPattern($raw)) {
                return $this->result(200, false, 'filtered', 'İlan değil (boş araç, şoför/eleman ilanı, reklam).', null, 'not_load_pattern');
            }
            $aiFirst = $this->parser->aiFirst();
            // Ucuz ön eleme her kipte: telefon + en az bir lojistik işaret (rota, tonaj, fiyat, araç/yük sözcüğü) yoksa yapay zekaya
            // da gitmez (eski "Her ilanda" kipi telefonu olan her sohbeti yapay zekaya yolluyordu; kota ve gecikme).
            // Bildirim başlığından gelen gönderen numarası ($fallbackPhone) da telefon sayılır: gövdede numara yazmayan ilan
            // eskiden "phone_missing" ile düşüyordu.
            if (IntakeLayers::enabled('logistics_signal') && ! self::looksLikeLoad($raw, $fallbackPhone)) {
                return $this->result(200, false, 'filtered', 'İlan ölçütleri karşılanmadı.', null, self::filterReason($raw, $fallbackPhone));
            }

            // 5) Mesajı ilanlara ayır. Kural: boş satır / rota satırı sınırları, ortak numara paylaşımı.
            $segments = self::splitSegments($raw, $fallbackPhone);
            $truncated = self::$lastTruncated;
            $ctx = ['raw' => $raw, 'source_id' => $sourceId, 'message_id' => $messageId, 'group' => $groupName, 'scraper' => $scraper,
                'fallback_phone' => $fallbackPhone, 'ai_first' => $aiFirst, 'message_ai' => ['status' => 'skipped', 'data' => null]];

            // Bilinen numara+rota tekrarları yapay zekaya gitmeden elenir (kota); kalanlar için yapay zeka çağrılır.
            $results = [];
            $pending = [];
            foreach ($segments as $i => $segment) {
                if (! empty($segment['pickup_missing'])) {
                    // Gölgedeki iki satır yorumu: tahmin (ilk yer kalkış, ikinci varış) + yapay zeka hakemi örnek olur; ilan etkilenmez.
                    if (! empty($segment['shadow_two_line'])) {
                        $guess = $this->parser->parseCheap($segment['text'], $fallbackPhone);
                        IntakeLayerReview::recordShadow('two_line_route', $segment['text'], $guess['pickup_location'] ?? null, $guess['delivery_location'] ?? null);
                    }
                    // Gönderen hafızası: bu numaranın bilinen kalkışı varsa her satır o kalkıştan "bilgi eksik" ilan olur; yoksa aday
                    // kuyrukta "Kalkış öğret" ile bekler (yönetici bir kez öğretir, sonrası kendiliğinden).
                    if (IntakeLayers::enabled('sender_pickup_memory') && ($memory = $this->pickupFromSenderMemory($segment, $ctx)) !== null) {
                        foreach ($memory as $j => $sub) {
                            $results[$i * 1000 + $j] = $this->processSegment($sub + ['parsed' => $this->seriesParsed($sub, $fallbackPhone), 'skip_ai' => true], $ctx);
                        }

                        continue;
                    }
                    if (IntakeLayers::enabled('sender_pickup_memory') && ($holder = $this->holdForPickup($segment, $ctx)) !== null) {
                        $results[$i] = $holder;

                        continue;
                    }
                    $results[$i] = $this->result(200, false, 'filtered', 'Kalkış yeri yazmıyor; yalnız varış ve araç listesi.', null, 'pickup_missing') + ['excerpt' => $segment['text']];

                    continue;
                }
                $parsed = $this->parser->parseCheap($segment['text'], $fallbackPhone);
                if (($parsed['sender_phone'] ?? null) === null && $segment['phones'] !== []) {
                    $parsed['sender_phone'] = $segment['phones'][0];
                }
                self::applyVehicleContext($segment, $parsed);
                // Seri ilan (tek yükleme, çok boşaltma): rota başlıktan ve satırdan kesin bilinir; yapay zeka çağrılmaz,
                // rota tekrarı ilçe düzeyinde bakılır (aynı ilin her ilçesi ayrı iş).
                $isSeries = isset($segment['series']);
                $districtLevel = $isSeries || ! empty($segment['district_level']); // aynı mesajda aynı il çiftinin ilçeleri ayrı ilan
                if ($isSeries) {
                    $parsed['pickup_location'] = $segment['series']['pickup'];
                    $parsed['delivery_location'] = $segment['series']['delivery'];
                    $parsed['success'] = true;
                    unset($parsed['reason']);
                }
                // Kural parçası hâlâ birden çok ilan barındırıyor olabilir (birden çok numara / ikiden çok il):
                // yapay zeka öncelikli kipte böyle bir parça tekrar sayılmaz, yapay zekanın ayırmasına bırakılır.
                $mayHoldSeveral = ! $isSeries && $aiFirst && (count($segment['phones']) > 1 || count(AiParserService::provincesIn($segment['text'], 3)) > 2);
                // Yapay zeka öncelikli kipte yapay zekadan ÖNCE rota tekrarı yalnız iki uç da katalogda birebir çözülüyorsa bakılır;
                // kuralın yakın eşlemeyle/sözlükle bulduğu rota yanlışsa parça yanlış bir ilanın tekrarı sayılıp kaybolmasın
                // (asıl tekrar denetimi yapay zeka birleştirmesinden sonra, processSegment içinde).
                $strongEnds = $isSeries || ! $aiFirst || (TurkishLocations::resolveCatalog($parsed['pickup_location'] ?? null) !== null && TurkishLocations::resolveCatalog($parsed['delivery_location'] ?? null) !== null);
                if (! $mayHoldSeveral && $strongEnds && ($sameRoute = $this->recentSameRoute($parsed, null, $districtLevel)) && ! $this->materiallyDifferent($sameRoute, $segment['text'], $parsed)) {
                    $this->noteSighting($sameRoute, $groupName);
                    $results[$i] = $this->result(200, true, 'duplicate', 'Aynı numara ve rota yakın zamanda kaydedildi.', $sameRoute->id) + ['excerpt' => $segment['text']];

                    continue;
                }
                if ($isSeries) {
                    $results[$i] = $this->processSegment($segment + ['parsed' => $parsed, 'skip_ai' => true], $ctx);

                    continue;
                }
                // Şablon hafızası: aynı numaranın daha önce doğrulanmış kalıbı varsa yapay zekaya gitmeden çözülür.
                $phone = $parsed['sender_phone'] ?? null;
                if (is_string($phone) && ! $mayHoldSeveral && IntakeLayers::enabled('template_memory')) {
                    $sig = TemplateMemory::signature($segment['text']);
                    $template = $this->templates->find($phone, $sig['hash']);
                    if ($template !== null && ! $template->is_load) {
                        $results[$i] = $this->result(200, false, 'filtered', 'Şablon: bu gönderenin bu kalıbı ilan değil.', null, 'template_not_load') + ['excerpt' => $segment['text']];

                        continue;
                    }
                    if ($template !== null && ($applied = $this->templates->apply($template, $sig, $parsed)) !== null) {
                        $results[$i] = $this->processSegment($segment + ['parsed' => $applied, 'template' => $template], $ctx);

                        continue;
                    }
                }
                // Kural kesin (iki il katalogda birebir, telefon, açık araç adı): yapay zeka öncelikli kipte bile kota harcanmaz;
                // yapay zeka yalnız kuralın eksik ya da zayıf bıraktığı parçalara bakar.
                if ($aiFirst && ! $mayHoldSeveral && empty($segment['interpreted']) && IntakeLayers::enabled('rule_strong') && self::ruleStrong($parsed, $segment['text'])) {
                    $results[$i] = $this->processSegment($segment + ['parsed' => $parsed, 'rule_strong' => true], $ctx);

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
                    if ($ads === [] && ($aiData['is_load'] ?? true) === false && (float) ($aiData['confidence'] ?? 0) >= 0.8 && IntakeLayers::enabled('ai_not_load')) {
                        // Gönderenin bu kalıbı "ilan değil" olarak öğrenilir; aynı kalıp bir daha yapay zekaya sorulmaz.
                        foreach ($pending as $segment) {
                            if (is_string($segment['parsed']['sender_phone'] ?? null)) {
                                $this->templates->learn($segment['parsed']['sender_phone'], $segment['text'], null, null, null, null, false, (float) $aiData['confidence']);
                            }
                        }

                        return $this->result(200, false, 'filtered', 'Yapay zeka: yük ilanı değil.', null, 'ai_not_load');
                    }
                    if ($ads !== []) {
                        // Parça anahtarları korunur: yeniden dizinlenince erken sonuçlar (tekrar/elenen) eziliyor, canlı akışta satır kayboluyordu.
                        $origKeys = array_keys($pending);
                        $fromAi = $this->segmentsFromAi($ads, array_values($pending), $raw, $fallbackPhone);
                        $pending = [];
                        foreach (array_values($fromAi) as $j => $seg) {
                            $pending[$origKeys[$j] ?? (1000 + $j)] = $seg;
                        }
                    }
                }
            }

            foreach ($pending as $i => $segment) {
                $results[$i] = $this->processSegment($segment, $ctx);
            }
            ksort($results);
        } catch (Throwable $e) {
            Cache::forget($seenKey); // yeniden denenebilsin
            foreach ($this->segmentSeenKeys as $k) {
                Cache::forget($k); // yarım kalan parçalar da 24 saat "görüldü" diye kilitli kalmasın
            }
            if ($e instanceof \PDOException || $e instanceof \RedisException) {
                throw $e; // altyapı hatası (veritabanı/önbellek kopması): kuyruk işi yeniden dener (tries=2); yutulursa ilan kalıcı kaybolurdu
            }
            Log::error('Dış kaynak ilanı ayrıştırılamadı.', ['exception' => $e::class, 'error' => $e->getMessage()]);

            return $this->result(503, false, 'failed', 'Mesaj işlenemedi.', null, $e::class);
        }

        return $this->aggregate(array_values($results), $truncated);
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
                $this->noteSighting($existing, $groupName);

                return $this->result(200, true, 'duplicate', 'İlan daha önce işlendi.', $existing->id) + ['excerpt' => $text];
            }
            if (! Cache::add($seenKey, 1, now()->addHours(24))) {
                $first = ScrapedLoad::query()->where('normalized_hash', $normalizedHash)->latest('id')->first();
                if ($first) {
                    $this->noteSighting($first, $groupName);

                    return $this->result(200, true, 'duplicate', 'Aynı ilan başka bir kaynaktan işleniyor veya işlendi.', $first->id) + ['excerpt' => $text];
                }
            } else {
                $this->segmentSeenKeys[] = $seenKey;
            }
            $recent = $this->recentByText($normalizedHash);
            if ($recent) {
                $this->noteSighting($recent, $groupName);

                return $this->result(200, true, 'duplicate', 'Aynı ilan yeniden paylaşıldı; kayıt tazelendi.', $recent->id) + ['excerpt' => $text];
            }
        }

        $parsed = $segment['parsed'] ?? $this->parser->parseCheap($text, $ctx['fallback_phone']);
        $phones = array_values(array_unique(array_merge($segment['phones'], (array) ($parsed['phones'] ?? []))));
        if (($parsed['sender_phone'] ?? null) === null && $phones !== []) {
            $parsed['sender_phone'] = $phones[0];
        }
        $cheap = $parsed; // kuralın tek başına okuduğu (öğrenme çemberi yapay zekayla karşılaştırır)
        $ai = ['status' => 'skipped', 'data' => null];

        if (isset($segment['template'])) {
            // Şablon hafızası: aynı gönderenin doğrulanmış kalıbı; yapay zeka doğrulaması sayılır.
            $ai = ['status' => 'done', 'data' => ['provider' => 'template', 'model' => null, 'is_load' => true, 'confidence' => (float) $segment['template']->confidence,
                'notes' => 'Aynı gönderenin daha önce doğrulanmış ilan kalıbı', 'template_id' => $segment['template']->id]];
        } elseif (! empty($segment['skip_ai']) || ! empty($segment['rule_strong'])) {
            // Seri ilan ya da kesin kural (iki il + telefon + açık araç adı): yapay zeka kotası harcanmaz.
        } elseif (isset($segment['ai'])) {
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

        // Yorum katmanı (iki satırlık ilan): rota kesin kuralla değil sırayla çözüldü. Yapay zeka iki ucu da verdiyse onun rotası
        // geçerlidir ve rota "doğrulandı" sayılır; vermediyse ilan yorumla, "bilgi eksik" rozetiyle yayınlanır (autoApprovalBlocker).
        $interpreted = $segment['interpreted'] ?? null;
        $routeConfirmed = false;
        if ($interpreted === 'two_line' && $ai['data'] !== null && ($ai['data']['is_load'] ?? true) !== false
            && TurkishLocations::resolve((string) ($ai['data']['pickup_location'] ?? '')) !== null && TurkishLocations::resolve((string) ($ai['data']['delivery_location'] ?? '')) !== null) {
            $parsed['pickup_location'] = $ai['data']['pickup_location'];
            $parsed['delivery_location'] = $ai['data']['delivery_location'];
            unset($parsed['ai_conflict']);
            $routeConfirmed = true;
        }

        // Yapay zeka yüksek güvenle "bu bir yük ilanı değil" dediyse (sohbet, araç satışı, iş ilanı…) elenir;
        // aynı gönderenin bu kalıbı bir daha yapay zekaya sorulmaz.
        if (($ai['data']['is_load'] ?? true) === false && (float) ($ai['data']['confidence'] ?? 0) >= 0.8 && IntakeLayers::enabled('ai_not_load')) {
            if (is_string($parsed['sender_phone'] ?? null)) {
                $this->templates->learn($parsed['sender_phone'], $text, null, null, null, null, false, (float) $ai['data']['confidence']);
            }

            return $this->result(200, false, 'filtered', 'Yapay zeka: yük ilanı değil.', null, 'ai_not_load') + ['excerpt' => $text];
        }
        // Yerel sınıflandırıcı (dış servisten bağımsız): yapay zeka bakmadıysa ve yeterince öğrenmişse çok düşük olasılıklı
        // metni eler; olasılık kayda yazılır (otomatik onay yapay zeka ulaşılamadığında bunu kullanır).
        $local = Settings::bool('scraper_local_enabled') ? $this->classifier->score($text) : null;
        if ($local !== null && $ai['data'] === null && $local < 0.15 && $this->classifier->canFilter()) {
            return $this->result(200, false, 'filtered', 'Yerel sınıflandırıcı: ilan değil.', null, 'local_not_load') + ['excerpt' => $text];
        }

        $phones = array_values(array_unique(array_filter(array_merge(
            [$parsed['sender_phone'] ?? null], $phones, (array) ($parsed['phones'] ?? []), [$ctx['fallback_phone']]
        ), fn ($p) => is_string($p) && AiParserService::isAdPhone($p))));
        $phone = $phones[0] ?? null;
        if ($phone === null) {
            return $this->result(200, false, 'filtered', 'İlan ölçütleri karşılanmadı.', null, 'phone_missing') + ['excerpt' => $text];
        }
        $parsed['sender_phone'] = $phone;
        $parsed['success'] = ! empty($parsed['pickup_location']) && ! empty($parsed['delivery_location']);
        // Yapay zeka ulaşılamadı (kota/ağ) ve kural rotanın yalnız bir ucunu çözdü: parça kaybolmasın; eksik kayıt olarak açılır
        // (status parsed_partial, ai_status pending) ve kuyruk (ai-enrich) yapay zeka gelince tamamlar. Eski sürüm "route_missing" ile eliyordu.
        $partialPending = $parsed['success'] !== true && $ai['status'] === 'pending' && $this->resolvesOneEnd($parsed);
        if ($parsed['success'] !== true && ! $partialPending) {
            return $this->result(200, false, 'filtered', 'İlan ölçütleri karşılanmadı.', null, $parsed['reason'] ?? 'route_missing') + ['excerpt' => $text];
        }

        // 6) Aynı numara aynı rotayı kısa aralıkla farklı sözcüklerle paylaşmışsa tek ilan kalır. Fiyat / tonaj / araç / yük / tarih
        // değişmişse bu yeni bir ilandır: kayıt açılır ve eskisinin yerine geçer (yayındaysa onay anında arşivlenir).
        $isSeries = isset($segment['series']);
        $districtLevel = $isSeries || ! empty($segment['district_level']);
        $routeKey = self::routeKey($phone, $parsed['pickup_location'] ?? null, $parsed['delivery_location'] ?? null, $districtLevel);
        $supersedes = null;
        if (! $partialPending && ($sameRoute = $this->recentSameRoute($parsed, $phone, $districtLevel))) {
            if (! $this->materiallyDifferent($sameRoute, $text, $parsed)) {
                $this->noteSighting($sameRoute, $groupName);

                return $this->result(200, true, 'duplicate', 'Aynı numara ve rota yakın zamanda kaydedildi.', $sameRoute->id) + ['excerpt' => $text];
            }
            $supersedes = $sameRoute;
        }

        // Standartlaştırma: konum kataloğu (yazım hatası toleranslı), yük kategorisi, araç tipi, tonaj, fiyat, aciliyet.
        $std = $this->standardizer->standardize($text, $parsed);
        $extraPhones = array_values(array_slice($phones, 1));
        // Aynı yük başka numarayla (komisyoncu): ayrı ilan olarak yayınlanır, iki kart da "Benzer ilan" rozeti taşır (Osman, 2026-10-05).
        $similar = $this->similarWithOtherPhone($std, $text, $phones);

        /** @var Scraper $scraper */
        $scraper = $ctx['scraper'];
        $scraper->update(['last_scraped_at' => now(), 'last_success_at' => now(), 'last_error' => null]);
        $scrapedLoad = $this->createLoad([
            'scraper_id' => $scraper->id,
            'content_hash' => $contentHash,
            'normalized_hash' => $normalizedHash,
            'route_key' => $routeKey,
            'duplicate_count' => 1,
            'seen_sources' => [$groupName],
            'last_seen_at' => now(),
            'sighting_count' => 1,
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
            'vehicle_any' => $std['vehicle_any'],
            'body_types' => $std['body_types'],
            'body_type_source' => $std['body_type_source'],
            'load_kind' => $std['load_kind'],
            'vehicle_count' => $std['vehicle_count'],
            'delivery_stops' => $std['delivery_stops'],
            'weight' => $std['weight'],
            'price' => $std['price'],
            'price_unit' => $std['price_unit'],
            'currency' => $std['currency'],
            'status' => (($std['pickup_province_code'] !== null || ! empty($std['metadata']['international']['pickup'])) && ($std['delivery_province_code'] !== null || ! empty($std['metadata']['international']['delivery']))) ? 'parsed_success' : 'parsed_partial',
            'parsed_by_llm' => $parsed['parsed_by_llm'] ?? 'unknown',
            'parse_confidence' => $ai['data']['confidence'] ?? null,
            'ai_status' => $ai['status'],
            'ai_checked_at' => in_array($ai['status'], ['done', 'failed'], true) ? now() : null,
            'parse_metadata' => array_merge($std['metadata'], array_filter([
                'ai' => $ai['data'] !== null ? array_intersect_key($ai['data'], array_flip(['provider', 'model', 'confidence', 'notes', 'pickup_date_text', 'multiple_loads', 'is_load', 'ad_index', 'ad_count', 'template_id'])) : null,
                'ai_conflict' => $parsed['ai_conflict'] ?? null,
                // Aynı ilandaki diğer numaralar (şifreli); ilk numara ana kolonda.
                'extra_phones_enc' => $extraPhones !== [] ? array_map(fn (string $p) => Crypt::encryptString($p), $extraPhones) : null,
                'phone_count' => count($phones) > 1 ? count($phones) : null,
                'local_confidence' => $local,
                'message_part' => $isWhole ? null : ['index' => $segment['index'] ?? null, 'count' => $segment['count'] ?? null, 'hash' => substr(hash('sha256', $raw), 0, 16)],
                // Seri ilan: aynı kalkıştan çok noktaya, her nokta ayrı araç (kartta rozet).
                'series' => $isSeries ? ['count' => (int) $segment['series']['count'], 'pickup' => $segment['series']['pickup']] : null,
                // Aynı numara + rota ama fiyat/tonaj/araç/yük/tarih değişti: bu kayıt eskisinin yerine geçer (onayda eski arşivlenir).
                'supersedes' => $supersedes?->id,
                // Aynı yük başka numarayla paylaşılmış (komisyoncu olabilir): kimlikler; karşı kayıtlara da yazılır.
                'similar_to' => $similar !== [] ? array_map(fn (ScrapedLoad $l) => $l->id, $similar) : null,
                // Hangi okuma katmanı çözdü (panelde katman sayımı) ve rota yorumla mı çözüldü (yapay zeka doğrulamadıysa eksik bilgili yayın).
                'layer' => $segment['layer'] ?? ($isSeries ? 'series' : (isset($segment['template']) ? 'template_memory' : (! empty($segment['rule_strong']) ? 'rule_strong' : 'blocks'))),
                'route_inferred' => $interpreted,
                'route_confirmed' => $routeConfirmed ? true : null,
            ])),
            'visibility' => 'private',
            'retention_expires_at' => now()->addDays(30), // yayınlanmayan aday 30 gün sonra arşivlenir; yayınlananda yayın anından itibaren ayarlanır
        ]);

        $this->messageIds[] = $scrapedLoad->id;
        if ($supersedes !== null) {
            app(ScrapedLoadService::class)->supersede($supersedes, $scrapedLoad);
        }
        foreach ($similar as $twin) {
            $ids = array_values(array_unique(array_merge((array) $twin->meta('similar_with', []), [$scrapedLoad->id])));
            ScrapedLoad::query()->whereKey($twin->id)->update(['parse_metadata' => array_merge((array) $twin->parse_metadata, ['similar_with' => $ids])]);
        }
        if ($partialPending) {
            return $this->result(201, true, 'created', 'Rotanın bir ucu çözüldü; yapay zeka sırada, kayıt eksik olarak açıldı.', $scrapedLoad->id) + ['excerpt' => $text];
        }

        // Öğrenme çemberi: yapay zekanın çözdüğü, kuralın çözemediği yazımlar sözlük ekranına öneri olur (onayla → kural öğrenir).
        if ($ai['status'] === 'done' && $ai['data'] !== null) {
            app(RuleFeedbackService::class)->fromAi($cheap, $ai['data'], $text, $scrapedLoad->id);
        }

        // Yapay zeka yüksek güvenle çözdüyse bu gönderenin kalıbı öğrenilir; sonraki aynı kalıp yapay zekasız okunur.
        if (($ai['data']['provider'] ?? null) !== 'template' && $ai['status'] === 'done' && (float) ($ai['data']['confidence'] ?? 0) >= 0.8
            && empty($parsed['ai_conflict']) && ($std['pickup_province_code'] !== null || ! empty($std['metadata']['international']['pickup']))
            && ($std['delivery_province_code'] !== null || ! empty($std['metadata']['international']['delivery']))) {
            // Araç yalnız kural kesin okuduysa kalıba yazılır: yapay zekanın tahmini araç sonraki mesajlara "kesin" diye taşınmaz
            $templateVehicle = ($std['vehicle_type_source'] ?? null) === 'keyword' ? $std['vehicle_type'] : null;
            $this->templates->learn($phone, $text, $std['pickup_location'], $std['delivery_location'], $templateVehicle, $ai['data']['goods_category'] ?? null, true, (float) $ai['data']['confidence'], $scrapedLoad->id);
        }

        return $this->result(201, true, 'created', 'İlan adayı kaydedildi.', $scrapedLoad->id) + ['excerpt' => $text];
    }

    /**
     * Parça sonuçlarını tek yanıta indirger: biri bile kaydedildiyse "created" (ilk kimlik + tüm kimlikler),
     * yoksa tekrar, yoksa elendi (ilk gerekçe). Her parçanın sonucu "segments" altında (canlı akış satırları).
     */
    private function aggregate(array $results, int $truncated = 0): array
    {
        $created = array_values(array_filter($results, fn ($r) => $r['status'] === 'created'));
        $duplicates = array_values(array_filter($results, fn ($r) => $r['status'] === 'duplicate'));
        $segments = array_map(fn (array $r) => array_intersect_key($r, array_flip(['status', 'message', 'reason', 'scraped_load_id', 'excerpt'])), $results);
        $count = count($results);

        $extra = $truncated > 0 ? ['truncated_ads' => $truncated] : [];

        if ($created !== []) {
            $ids = array_map(fn ($r) => $r['scraped_load_id'], $created);
            $message = count($ids) > 1 ? count($ids).' ilan adayı kaydedildi (mesajda '.$count.' ilan bulundu).' : ($count > 1 ? 'İlan adayı kaydedildi (mesajdaki diğer '.($count - 1).' ilan tekrar/elendi).' : 'İlan adayı kaydedildi.');
            if ($truncated > 0) {
                $message .= " Mesajda {$truncated} ilan daha vardı; sınır (".self::MAX_ADS_PER_MESSAGE.') aşıldı.';
            }
            // Tek parça ve özel bir "kaydedildi" durumu (kalkış bekleyen aday): mesaj ve gerekçe üst sonuca taşınır (canlı akış satırı).
            if (count($created) === 1 && $count === 1 && isset($created[0]['reason'])) {
                return $this->result(201, true, 'created', $created[0]['message'], $ids[0], $created[0]['reason']) + ['created_ids' => $ids, 'segments' => $segments] + $extra;
            }

            return $this->result(201, true, 'created', $message, $ids[0]) + ['created_ids' => $ids, 'segments' => $segments] + $extra;
        }
        if ($duplicates !== []) {
            return $this->result(200, true, 'duplicate', $count > 1 ? 'Mesajdaki ilanlar daha önce alınmış.' : $duplicates[0]['message'], $duplicates[0]['scraped_load_id'] ?? null) + ['segments' => $segments] + $extra;
        }
        $first = $results[0] ?? $this->result(200, false, 'filtered', 'İlan ölçütleri karşılanmadı.', null, 'route_missing');

        return array_intersect_key($first, array_flip(['code', 'success', 'status', 'message', 'reason', 'scraped_load_id'])) + ['segments' => $segments] + $extra;
    }

    /**
     * Aynı numara + rota kaydıyla yeni parça "aynı ilan" mı, "değişmiş ilan" mı? Ucuz alanlar karşılaştırılır: iki tarafta da
     * yazılı olup farklı olan fiyat, tonaj, araç tipi, yük türü ya da yükleme günü → farklı ilan (yeni kayıt açılır, eski yerini bırakır).
     * Bir tarafta yazmayan alan fark sayılmaz (aynı ilanın kısa tekrarı).
     */
    private function materiallyDifferent(ScrapedLoad $existing, string $text, array $parsed): bool
    {
        $std = $this->standardizer->standardize($text, $parsed);
        $differs = fn ($a, $b): bool => $a !== null && $a !== '' && $b !== null && $b !== '' && (string) $a !== (string) $b;
        // Daha dolu paylaşım: eski kayıtta araç/fiyat/tonaj yokken yeni parça getiriyorsa yeni kayıt eskisinin yerine geçer (bilgi yutulmaz).
        // Reddedilmiş kayıt için geçerli değil: yöneticinin "ilan değil" dediği metnin eksik alanı tamamlanınca ilan olmaz.
        $fillable = $existing->status !== 'rejected';
        if ($fillable && ! $existing->vehicle_type && ! $existing->vehicle_any && ($std['vehicle_any'] || ($std['vehicle_type'] && in_array($std['vehicle_type_source'], ['keyword', 'template'], true)))) {
            return true;
        }
        if ($fillable && (($std['price'] !== null && $existing->price === null) || ($std['weight'] !== null && $existing->weight === null))) {
            return true;
        }
        if ($differs($std['price'] !== null ? (float) $std['price'] : null, $existing->price !== null ? (float) $existing->price : null)) {
            return true;
        }
        if ($differs($std['weight'], $existing->weight)) {
            return true;
        }
        // Aynı il çifti ama iki kayıtta da ilçe açıkça yazılı ve farklı (Polatlı→Tuzla, Sincan→Esenyurt): ayrı ilanlar.
        foreach (['pickup_district', 'delivery_district'] as $col) {
            if ($differs($std[$col], $existing->{$col})) {
                return true;
            }
        }
        if ($differs($std['vehicle_type'], $existing->vehicle_type) && in_array($std['vehicle_type_source'], ['keyword', 'hint'], true)
            && in_array($existing->vehicle_type_source, ['keyword', 'hint', 'admin', 'ai', 'template'], true)) {
            return true;
        }
        if ($differs($std['metadata']['goods_category'] ?? null, $existing->meta('goods_category'))) {
            return true;
        }

        return $differs($std['metadata']['pickup_note'] ?? null, $existing->meta('pickup_note'));
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
        // Yapay zeka kuralın bulduğu farklı il çiftlerinden daha az ilan döndürdüyse ilanları birleştirmiş demektir: kural parçaları
        // korunur, her parçaya il çifti eşleşen yapay zeka ilanının alanları iliştirilir (eşleşmeyen parça yapay zekasız sürer).
        $pairOf = function (?string $pickup, ?string $delivery): ?string {
            $p = is_string($pickup) ? (TurkishLocations::resolve($pickup)['province_code'] ?? null) : null;
            $d = is_string($delivery) ? (TurkishLocations::resolve($delivery)['province_code'] ?? null) : null;

            return $p !== null && $d !== null ? $p.'|'.$d : null;
        };
        $rulePairs = array_values(array_unique(array_filter(array_map(fn (array $s) => $pairOf($s['parsed']['pickup_location'] ?? null, $s['parsed']['delivery_location'] ?? null), $ruleSegments))));
        if (count($ruleSegments) > 1 && count($rulePairs) > $count) {
            $out = [];
            foreach ($ruleSegments as $i => $segment) {
                $pair = $pairOf($segment['parsed']['pickup_location'] ?? null, $segment['parsed']['delivery_location'] ?? null);
                foreach ($ads as $ad) {
                    if ($pair !== null && $pairOf($ad['pickup_location'] ?? null, $ad['delivery_location'] ?? null) === $pair) {
                        $segment['ai'] = $ad;
                        break;
                    }
                }
                $segment['phones'] = self::withFallback($segment['phones'], $fallbackPhone);
                $out[] = $segment + ['index' => $i, 'count' => count($ruleSegments)];
            }

            return $out;
        }
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
                    ($ad['price'] ?? null) ? number_format((float) $ad['price'], 0, ',', '.').' TL'.(($ad['price_unit'] ?? null) === 'per_ton' ? '/ton' : '') : null,
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
            $out[] = ['text' => $text, 'phones' => $phones, 'ai' => $ad, 'index' => $i, 'count' => $count]
                + (count($ruleSegments) === 1 ? array_intersect_key($ruleSegments[0], array_flip(['interpreted', 'layer'])) : []); // yorum katmanı damgası yapay zeka parçasına taşınır
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
        $prepared = TextPrep::prepare($raw); // biçim işaretleri, emoji oklar ve süs satırları (blok ayırıcı) temizlenir
        self::$lastTruncated = 0;
        // "Tek yükleme, çok boşaltma noktası" serisi: her "X boşaltır" satırı ayrı araçlık ilan (bkz. SeriesAd).
        if (IntakeLayers::enabled('series') && ($series = SeriesAd::segments($prepared, $fallbackPhone)) !== null) {
            return self::stampLayer($series, 'series');
        }
        // "İSTANBUL ÇIKIŞLI: Ankara, İzmir, Bursa …" / "… yükleme, Ankara, Konya, Kayseri boşaltma 3 araç": virgüllü varış listesi, her varış ayrı ilan.
        if (IntakeLayers::enabled('comma_list') && ($list = SeriesAd::commaList($prepared, $fallbackPhone)) !== null) {
            return self::stampLayer($list, 'comma_list');
        }
        // "Ankara-İstanbul / İstanbul-Ankara gidiş dönüş": iki ilan.
        if (IntakeLayers::enabled('round_trip') && ($trip = SeriesAd::roundTrip($prepared, $fallbackPhone)) !== null) {
            return self::stampLayer($trip, 'round_trip');
        }
        // "SAMSUN KAPALI TIR / İZMİR KAPALI TIR / ÇANAKKALE TENTELİ KAMYON": yalnız varış + araç listesi, kalkış yazmıyor.
        // Satırlar birbirine rota diye bağlanmaz ("Samsun → İzmir" uydurulmaz); tek parça, kalkış eksik gerekçesiyle elenir.
        // Yorum katmanı: fiilsiz TAM İKİ "yer + araç" satırı ("İSTANBUL HADIMKÖY TENTELİ TIR ⏎ ANKARA 2 ARAÇ"): ilk yer kalkış, ikinci yer
        // varış (Osman, 2026-10-05). Yapay zeka doğrulamazsa "bilgi eksik" rozetiyle yayınlanır (processSegment / autoApprovalBlocker).
        $destLines = SeriesAd::destinationOnlyLines($prepared);
        if (count($destLines) === 2 && IntakeLayers::enabled('two_line_route')) {
            return [['text' => $raw, 'phones' => self::withFallback(AiParserService::phonesIn($raw), $fallbackPhone), 'index' => 0, 'count' => 1, 'interpreted' => 'two_line', 'layer' => 'two_line_route']];
        }
        if ($destLines !== []) {
            // Gölgedeki iki satır yorumu: ne yapacağı örnek tablosuna yazılır (intakeUnlocked), ilan eskisi gibi kalkışsız liste sayılır.
            return [['text' => $raw, 'phones' => self::withFallback(AiParserService::phonesIn($raw), $fallbackPhone), 'index' => 0, 'count' => 1, 'pickup_missing' => true,
                'shadow_two_line' => count($destLines) === 2 && IntakeLayers::isShadow('two_line_route'),
                'dest_lines' => array_map(fn ($d) => $d['line'], $destLines), 'notes' => self::notesOutside($prepared, array_map(fn ($d) => $d['line'], $destLines))]];
        }
        $units = [];
        $carryHeader = null; // varışı olmayan başlık ("Çorlu yükler") boş satırdan sonraki bloklara taşınır
        $carryPlace = null;
        foreach (preg_split('/\n[ \t]*\n+/u', $prepared) ?: [] as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }
            $current = [];
            $pair = null; // parçanın rotası [kalkış ili, varış ili]
            $provinces = []; // parçanın şimdiye kadarki farklı illeri (yazım sırasıyla)
            $hasPhone = false;
            $header = null; // "X yükler / yüklemeli / Xden" başlık satırı: sonraki her varış satırı ayrı ilan
            $headerPlace = null;
            $destInCurrent = false;
            $continuationNote = null; // rota başlığının ("Malkara'dan Adana damper") notu, altındaki varış satırlarına taşınır
            $blockLines = self::expandMultiDestinationLines(preg_split('/\n/u', $block) ?: []);
            $blockHasPairLine = array_filter($blockLines, fn ($l) => (self::isPlusChain($l) ? AiParserService::connectorPair($l) : AiParserService::routePair($l)) !== null) !== [];
            // Kalkış satırı + alt alta yalnız yer adı taşıyan satırlar ("İstanbul Kartal 13.60 açık ⏎ Sivas ⏎ Aydın ⏎ Bursa"):
            // sektör dilinde her satır ayrı araçlık yüktür; ilk satır başlık sayılır.
            // ("İstanbul-Kartal" gibi aynı ilin ilçesiyle yazımı rota sayılmaz.)
            $realPairLine = array_filter($blockLines, fn ($l) => ($p = self::isPlusChain($l) ? AiParserService::connectorPair($l) : AiParserService::routePair($l)) !== null && strtok($p[0], ' ') !== strtok($p[1], ' ')) !== [];
            // Önceki bloktan taşınan başlık ("Çorlu yükler" + boş satır + liste) varsa liste zaten o başlığa bağlanır.
            $listHeaderLine = $realPairLine || $carryHeader !== null ? null : self::listHeaderLine($blockLines);
            if ($listHeaderLine !== null) {
                // Başlıktaki "İl-İlçe" tiresi boşluk olur ki parça rotası başlık içindeki tireye değil listeye bakılsın.
                $blockLines[$listHeaderLine] = preg_replace('/(?<=\p{L})\s*[-–—\/]\s*(?=\p{L})/u', ' ', $blockLines[$listHeaderLine]) ?? $blockLines[$listHeaderLine];
            }
            if ($carryHeader !== null && ! $blockHasPairLine && AiParserService::placesIn($block, 1) !== []) {
                $header = $carryHeader;
                $headerPlace = $carryPlace;
                $current[] = $carryHeader;
            }
            foreach ($blockLines as $lineIndex => $line) {
                $lineProvinces = AiParserService::provincesIn($line, 2);
                // "Gönen+Merkez", "Çorum+Ankara+Denizli": aynı araçla sıralı boşaltma; rota değil, tek varış satırıdır
                // "+"lı satırda yalnız açık bağlaç rota sayılır ("Bursa - İstanbul+Lüleburgaz" rota; "Kütahya+Uşak" değil)
                $plusChain = self::isPlusChain($line);
                $linePair = $plusChain ? AiParserService::connectorPair($line) : AiParserService::routePair($line); // "Beykoz-Şanlıurfa" gibi ilçeli yazımlar da rota sayılır
                $split = false;
                $linePlaces = AiParserService::placesIn($line, 2);
                $lower = TurkishCities::lower(trim($line)); // "YÜKLEMELİ" gibi büyük İ'li sözcükler /i ile eşleşmez
                // Başlık: "X yükler/yüklemeli/yükleme", "Xden", "X DAN", "NEVŞEHİR DENGE BİMSDEN" (son sözcük ayrılma ekli)
                // Sondaki noktalama ("İSTANBULDAN:", "Samsundan;") başlığı bozmaz.
                $isHeader = $linePlaces !== [] && ! self::hasPhone($line)
                    && ($lineIndex === $listHeaderLine
                        || $linePair === null && (preg_match(AiParserService::PICKUP_VERBS, $lower) === 1
                        || (AiParserService::hasTrueAblative($lower) && (preg_match('/^\p{L}+(?:dan|den|tan|ten)[\p{P}\p{S}\s]*$/u', $lower) === 1
                        || preg_match('/^(?:\p{L}+\s+){0,3}\p{L}{4,}(?:dan|den|tan|ten)[\p{P}\p{S}\s]*$/u', $lower) === 1))
                        || preg_match('/^\p{L}+(?:\s+\p{L}+){0,3}\s+(?:dan|den|tan|ten)\s*[\p{P}\p{S}]*\s*(?:\p{L}+\s*){0,2}[\p{P}\p{S}\s]*$/u', $lower) === 1));
                if ($isHeader) {
                    // Yeni başlık: önceki başlığın son varışını kapat.
                    if ($header !== null && $destInCurrent && $current !== []) {
                        $units[] = self::unit(implode("\n", $current));
                        $current = [];
                        $pair = null;
                        $provinces = [];
                        $hasPhone = false;
                    }
                    $header = $line;
                    $headerPlace = $linePlaces[0]['label'];
                    $destInCurrent = false;
                } elseif ($header !== null && $linePair === null && $linePlaces !== [] && $linePlaces[0]['label'] !== $headerPlace) {
                    // Başlık altındaki varış satırı ("İSTANBUL ESENYURT", "DENİZLİ BOŞALTIR", "Çorlu damperli 15 araç")
                    if ($destInCurrent) {
                        $units[] = self::unit(implode("\n", $current));
                        $current = [$header];
                        $pair = null;
                        $provinces = [];
                        $hasPhone = false;
                    }
                    $destInCurrent = true;
                }
                // Rota satırının altında kalkışsız varış satırı ("izmir kemalpaşa'dan diyarbakır hani bir tır ⏎ diyarbakır merkez 4 tır",
                // "Malkara'dan Adana damper ⏎ Çukurova 2.100+kdv ⏎ Pozantı 2.100+kdv"): aynı kalkıştan ayrı ilandır; eskiden önceki ilana
                // yapışıp kayboluyordu (Engin Abi, 2026-10-08). Satır "kalkış -> satır" biçimine çevrilir. Rota satırı yalnız il yazıp
                // fiyat/adet taşımıyorsa ("Malkara'dan Adana damper") başlıktır: kendi başına ilan olmaz, notları alt satırlara geçer.
                if ($current !== [] && $pair !== null && $linePair === null && ! $isHeader && $header === null && self::isDestinationContinuation($line, $lower, $linePlaces, $pair)) {
                    $pickupLabel = $pair[0];
                    $headOnly = count($current) === 1 && ! self::hasPhone($current[0]) && ! self::hasPriceOrCount($current[0]);
                    $sameProvince = strtok($linePlaces[0]['label'], ' ') === strtok($pair[1], ' ');
                    if ($headOnly && $sameProvince) {
                        $continuationNote = $current[0]; // "Malkara'dan Adana damper": araç/kasa notu her alt satıra taşınır
                    } else {
                        $units[] = self::unit(implode("\n", $current));
                    }
                    $current = [];
                    $pair = null;
                    $provinces = [];
                    $hasPhone = false;
                    $line = $pickupLabel.' -> '.trim($line).($continuationNote !== null ? "\n".$continuationNote : '');
                    $linePair = AiParserService::routePair($line);
                    $lineProvinces = AiParserService::provincesIn($line, 2);
                }
                if ($current !== [] && $pair !== null) {
                    // Parça zaten bir rota taşıyor: farklı rotalı satır ("Bursa-Konya 10 ton") yeni ilan;
                    // numarası da yazılmış tam bir ilandan sonra yeni bir il satırı ("📍 Bursa") yeni ilan.
                    if ($linePair !== null && self::pairDiffers($pair, $linePair)) {
                        $split = true;
                    } elseif ($hasPhone && $lineProvinces !== [] && ! in_array($lineProvinces[0], $provinces, true) && ! in_array($lineProvinces[0], array_map(fn ($v) => (string) strtok($v, ' '), $pair), true)) {
                        $split = true;
                    }
                } elseif ($current !== [] && count($provinces) >= 2 && $hasPhone && $lineProvinces !== [] && ! in_array($lineProvinces[0], $provinces, true)) {
                    $split = true;
                }
                if ($split) {
                    $units[] = self::unit(implode("\n", $current));
                    $current = [];
                    $pair = null;
                    $provinces = [];
                    $hasPhone = false;
                    if ($linePair !== null) {
                        $header = null; // bağlaçlı rota satırları başlık düzenini bitirir
                        $destInCurrent = false;
                    }
                }
                $current[] = $line;
                $pair ??= $linePair;
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
            if ($header !== null && ! $destInCurrent) {
                $carryHeader = $header; // "Çorlu yükler" + boş satır + varış listesi
                $carryPlace = $headerPlace;
            } elseif ($header !== null || $pair !== null) {
                $carryHeader = null;
                $carryPlace = null;
            }
        }
        // Yalnız numara/isim taşıyan parçalar ("0533 811 88 10 ⏎ 0535 250 50 47") ilan değildir: numaraları komşuya geçer.
        $kept = [];
        foreach ($units as $unit) {
            $letters = preg_replace(AiParserService::PHONE_PATTERN, '', $unit['text']) ?? $unit['text'];
            if (! $unit['route'] && AiParserService::placesIn($unit['text'], 1) === [] && mb_strlen(preg_replace('/[^\p{L}]/u', '', $letters) ?? '') < 12) {
                $target = array_key_last($kept);
                if ($target !== null) {
                    $kept[$target] = self::join($kept[$target], $unit);
                } else {
                    $kept[] = $unit; // öndeyse sıradaki parçaya eklenir (aşağıdaki birleştirme)
                }

                continue;
            }
            $kept[] = $unit;
        }
        $units = $kept;
        if ($units === []) {
            return [['text' => $raw, 'phones' => self::withFallback(AiParserService::phonesIn($raw), $fallbackPhone), 'index' => 0, 'count' => 1]];
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

        // Ortak bağlam: mesajın başındaki/sonundaki yer adı içermeyen satırlar ("KAPALI TIR", "13-60 TENTELİ-FRİGO",
        // "ARAÇLAR DAMPER DORSE OLACAK", "ÖDEME PEŞİN") her ilana aittir; araç/yük/fiyat çıkarımı için her parçaya eklenir.
        $shared = [];
        foreach (preg_split('/\n[ \t]*\n+/u', $prepared) ?: [] as $block) {
            $block = trim($block);
            if ($block === '' || AiParserService::placesIn($block, 1) !== [] || AiParserService::routePair($block) !== null) {
                continue;
            }
            $stripped = trim(preg_replace(AiParserService::PHONE_PATTERN, ' ', $block) ?? $block);
            // Yalnız yük adı taşıyan blok ("Çuvallı yem", "Torbalı çimento") da ortak bağlamdır: her ilan yükü bilsin (Engin Abi, 2026-10-05).
            if (preg_match('/[\p{L}]{3,}/u', $stripped) && (preg_match('/(?<!\p{L})(?:tır|tir|dorse|damper|tente|frigo|firgo|kapalı|kapali|açık|acik|teker|kamyon|kamyonet|panelvan|araç|arac|ton|palet|kdv|peşin|pesin|nakit|dökme|dokme|13[.,\/\- ]?60|1360|8[.,]60|uzun|kısa|kisa|basar|tonaj)(?!\p{L})/iu', $stripped)
                || (mb_strlen($stripped) <= 60 && GoodsCatalog::detect(VehicleClassifier::normalize($stripped)) !== null))) {
                $shared[] = $stripped;
            }
        }
        $sharedText = implode("\n", array_unique($shared));

        // Mesaj bağlamı aracı: listedeki ilanların çoğu açıkça aynı aracı yazıyorsa ("… 2 tır", "… bir tır"), araç yazmayan satırlar
        // ("Akhisar'dan Kızıltepe bir araç") da o araçla **tahmin** olarak işaretlenir (kaynak ai_guess: yumuşak filtre, kesin değil).
        $vehicleContext = self::dominantVehicle($final);
        // Aynı il çiftine farklı ilçelerle giden parçalar ("Malkara → Konya Karapınar", "Malkara → Konya Bozkır") ayrı ilandır: rota anahtarı
        // ilçe düzeyinde tutulur ki ikincisi ilkinin tekrarı sayılıp reddedilmesin (seri ilanlardaki kuralın aynısı).
        $routePairs = array_map(fn ($u) => AiParserService::routePair($u['text']), $final);

        // Numarasız rota parçaları en yakın numaralı parçanın numaralarını devralır (ortak irtibat).
        $count = count($final);
        $out = [];
        foreach ($final as $i => $unit) {
            $phones = $unit['phones'];
            $inherited = false;
            if ($phones === []) {
                // En yakın numaralı parça (önce sonraki, sonra önceki; mesafe sınırsız: 10 bloklu listelerde numara sondadır)
                for ($offset = 1; $offset < $count && ! $inherited; $offset++) {
                    foreach ([$i + $offset, $i - $offset] as $j) {
                        if (isset($final[$j]) && $final[$j]['phones'] !== []) {
                            $phones = $final[$j]['phones'];
                            $inherited = true;
                            break;
                        }
                    }
                }
                if (! $inherited && ($all = AiParserService::phonesIn($raw)) !== []) {
                    $phones = $all;
                    $inherited = true;
                }
            }
            $phones = self::withFallback($phones, $fallbackPhone);
            $text = $unit['text'];
            if ($sharedText !== '' && ! str_contains($text, $sharedText)) {
                $text .= "\n".$sharedText;
            }
            if ($inherited && $phones !== []) {
                $text .= "\n☎️ ".implode(', ', array_map(fn (string $p) => Phone::format($p), $phones));
            }
            $segment = ['text' => $text, 'phones' => $phones, 'index' => $i, 'count' => $count];
            if ($vehicleContext !== null && VehicleClassifier::analyze($text)['source'] !== 'keyword') {
                $segment['vehicle_context'] = $vehicleContext;
            }
            if (self::sharesProvincePairWithOtherDistrict($routePairs, $i)) {
                $segment['district_level'] = true;
            }
            $out[] = $segment;
        }
        self::$lastTruncated = max(0, count($out) - self::MAX_ADS_PER_MESSAGE);

        return array_slice($out, 0, self::MAX_ADS_PER_MESSAGE);
    }

    /** Aynı mesajda aynı il çiftine başka bir ilçeyle giden parça var mı (varış ilçesi farklı ya da biri il geneli). */
    private static function sharesProvincePairWithOtherDistrict(array $pairs, int $i): bool
    {
        $p = $pairs[$i] ?? null;
        if ($p === null) {
            return false;
        }
        foreach ($pairs as $j => $q) {
            if ($j !== $i && $q !== null && strtok($q[0], ' ') === strtok($p[0], ' ') && strtok($q[1], ' ') === strtok($p[1], ' ') && $q[1] !== $p[1]) {
                return true;
            }
        }

        return false;
    }

    /** Mesaj bağlamı aracı (splitSegments) kuralın okuyamadığı parçaya tahmin olarak yazılır; açık araç sözcüğü ve ipucu dışı kaynaklar ezilmez. */
    public static function applyVehicleContext(array $segment, array &$parsed): void
    {
        if (empty($segment['vehicle_context'])) {
            return;
        }
        if (empty($parsed['vehicle_type']) || in_array($parsed['vehicle_type_source'] ?? null, ['hint', 'goods', null], true)) {
            $parsed['vehicle_type'] = $segment['vehicle_context'];
            $parsed['vehicle_type_source'] = 'ai_guess';
        }
    }

    /**
     * Araç yazan parçalar en az 3 ve tüm parçaların %40'ı, bunların %80'i aynı araçsa o araç mesaj bağlamıdır (yalnız ağır araçlar).
     *
     * @param  list<array{text:string}>  $units
     */
    private static function dominantVehicle(array $units): ?string
    {
        if (count($units) < 3) {
            return null;
        }
        $counts = [];
        foreach ($units as $unit) {
            $v = VehicleClassifier::analyze($unit['text']);
            if ($v['source'] === 'keyword' && $v['type'] !== null) {
                $counts[$v['type']] = ($counts[$v['type']] ?? 0) + 1;
            }
        }
        $explicit = array_sum($counts);
        if ($explicit < 3 || $explicit / count($units) < 0.4) {
            return null; // araç yazan satır azsa bağlam yok
        }
        arsort($counts);
        $type = (string) array_key_first($counts);

        return $counts[$type] / $explicit >= 0.8 && in_array($type, ['tir', 'kirkayak', '10_teker_kamyon', '8_teker_kamyon', '6_teker_kamyon'], true) ? $type : null;
    }

    /**
     * Rota satırının altındaki kalkışsız varış satırı mı: satır bir yer adıyla başlar, kalkış eki/fiili yoktur, adet / araç / fiyat
     * taşır ("diyarbakır merkez 4 tır", "Çukurova 2.100+kdv günlük 7-8 araç", "Pozantı 2.100+kdv") ve yer, rotanın iki ucundan da farklıdır.
     */
    private static function isDestinationContinuation(string $line, string $lower, array $linePlaces, array $pair): bool
    {
        if ($linePlaces === [] || self::hasPhone($line) || str_word_count($lower) > 12) {
            return false;
        }
        if (AiParserService::hasTrueAblative($lower) || preg_match(AiParserService::PICKUP_VERBS, $lower) === 1) {
            return false;
        }
        $head = implode(' ', array_slice(preg_split('/\s+/u', trim($lower)) ?: [], 0, 2));
        if (AiParserService::placesIn($head, 1) === []) {
            return false;
        }
        $label = $linePlaces[0]['label'];
        if ($label === $pair[0] || $label === $pair[1]) {
            return false;
        }

        return self::hasPriceOrCount($lower);
    }

    /** Satırda araç adedi, araç sözcüğü ya da fiyat var mı ("4 tır", "bir araç", "2.100+kdv", "1750 art"). */
    private static function hasPriceOrCount(string $text): bool
    {
        $lower = TurkishCities::lower($text);

        return preg_match('/\d{3,}|(?<!\p{L})(?:\d{1,2}|bir|iki|üç|uc|dört|dort|beş|bes|altı|alti|yedi|sekiz|dokuz|on)\s*(?:tır|tir|tr|tur|araç|arac|kamyon|adet)(?!\p{L})|(?<!\p{L})(?:tır|tir|araç|arac|kamyon|damper|kdv|artı|art|peşin|pesin|nakit)(?!\p{L})/u', $lower) === 1;
    }

    /**
     * Tek satırda kalkış + fiyatlı birden çok varış ("çan'dan muş 3400 artı kdv malatya 2700 + kdv"): her varış kendi fiyatıyla ayrı satır olur.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    private static function expandMultiDestinationLines(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $lower = preg_replace("/[’'‘`]/u", '', TurkishCities::lower($line)) ?? TurkishCities::lower($line); // "çan'dan" → "çandan"
            if (! preg_match('/^\W*((?:\p{L}+\s+){0,2}\p{L}{3,}(?:dan|den|tan|ten))(?!\p{L})/u', $lower, $head) || ! AiParserService::hasTrueAblative($lower)) {
                $out[] = $line;

                continue;
            }
            $pricePattern = '/(\d{1,3}(?:\.\d{3})+|\d{3,5})\s*,?\s*(?:\+|artı|arti|art)\s*(?:kdv)?(?!\p{L})/u';
            if (preg_match_all($pricePattern, $lower, $m, PREG_OFFSET_CAPTURE) < 2) {
                $out[] = $line;

                continue;
            }
            $chunks = [];
            $pos = 0;
            foreach ($m[0] as [$match, $offset]) {
                $end = $offset + strlen($match);
                $chunks[] = trim(substr($lower, $pos, $end - $pos));
                $pos = $end;
            }
            $tail = trim(substr($lower, $pos));
            if ($tail !== '') {
                $chunks[count($chunks) - 1] .= ' '.$tail;
            }
            // Kalkış gövdesi ("çan", "çanakkale çan") yer olmalı; ilk parçada ondan sonra bir varış, sonraki her parçada bir yer olmalı
            $stem = preg_replace('/(?:dan|den|tan|ten)$/u', '', $head[1]) ?? $head[1];
            $ok = TurkishLocations::resolve($stem) !== null && AiParserService::placesIn(mb_substr($chunks[0], mb_strlen($head[0])), 1) !== [];
            foreach (array_slice($chunks, 1) as $chunk) {
                $ok = $ok && AiParserService::placesIn($chunk, 1) !== [];
            }
            if (! $ok) {
                $out[] = $line;

                continue;
            }
            $out[] = $chunks[0];
            foreach (array_slice($chunks, 1) as $chunk) {
                $out[] = $head[1].' '.$chunk;
            }
        }

        return $out;
    }

    /** Parçalara hangi katmanın ürettiğini yazar (kayıtta parse_metadata.layer; panelde katman sayımı). */
    private static function stampLayer(array $segments, string $layer): array
    {
        return array_map(fn (array $s) => $s + ['layer' => $layer], $segments);
    }

    /** Listedeki yer satırları ve numaralar dışında kalan satırlar (araç, kasa, yük, tarih notları): her noktaya taşınır. */
    private static function notesOutside(string $prepared, array $destLines): array
    {
        $out = [];
        foreach (preg_split('/\n/u', $prepared) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || in_array($line, $destLines, true) || AiParserService::phonesIn($line) !== []) {
                continue;
            }
            $out[] = $line;
        }

        return array_values(array_unique($out));
    }

    /**
     * Kalkışı yazmayan liste için gönderenin bilinen kalkışı (SenderPickupMemory): her varış satırı o kalkıştan ayrı, "bilgi eksik"
     * rozetli ilan olur (seri ilan mekanizması: rota kesin, yapay zeka çağrılmaz, tekrar ilçe düzeyinde).
     *
     * @return list<array>|null
     */
    private function pickupFromSenderMemory(array $segment, array $ctx): ?array
    {
        $lines = (array) ($segment['dest_lines'] ?? []);
        if ($lines === [] || $segment['phones'] === []) {
            return null;
        }
        $pickup = app(SenderPickupMemory::class)->pickupFor($segment['phones']);
        if ($pickup === null) {
            return null;
        }
        $notes = (array) ($segment['notes'] ?? []);
        $phoneLine = "\n☎️ ".implode(', ', array_map(fn (string $p) => Phone::format($p), $segment['phones']));
        $out = [];
        $n = count($lines);
        foreach ($lines as $i => $line) {
            $place = AiParserService::placesIn($line, 1)[0] ?? null;
            if ($place === null) {
                continue;
            }
            $out[] = [
                'text' => 'Kalkış: '.$pickup['label']." (gönderen hafızası)\n".$line.($notes !== [] ? "\n".implode("\n", $notes) : '').$phoneLine,
                'phones' => $segment['phones'], 'index' => $i, 'count' => $n,
                'series' => ['count' => $n, 'pickup' => $pickup['label'], 'delivery' => $place['label']],
                'interpreted' => 'sender_memory', 'layer' => 'sender_pickup_memory',
            ];
        }

        return $out !== [] ? $out : null;
    }

    /** Seri/hafıza parçası için kural çözümü: rota parçadan kesin bilinir. */
    private function seriesParsed(array $segment, ?string $fallbackPhone): array
    {
        $parsed = $this->parser->parseCheap($segment['text'], $fallbackPhone);
        if (($parsed['sender_phone'] ?? null) === null && $segment['phones'] !== []) {
            $parsed['sender_phone'] = $segment['phones'][0];
        }
        $parsed['pickup_location'] = $segment['series']['pickup'];
        $parsed['delivery_location'] = $segment['series']['delivery'];
        $parsed['success'] = true;
        unset($parsed['reason']);

        return $parsed;
    }

    /**
     * Kalkışı yazmayan liste ve gönderen hafızada yok: mesaj elenmek yerine tek "kalkış bekleyen" aday olarak kuyruğa girer
     * (status parsed_partial, needs_pickup). Yönetici "Kalkış öğret" deyince gönderen hafızasına yazılır, mesaj yeniden okunur ve
     * her satır ayrı ilan olur; aynı gönderenin sonraki listeleri kendiliğinden çözülür. 48 saat içinde öğretilmezse olağan yaş retine düşer.
     */
    private function holdForPickup(array $segment, array $ctx): ?array
    {
        $lines = (array) ($segment['dest_lines'] ?? []);
        $phones = array_values(array_filter($segment['phones'], fn ($p) => AiParserService::isAdPhone($p)));
        if ($lines === [] || $phones === []) {
            return null;
        }
        $text = $segment['text'];
        $contentHash = hash('sha256', $ctx['source_id'].'|'.$ctx['message_id'].'|'.$text);
        if ($existing = ScrapedLoad::query()->where('content_hash', $contentHash)->first()) {
            $this->noteSighting($existing, $ctx['group']);

            return $this->result(200, true, 'duplicate', 'Kalkış bekleyen aday zaten kuyrukta.', $existing->id) + ['excerpt' => $text];
        }
        $firstPlace = AiParserService::placesIn($lines[0], 1)[0] ?? null;
        /** @var Scraper $scraper */
        $scraper = $ctx['scraper'];
        $load = $this->createLoad([
            'scraper_id' => $scraper->id, 'content_hash' => $contentHash, 'normalized_hash' => hash('sha256', self::normalizeText($text)),
            'route_key' => null, 'duplicate_count' => 1, 'seen_sources' => [$ctx['group']], 'last_seen_at' => now(), 'sighting_count' => 1,
            'raw_message' => $text, 'sender_phone' => null, 'encrypted_sender_phone' => Crypt::encryptString($phones[0]),
            'pickup_location' => null, 'delivery_location' => $firstPlace['label'] ?? null,
            'status' => 'parsed_partial', 'parsed_by_llm' => 'regex', 'ai_status' => 'skipped', 'ai_checked_at' => now(), 'visibility' => 'private',
            'parse_metadata' => array_filter([
                'layer' => 'sender_pickup_memory', 'needs_pickup' => true, 'dest_lines' => array_slice($lines, 0, 60), 'message_id' => $ctx['message_id'],
                'extra_phones_enc' => count($phones) > 1 ? array_map(fn (string $p) => Crypt::encryptString($p), array_slice($phones, 1)) : null,
            ]),
            'retention_expires_at' => now()->addDays(30),
        ]);
        $this->messageIds[] = $load->id;

        return $this->result(201, true, 'created', 'Kalkış yeri yazmıyor; gönderenin kalkışı öğretilene kadar kuyrukta bekliyor ('.count($lines).' varış satırı).', $load->id) + ['excerpt' => $text, 'reason' => 'pickup_pending'];
    }

    /** Satırda "+" ile bağlı yer adları var mı ("Gönen+Merkez", "Çorum + Ankara")? */
    public static function isPlusChain(string $line): bool
    {
        return preg_match('/\p{L}\s*\+\s*\p{L}/u', $line) === 1;
    }

    /**
     * Blok "kalkış satırı + alt alta yer listesi" biçiminde mi? İlk yer satırından sonra en az iki satır yalnız
     * yer adı (+ araç/ton sözcükleri) taşıyor ve hepsi farklı ilse ilk satır başlıktır; dizini döner.
     * "Ankara ⏎ İstanbul ⏎ 24 ton" (iki yer = rota) başlık sayılmaz.
     */
    private static function listHeaderLine(array $lines): ?int
    {
        $placeOnly = [];
        foreach ($lines as $i => $line) {
            if (self::hasPhone($line)) {
                continue;
            }
            // "İstanbul-Kartal" → "İstanbul Kartal": tire yer adını böler
            $line = preg_replace('/(?<=\p{L})\s*[-–—\/]\s*(?=\p{L})/u', ' ', $line) ?? $line;
            $places = AiParserService::placesIn($line, 2);
            if ($places === []) {
                continue;
            }
            // Klasik başlık ("Çorlu yüklemeli işlerimiz", "Samsundan") varsa liste düzeni o başlığa göre kurulur.
            $lower = TurkishCities::lower(trim($line));
            if (preg_match(AiParserService::PICKUP_VERBS, $lower) === 1 || (preg_match('/(?:dan|den|tan|ten)\s*[\p{P}\p{S}]*\s*$/u', $lower) === 1 && AiParserService::hasTrueAblative($lower))) {
                return null;
            }
            // Yer adı dışında kalan sözcükler araç/ton/kasa/adet sözcükleri ya da kısa bağlaçlar olmalı
            $rest = TurkishCities::ascii($line);
            foreach ($places as $pl) {
                foreach (explode(' ', TurkishCities::ascii($pl['label'])) as $w) {
                    $rest = preg_replace('/(?<![\p{L}])'.preg_quote($w, '/').'\w*/iu', ' ', $rest) ?? $rest;
                }
            }
            $rest = trim(preg_replace('/(?<![\p{L}])(?:tir|tır|kamyon|kamyonet|kirkayak|dorse|tenteli|tente|kapali|acik|sal|frigo|damper\w*|13[.,\/\- ]?60|1360|ton|tn|palet|arac|araç|yer|adet|acil|yuk\w*|yükler|yuklemeli|iner|inecek|ve|ile|merkez|osb|sanayi|liman\w*|[\d.,\-\/()+:–—]+)(?![\p{L}])/iu', ' ', $rest) ?? $rest);
            if (preg_replace('/[^\p{L}]/u', '', $rest) === '') {
                $placeOnly[$i] = (string) strtok($places[0]['label'], ' ');
            }
        }
        if (count($placeOnly) < 3) {
            return null;
        }
        // Liste kesintisiz olmalı: araya numara/başka satır giren "📍 Ankara ⏎ 📦 İstanbul ⏎ ☎️ … ⏎ 📍 Bursa ⏎ 📦 Konya" iki ilandır
        $keys = array_keys($placeOnly);
        if (count($keys) - 1 !== $keys[array_key_last($keys)] - $keys[0]) {
            return null;
        }
        $first = array_key_first($placeOnly);
        $rest = array_slice($placeOnly, 1, null, true);
        if (count(array_unique($rest)) < 2 || in_array($placeOnly[$first], $rest, true)) {
            return null;
        }

        return $first;
    }

    /**
     * İki rota farklı ilan mı? İller farklıysa evet. Aynı il çiftinde: iki satır da ilçe yazmış ve ilçeler farklıysa evet
     * ("Bandırma → Hendek" / "Bandırma → Adapazarı"); biri ilçesiz, öteki ilçeliyse aynı ilanın ayrıntısıdır
     * ("Ankara → İstanbul" / "Ankara Ostim yükleme İstanbul Kartal teslim").
     */
    private static function pairDiffers(array $a, array $b): bool
    {
        foreach ([0, 1] as $i) {
            $pa = (string) strtok($a[$i], ' ');
            $pb = (string) strtok($b[$i], ' ');
            if ($pa !== $pb) {
                return true;
            }
            $da = trim(mb_substr($a[$i], mb_strlen($pa)));
            $db = trim(mb_substr($b[$i], mb_strlen($pb)));
            if ($da !== '' && $db !== '' && $da !== $db) {
                return true;
            }
        }

        return false;
    }

    private static function unit(string $text): array
    {
        $text = trim($text);

        return ['text' => $text, 'phones' => AiParserService::phonesIn($text), 'route' => AiParserService::routePair($text) !== null];
    }

    private static function join(array $a, array $b): array
    {
        return [
            'text' => trim($a['text']."\n".$b['text']),
            'phones' => array_values(array_unique(array_merge($a['phones'], $b['phones']))),
            'route' => $a['route'] || $b['route'],
        ];
    }

    /**
     * Kural kesin mi: telefon var, rota bağlaçla yazılmış ("X - Y", "Xden Yye"; yön kesin), kalkış ve varış katalogda birebir
     * çözülüyor (sözlük/yakın eşleme değil) ve araç açık adıyla yazılmış (sınıflandırıcı "keyword" + "high"). Böyle bir parça
     * yapay zeka öncelikli kipte bile yapay zekaya gitmez. Etiketli/satır rollü yazımlar ("Varış: … / Çıkış: …") yapay zekaya gider.
     */
    public static function ruleStrong(array $parsed, string $text): bool
    {
        if (($parsed['success'] ?? false) !== true || empty($parsed['sender_phone']) || AiParserService::connectorPair($text) === null) {
            return false;
        }
        foreach (['pickup_location', 'delivery_location'] as $key) {
            $value = $parsed[$key] ?? null;
            if (! is_string($value) || $value === '' || TurkishLocations::resolveCatalog($value) === null) {
                return false;
            }
        }
        $vehicle = VehicleClassifier::analyze($text);

        return $vehicle['type'] !== null && $vehicle['source'] === 'keyword' && $vehicle['confidence'] === 'high';
    }

    /** Kural rotanın en az bir ucunu bir ile çözdü mü (eksik kayıt açmaya değer)? */
    private function resolvesOneEnd(array $parsed): bool
    {
        foreach (['pickup_location', 'delivery_location'] as $key) {
            $value = $parsed[$key] ?? null;
            if (is_string($value) && $value !== '' && TurkishLocations::resolve($value) !== null) {
                return true;
            }
        }

        return false;
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
    public static function filterReason(string $text, ?string $fallbackPhone = null): string
    {
        return self::hasPhone($text) || $fallbackPhone !== null ? 'no_logistics_signal' : 'phone_missing';
    }

    /** Aynı ilan yeni bir kaynaktan görüldüyse sayacı ve kaynak listesini günceller; aynı kaynaktan tekrar sayılmaz. */
    /**
     * Aynı ilan yeniden görüldü (başka grup ya da aynı grupta tekrar paylaşım): kaynak listesine eklenir ve ilan tazelenir.
     * Her gün yeniden paylaşılan ilan "canlı"dır: last_seen_at ilerler, yayındaysa saklama süresi uzar ve listede öne çıkar;
     * eskiden ilk görüldüğü tarihte kalıp 7 gün sonra düşüyordu (Engin Abi: "İstanbul çıkışlı ilan yok, hep 7 günlük").
     */
    private function noteSighting(?ScrapedLoad $load, string $groupName): void
    {
        if (! $load) {
            return;
        }
        $sources = array_values(array_filter((array) ($load->seen_sources ?? [])));
        $newSource = $groupName !== '' && ! in_array($groupName, $sources, true);
        if ($newSource) {
            $sources[] = $groupName;
        }
        $changes = ['seen_sources' => $sources, 'duplicate_count' => max(1, count($sources))];
        // Reddedilmiş kayıt tazelenmez (yeniden paylaşım yeni aday açsın); bir saat içindeki tekrar teslim (bildirim yinelemesi) sayılmaz.
        $recentlySeen = $load->last_seen_at !== null && $load->last_seen_at->gt(now()->subHour());
        if ($load->status !== 'rejected' && ! $recentlySeen) {
            $changes += ['last_seen_at' => now(), 'sighting_count' => (int) $load->sighting_count + 1];
            if ($load->visibility === 'public') {
                $changes['retention_expires_at'] = now()->addDays(max(1, Settings::int('scraper_list_days')));
            }
        } elseif (! $newSource) {
            return;
        }
        $load->forceFill($changes)->saveQuietly();
    }

    /** Emoji, noktalama, bağlantı ve büyük/küçük harf farklarını yok sayan karşılaştırma metni. */
    /**
     * Facebook ekranında aynı gönderi iki biçimde görülür: kesik ("… diğer" ile biten) ve açılmış tam metin. Aynı kaynağın son
     * 7 gündeki kayıtlarıyla ön ek karşılaştırması yapılır (en az 40 karakter ortak):
     * - Yeni metin, kuyruktaki (yayınlanmamış, reddedilmemiş, yönetici düzenlememiş) bir kaydın metnini baştan içeriyorsa o kayıt
     *   arşivlenir ve işlem yeni (tam) metinle sürer (null döner).
     * - Yeni metin, var olan bir kaydın metninin başlangıcıysa (kesik hâl sonradan geldi) tekrar sayılır.
     */
    private function mergeTruncatedFacebookPost(Scraper $scraper, string $raw, string $groupName): ?array
    {
        $new = self::normalizeText($raw);
        if (mb_strlen($new) < 40) {
            return null;
        }
        $recent = ScrapedLoad::query()->where('scraper_id', $scraper->id)->where('created_at', '>=', now()->subDays(7))
            ->latest('id')->limit(300)->get(['id', 'raw_message', 'status', 'visibility', 'parse_metadata', 'seen_sources', 'duplicate_count', 'last_seen_at', 'sighting_count', 'retention_expires_at']);
        foreach ($recent as $load) {
            $old = self::normalizeText((string) $load->raw_message);
            if (mb_strlen($old) < 40 || $old === $new) {
                continue;
            }
            if (str_starts_with($old, $new)) {
                $this->noteSighting($load, $groupName);

                return $this->result(200, true, 'duplicate', 'Aynı gönderinin kesik hâli; tam metin daha önce alındı.', $load->id);
            }
            if (str_starts_with($new, $old)) {
                $meta = (array) ($load->parse_metadata ?? []);
                $editedByAdmin = ! empty($meta['admin_edited_at']) || ! empty($meta['edited_by']) || ! empty($meta['manual']);
                if ($load->visibility !== 'public' && $load->status !== 'rejected' && ! $editedByAdmin) {
                    Log::info('Facebook kesik gönderi tam metinle değiştirildi', ['scraped_load_id' => $load->id, 'scraper_id' => $scraper->id]);
                    Cache::forget('intake:seen:'.hash('sha256', $old));
                    ScrapedLoad::query()->whereKey($load->id)->update(['content_hash' => null]); // tekil anahtar boşalır
                    ScrapedLoad::query()->whereKey($load->id)->delete(); // arşive gider; tam metin yeni kayıt olarak işlenir
                }

                return null;
            }
        }

        return null;
    }

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

    /**
     * İlan olmayan ama telefonlu ve rota içerebilen mesajlar: boş araç ilanı (şoför yük arıyor), şoför/eleman ilanı,
     * fatura/fiş reklamı, satılık/kiralık araç. Yük gruplarındaki 9.000 mesajdan derlendi.
     */
    public const NOT_LOAD_PATTERN = '/(?<!\p{L})(?:e-?fatura|e-?arşiv|e-?arsiv|gider fişi|gider fisi|utts|sgk yapılır|sgk yapilir|kdv açığ|kdv acig|beyanname|iş ilanı|is ilani|eleman aran|arkadaşlar aran|arkadaslar aran|şoför aran|sofor aran|şoför arıyor|sofor ariyor|şoför lazım|sofor lazim|şoförüm|soforum|iş arıyorum|is ariyorum|iş bakıyorum|boştayım|bostayim|boşum\b|bosum\b'
        // Yük sahibi dili elenmez: "boş araç arıyorum / var mı / lazım / girecek" aracı arayan yük sahibidir.
        .'|boş\s+(?:araç|arac|tır|tir|kamyon|kamyonet|dorse|\d+\s*teker)(?!\s*(?:girecek|gerek|lazım|lazim|ihtiyaç|ihtiyac|aranıyor|araniyor|arıyoruz|ariyoruz|arıyorum|ariyorum|arıyor|ariyor|arayan|olan|varsa|var\s*mı|varmı|var\s*mi|varmi|arayabilir|arasın|arasin|bulun|ara))'
        .'|boşta\b(?!\s*(?:olan|varsa|var\s*mı|varmı|arkadaş|arkadas|araç|arac|tır|tir|kamyon|kamyonet|dorse|çekici|cekici))|bosta\b(?!\s*(?:olan|varsa|var\s*mi|varmi|arac|tir|kamyon|kamyonet|dorse))'
        // Nakliyeci dili elenir: "kamyonum boş", "tırım boş(ta)", "aracım yük bekliyor", "müsait araç" (yük sahibinin "müsait araç arıyorum"u hariç)
        .'|(?:kamyonum|tırım|tirim|aracım|aracim|arabam|dorsem|çekicim|cekicim|kamyonetim|panelvanım|panelvanim|kırkayağım|kirkayagim)\s+(?:boş|bos|boşta|bosta|müsait|musait|yük\s+bekl\p{L}*|yuk\s+bekl\p{L}*|yük\s+arıyor|yuk\s+ariyor|hazır|hazir)'
        .'|(?:müsait|musait)\s+(?:araç|arac|tır|tir|kamyon|kamyonet|dorse)(?!\s*(?:arıyor|ariyor|arayan|aranıyor|araniyor|lazım|lazim|var\s*mı|varmı|var\s*mi|varmi|olan|varsa|gerek|ihtiyaç|ihtiyac))|(?:araç|arac|tır|tir|kamyon|kamyonet)\s+(?:müsait|musait)\b'
        .'|yük arıyor\p{L}*|yuk ariyor\p{L}*|yük bakıyor\p{L}*|yuk bakiyor\p{L}*|yük lazım|yuk lazim|yük varsa|yuk varsa|yük olan|yuk olan|dönüş yükü arıyor\p{L}*'
        // "boş tırım var", "boş kamyonum var": nakliyeci (iyelik ekli araç) — "boş tır var mı" yük sahibi kalır (yukarıdaki kuralla)
        .'|boş\s+(?:tırım|tirim|kamyonum|kamyonetim|aracım|aracim|arabam|dorsem|çekicim|cekicim|kırkayağım|kirkayagim)'
        .'|satılık|satilik|kiralık|kiralik|dolandırıcı|dolandirici|epd kayıt|epd kayit|sanal market)(?!\p{L})/iu';

    /** Muhasebe/fatura reklamı sözcükleri: ilan rotası (iki il) varsa yalnız nottur ("e-fatura kesilir"), elenmez. */
    private const ACCOUNTING_TERMS = ['e-fatura', 'efatura', 'e fatura', 'e-arşiv', 'e-arsiv', 'gider fişi', 'gider fisi', 'utts', 'sgk yapılır', 'sgk yapilir', 'kdv açığ', 'kdv acig', 'beyanname', 'epd kayıt', 'epd kayit', 'sanal market'];

    public static function isNotLoadPattern(string $text): bool
    {
        // PCRE büyük İ/I harflerini küçük i/ı ile eşleştirmez; Türkçe küçük harfe çevrilip bakılır ("BOŞ ARAÇ GİRECEK")
        $lower = mb_strtolower(str_replace(['İ', 'I'], ['i', 'ı'], $text));
        if (! preg_match_all(self::NOT_LOAD_PATTERN, $lower, $hits, PREG_OFFSET_CAPTURE)) {
            return false;
        }
        $hasRoute = null; // tembel: yalnız muhasebe sözcüğü görülünce bakılır
        foreach ($hits[0] as [$term, $offset]) {
            // Olumsuzlama: "satılık değil", "kiralık değil, navlun" → ilan (derin inceleme 2026-10-05)
            if (preg_match('/^\s*(?:değil|degil)(?!\p{L})/u', substr($lower, $offset + strlen($term))) === 1) {
                continue;
            }
            // Muhasebe reklamı sözcüğü ("e-fatura kesilir", "sgk") rota taşıyan ilanda yalnız nottur
            $isAccounting = false;
            foreach (self::ACCOUNTING_TERMS as $acc) {
                if (str_contains($term, $acc)) {
                    $isAccounting = true;
                    break;
                }
            }
            if ($isAccounting) {
                $hasRoute ??= count(AiParserService::placesIn($text, 2)) === 2;
                if ($hasRoute) {
                    continue;
                }
            }

            return true;
        }

        return false;
    }

    public static function looksLikeLoad(string $text, ?string $fallbackPhone = null): bool
    {
        if (! self::hasPhone($text) && $fallbackPhone === null) {
            return false;
        }
        if (Lexicon::isNotLoad($text) || TextPrep::isForeignScript($text) || self::isNotLoadPattern($text)) {
            return false;
        }
        if (Lexicon::hasLoadSignal($text)) {
            return true;
        }

        $hasMoney = (bool) preg_match('/\d[\d.,]*\s*(?:tl|₺|lira|bin)(?!\p{L})/iu', $text);
        $hasWeight = (bool) preg_match('/\d[\d.,]*\s*(?:kg|ton|tn|t|palet|koli|adet|m3)(?!\p{L})/iu', $text);
        // "Ankara-İzmir", "Ankaradan İzmire", "Diyarbakr dan Ankaraya", "Ankara → İzmir"
        $hasRoute = (bool) preg_match('/\p{L}{3,}\s*(?:->|→|>|-|–|—)\s*\p{L}{3,}|\p{L}{3,}\s*(?:dan|den|tan|ten)\s+\p{L}{3,}/iu', $text);
        $hasKeyword = (bool) preg_match('/(?<!\p{L})(?:yük|yuk|yükü|yükler|yukler|yüklemeli|yuklemeli|yüklenir|yuklenir|yüklemem|nakliye|nakliyat|tır|tir|kamyon|kamyonet|dorse|tenteli|tente|tenten|parsiyel|komple|araç|arac|sevkiyat|yükleme|yukleme|teslim|iner|indirmeli|boşaltır|bosaltir|palet|frigo|firgo|firigo|termokin|thermoking|lowbed|kırkayak|kirkayak|panelvan|çekici|cekici|navlun|gidecek|taşınacak|tasinacak|lazım|lazim|aranıyor|araniyor|arayan|boşta|bosta|yükleyecek|dökme|dokme|damper|damperli|kapalı|kapali|açık|acik|basar|tonaj|kdv|peşin|pesin|teker|dingil|koli|nokta|kapak)(?!\p{L})/iu', $text)
            || (bool) preg_match('/(?<!\d)(?:13[.,\-\/ ]?60|1360|8[.,]60|860)(?!\d)/u', $text);
        $hasRoute = $hasRoute || count(AiParserService::placesIn($text, 2)) === 2; // "Ankara Denizli ⏎ az parça": iki yer adı rota sayılır
        $norm = VehicleClassifier::normalize($text);
        $hasGoods = GoodsCatalog::detect($norm) !== null;
        $hasVehicle = VehicleClassifier::analyze($text)['type'] !== null;

        return $hasRoute || $hasMoney || $hasWeight || $hasKeyword || $hasGoods || $hasVehicle;
    }

    /**
     * Aynı yükü başka numarayla paylaşan kayıtlar (komisyoncu / ikinci aracı). Kesin eşleşme istenir (Osman, 2026-10-05):
     * son 48 saatte, aynı il çifti, farklı numara ve (a) metin numaralar çıkarılınca neredeyse aynı (sözcük benzerliği ≥ 0,7) ya da
     * (b) ilçeler çelişmiyor + yük kategorisi aynı + tonaj / fiyat / kesin araç tipinden biri aynı. Reddedilmiş ve aynı mesajın
     * öbür parçaları sayılmaz. Yan etkisizdir; kayıt açılınca iki tarafa da kimlik yazılır.
     *
     * @param  list<string>  $phones
     * @return list<ScrapedLoad>
     */
    private function similarWithOtherPhone(array $std, string $text, array $phones): array
    {
        if ($std['pickup_province_code'] === null || $std['delivery_province_code'] === null || $phones === []) {
            return [];
        }
        $candidates = ScrapedLoad::query()
            ->where('pickup_province_code', $std['pickup_province_code'])->where('delivery_province_code', $std['delivery_province_code'])
            ->where('status', '!=', 'rejected')->where('last_seen_at', '>=', now()->subHours(self::SIMILAR_HOURS))
            ->when($this->messageIds !== [], fn ($q) => $q->whereNotIn('id', $this->messageIds))
            ->latest('id')->limit(40)->get();
        $tokens = self::similarityTokens($text);
        $out = [];
        foreach ($candidates as $c) {
            if (array_intersect($c->allPhones(), $phones) !== []) {
                continue; // aynı gönderen: tekrar/tazeleme mantığı (routeKey) bakar
            }
            if (self::looksLikeSameLoad($std, $c, $tokens)) {
                $out[] = $c;
            }
        }

        return $out;
    }

    private static function looksLikeSameLoad(array $std, ScrapedLoad $c, array $tokens): bool
    {
        $same = fn ($a, $b): bool => $a !== null && $a !== '' && $b !== null && $b !== '' && (string) $a === (string) $b;
        $conflict = fn ($a, $b): bool => $a !== null && $a !== '' && $b !== null && $b !== '' && (string) $a !== (string) $b;
        if ($conflict($std['pickup_district'], $c->pickup_district) || $conflict($std['delivery_district'], $c->delivery_district)) {
            return false;
        }
        if ($conflict($std['weight'], $c->weight) || $conflict($std['metadata']['goods_category'] ?? null, $c->meta('goods_category'))) {
            return false;
        }
        $other = self::similarityTokens((string) $c->raw_message);
        if (count($tokens) >= 5 && count($other) >= 5) {
            $union = count(array_unique(array_merge($tokens, $other)));
            if ($union > 0 && count(array_intersect($tokens, $other)) / $union >= 0.7) {
                return true;
            }
        }
        if (! $same($std['metadata']['goods_category'] ?? null, $c->meta('goods_category'))) {
            return false;
        }
        $exactVehicle = in_array($std['vehicle_type_source'], ['keyword', 'template'], true) && in_array($c->vehicle_type_source, LoadFilterService::EXACT_VEHICLE_SOURCES, true);

        return $same($std['weight'], $c->weight) || $same($std['price'], $c->price) || ($exactVehicle && $same($std['vehicle_type'], $c->vehicle_type));
    }

    /** Benzerlik için sözcükler: numaralar ve tek harfler atılır, Türkçe küçük harf. @return list<string> */
    private static function similarityTokens(string $text): array
    {
        $clean = preg_replace(AiParserService::PHONE_PATTERN, ' ', TextPrep::prepare($text)) ?? $text;

        return array_values(array_unique(array_filter(explode(' ', self::normalizeText($clean)), fn ($w) => mb_strlen($w) >= 2)));
    }

    /** Aynı numara + aynı il çifti son saatlerde kaydedildiyse o ilanı döndürür. */
    private function recentSameRoute(array $parsed, ?string $phone = null, bool $districtLevel = false): ?ScrapedLoad
    {
        $phone ??= $parsed['sender_phone'] ?? null;
        $routeKey = is_string($phone) && $phone !== '' ? self::routeKey($phone, $parsed['pickup_location'] ?? null, $parsed['delivery_location'] ?? null, $districtLevel) : null;
        if (! $routeKey) {
            return null;
        }

        // Son görülme sayılır (her gün aynı rotayı paylaşan gönderenin ilanı tek kayıtta tazelenir) ama en çok 7 gün: sonra
        // yeni kayıt açılır ki fiyat/araç değişen ilan donup kalmasın. Yöneticinin reddettiği kayıt yalnız 48 saatlik açılış
        // penceresinde tutar; kendiliğinden reddedilen (puan/yaş) ve tekrar diye kapatılan kayıtlar tutmaz (onlar ilan değil demek
        // değildir; yeniden paylaşım yeni aday açsın). Aynı mesajın öbür ilanı (aynı il çifti, farklı ilçe) tekrar sayılmaz.
        return ScrapedLoad::query()->where('route_key', $routeKey)
            ->when($this->messageIds !== [], fn ($q) => $q->whereNotIn('id', $this->messageIds))
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('status', '!=', 'rejected')->where('last_seen_at', '>=', now()->subHours(self::ROUTE_DEDUPE_HOURS))->where('created_at', '>=', now()->subDays(self::TEXT_DEDUPE_DAYS)))
                ->orWhere(fn ($w) => $w->where('created_at', '>=', now()->subHours(self::ROUTE_DEDUPE_HOURS))
                    ->where(fn ($r) => $r->where('status', '!=', 'rejected')
                        ->orWhere(fn ($h) => $h->whereNull('parse_metadata->auto_rejected')->whereNull('parse_metadata->duplicate_of')->whereNull('parse_metadata->superseded_by')))))
            ->orderByRaw("CASE WHEN visibility = 'public' THEN 0 ELSE 1 END")->orderByRaw("CASE WHEN status = 'rejected' THEN 1 ELSE 0 END")->latest('id')->first();
    }

    /** Aynı metin: yayındaki ya da bekleyen kayıt 7 gün boyunca (son görülmeye göre) tazelenir; reddedilmiş kayıt yalnız açılıştan 7 gün tutar. */
    private function recentByText(string $normalizedHash): ?ScrapedLoad
    {
        // Reddedilmiş kayıt yalnız yöneticinin "ilan değil" dediyse tutar: kendiliğinden ret (il çözülemedi, yaş), tekrar, eski, yanlış rota
        // gerekçeli kayıtlar ilanın ilan olmadığı anlamına gelmez; sözlük düzeltilince yeniden paylaşım yeni aday açsın.
        // 30 günü geçmiş kayıt tazelenmez: yeni kayıt açılır ve yayında eskisinin yerine geçer (resolveTwin).
        return ScrapedLoad::query()->where('normalized_hash', $normalizedHash)
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('status', '!=', 'rejected')->where('last_seen_at', '>=', now()->subDays(self::TEXT_DEDUPE_DAYS))
                    ->where('created_at', '>=', now()->subDays(ScrapedLoadService::TWIN_MAX_AGE_DAYS)))
                ->orWhere(fn ($w) => $w->where('status', 'rejected')->where('created_at', '>=', now()->subDays(self::TEXT_DEDUPE_DAYS))
                    ->whereNull('parse_metadata->auto_rejected')->whereNull('parse_metadata->duplicate_of')->whereNull('parse_metadata->superseded_by')
                    ->where(fn ($r) => $r->whereNull('parse_metadata->reject_reason')->orWhere('parse_metadata->reject_reason', 'not_load'))))
            ->orderByRaw("CASE WHEN visibility = 'public' THEN 0 ELSE 1 END")->orderByRaw("CASE WHEN status = 'rejected' THEN 1 ELSE 0 END")->latest('id')->first();
    }

    public static function routeKey(?string $phone, ?string $pickup, ?string $delivery, bool $districtLevel = false): ?string
    {
        if (! $phone || ! $pickup || ! $delivery) {
            return null;
        }
        // İlçe/semt yazımı kaynağa göre değiştiği için il düzeyinde karşılaştırılır:
        // aynı numara, aynı il çifti, 48 saat içinde → aynı ilan. İl bulunamazsa ilk sözcük kullanılır.
        // Seri ilanda (aynı ilin ilçelerine ayrı araçlar) varış ilçe düzeyinde tutulur.
        // İl, katalogdan çözülür ("Tuzla" → İstanbul, "İstanbul Tuzla" → İstanbul): ham ve standart etiket aynı anahtarı verir.
        $city = fn (string $v) => TurkishCities::ascii(TurkishLocations::resolve($v)['province'] ?? TurkishCities::fromText($v) ?? (string) strtok(self::normalizeText($v), ' '));
        $dest = $districtLevel ? TurkishCities::ascii(self::normalizeText($delivery)) : $city($delivery);

        return mb_substr($phone.'|'.$city($pickup).'|'.$dest, 0, 191);
    }

    /**
     * Kaydı açar. Arşivlenmiş (soft delete) bir kayıt aynı content_hash'i taşıyorsa (eski arşivler, tekil anahtar) onun
     * anahtarı boşaltılıp yeniden denenir; yeniden paylaşılan ilan "failed" ile düşmez.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createLoad(array $attributes): ScrapedLoad
    {
        try {
            return ScrapedLoad::create($attributes);
        } catch (UniqueConstraintViolationException $e) {
            $trashed = ScrapedLoad::withTrashed()->where('content_hash', $attributes['content_hash'])->whereNotNull('deleted_at')->first();
            if ($trashed === null) {
                throw $e;
            }
            ScrapedLoad::withTrashed()->whereKey($trashed->id)->update(['content_hash' => null]);

            return ScrapedLoad::create($attributes);
        }
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
