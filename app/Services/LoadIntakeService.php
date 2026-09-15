<?php

namespace App\Services;

use App\Models\ScrapedLoad;
use App\Models\Scraper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use App\Support\TurkishCities;
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

    public function __construct(private readonly AiParserService $parser)
    {
    }

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
        if ($existing = ScrapedLoad::query()->where('content_hash', $contentHash)->first()) {
            return $this->result(200, true, 'duplicate', 'Mesaj daha önce işlendi.', $existing->id);
        }

        // 2) Aynı metin farklı gruplardan (aynı anda bile gelse) yalnız bir kez işlenir.
        $normalizedHash = hash('sha256', self::normalizeText($raw));
        $seenKey = 'intake:seen:'.$normalizedHash;
        if (! Cache::add($seenKey, 1, now()->addHours(24))) {
            return $this->result(200, true, 'duplicate', 'Aynı ilan başka bir kaynaktan işleniyor veya işlendi.');
        }

        try {
            $recent = ScrapedLoad::query()->where('normalized_hash', $normalizedHash)
                ->where('created_at', '>=', now()->subDays(self::TEXT_DEDUPE_DAYS))->first();
            if ($recent) {
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

            // 4) İlan gibi görünmeyen sohbet mesajları yapay zekaya gitmez.
            if (! self::looksLikeLoad($raw)) {
                return $this->result(200, false, 'filtered', 'İlan ölçütleri karşılanmadı.');
            }

            // 5) Ücretsiz kalıp ayrıştırma; başarılıysa yapay zekaya hiç gidilmez.
            $parsed = $this->parser->parseCheap($raw);
            if (($parsed['success'] ?? false) !== true) {
                $parsed = $this->parser->parseWithAi($raw, (string) config('services.ai.active_provider', 'gemini'));
            }
        } catch (Throwable $e) {
            Cache::forget($seenKey); // yeniden denenebilsin
            Log::error('Dış kaynak ilanı ayrıştırılamadı.', ['exception' => $e::class]);

            return $this->result(503, false, 'failed', 'Mesaj işlenemedi.');
        }

        $phone = $parsed['sender_phone'] ?? $payload['sender_phone'] ?? null;
        $hasPhone = is_string($phone) && $phone !== '' && $phone !== 'Bilinmiyor';
        if (($parsed['success'] ?? false) !== true || ! $hasPhone) {
            return $this->result(200, false, 'filtered', 'İlan ölçütleri karşılanmadı.');
        }

        // 6) Aynı numara aynı rotayı kısa aralıkla farklı sözcüklerle paylaşmışsa tek ilan kalır.
        $routeKey = self::routeKey($phone, $parsed['pickup_location'] ?? null, $parsed['delivery_location'] ?? null);
        if ($routeKey) {
            $sameRoute = ScrapedLoad::query()->where('route_key', $routeKey)
                ->where('created_at', '>=', now()->subHours(self::ROUTE_DEDUPE_HOURS))->first();
            if ($sameRoute) {
                return $this->result(200, true, 'duplicate', 'Aynı numara ve rota yakın zamanda kaydedildi.', $sameRoute->id);
            }
        }

        $scraper->update(['last_scraped_at' => now(), 'last_success_at' => now(), 'last_error' => null]);
        $scrapedLoad = ScrapedLoad::create([
            'scraper_id' => $scraper->id,
            'content_hash' => $contentHash,
            'normalized_hash' => $normalizedHash,
            'route_key' => $routeKey,
            'raw_message' => $raw,
            'sender_phone' => null,
            'encrypted_sender_phone' => Crypt::encryptString($phone),
            'pickup_location' => $parsed['pickup_location'] ?? null,
            'delivery_location' => $parsed['delivery_location'] ?? null,
            'goods_type' => $parsed['goods_type'] ?? null,
            'weight' => $parsed['weight'] ?? null,
            'price' => $parsed['price'] ?? null,
            'currency' => 'TRY',
            'status' => (! empty($parsed['pickup_location']) && ! empty($parsed['delivery_location'])) ? 'parsed_success' : 'parsed_partial',
            'parsed_by_llm' => $parsed['parsed_by_llm'] ?? 'unknown',
            'visibility' => 'private',
            'retention_expires_at' => now()->addDays(30),
        ]);

        return $this->result(201, true, 'created', 'İlan adayı kaydedildi.', $scrapedLoad->id);
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
    public static function looksLikeLoad(string $text): bool
    {
        $hasPhone = (bool) preg_match('/(?<!\d)(?:\+?90|0)?[\s\-.()]*5(?:[\s\-.()]*\d){9}(?!\d)/u', $text);
        if (! $hasPhone) {
            return false;
        }

        $hasMoney = (bool) preg_match('/\d[\d.,]*\s*(?:tl|₺|lira)(?!\p{L})/iu', $text);
        $hasWeight = (bool) preg_match('/\d[\d.,]*\s*(?:kg|ton|tn)(?!\p{L})/iu', $text);
        $hasRoute = (bool) preg_match('/\p{L}{3,}\s*(?:->|→|>|-|–|—)\s*\p{L}{3,}|\p{L}{3,}(?:dan|den|tan|ten)\s+\p{L}{3,}(?:a|e|ya|ye)(?!\p{L})/iu', $text);
        $hasKeyword = (bool) preg_match('/(?<!\p{L})(?:yük|yuk|nakliye|nakliyat|tır|tir|kamyon|kamyonet|dorse|tenteli|tente|parsiyel|komple|araç|arac|sevkiyat|yükleme|yukleme|teslim|palet|frigo|lowbed|kırkayak|panelvan|çekici|cekici|navlun)(?!\p{L})/iu', $text);

        return $hasRoute || $hasMoney || $hasWeight || $hasKeyword;
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

    private function result(int $code, bool $success, string $status, string $message, ?int $id = null): array
    {
        $out = ['code' => $code, 'success' => $success, 'status' => $status, 'message' => $message];
        if ($id !== null) {
            $out['scraped_load_id'] = $id;
        }

        return $out;
    }
}
