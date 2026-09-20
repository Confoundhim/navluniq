<?php

namespace App\Services;

use App\Models\AiProviderUsage;
use App\Support\GoodsCatalog;
use App\Support\Settings;
use App\Support\TurkishCities;
use App\Support\TurkishLocations;
use App\Support\VehicleClassifier;
use App\Support\VehicleTypes;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class AiParserService
{
    public const MODES = ['off' => 'Kapalı (yalnız kural)', 'fill_gaps' => 'Kural eksik bırakınca', 'always' => 'Her ilanda'];

    public const CLAUDE_MODELS = ['claude-opus-5' => 'Claude Opus 5 (en isabetli)', 'claude-sonnet-5' => 'Claude Sonnet 5', 'claude-haiku-4-5' => 'Claude Haiku 4.5 (en ucuz)'];

    public const GEMINI_MODELS = ['gemini-2.5-flash' => 'Gemini 2.5 Flash', 'gemini-2.5-pro' => 'Gemini 2.5 Pro'];

    /** Yalnız kural tabanlı ayrıştırma; başarısızsa (ayar açıksa) yapay zeka ile tamamlar. */
    public function parseMessage(string $message, ?string $preferredProvider = null): array
    {
        $message = trim(mb_substr($message, 0, 5000));
        if ($message === '') {
            return $this->failure('empty_message');
        }
        $parsed = $this->parseWithRegex($message);
        if ($this->shouldUseAi($parsed)) {
            $ai = $this->enrich($message, $parsed);
            if ($ai['data'] !== null) {
                $parsed = $this->merge($parsed, $ai['data']);
            }
        }

        return $parsed;
    }

    /** Yapay zekaya gitmeden, yalnız kalıp eşlemeyle ayrıştırır (kota harcamaz). */
    public function parseCheap(string $message): array
    {
        $message = trim(mb_substr($message, 0, 5000));

        return $message === '' ? $this->failure('empty_message') : $this->parseWithRegex($message);
    }

    public function mode(): string
    {
        $mode = Settings::string('ai_parse_mode');

        return array_key_exists($mode, self::MODES) ? $mode : 'fill_gaps';
    }

    /** Sağlayıcı: panel ayarı; panelde seçilmemişse (eski kurulum) env ACTIVE_AI_PROVIDER; yoksa claude. */
    public function provider(): string
    {
        $p = Settings::get('ai_provider', '');
        if (! in_array($p, ['claude', 'gemini'], true)) {
            $p = (string) config('services.ai.active_provider', 'claude');
        }

        return in_array($p, ['claude', 'gemini'], true) ? $p : 'claude';
    }

    public function model(): string
    {
        return $this->provider() === 'gemini'
            ? (Settings::string('ai_gemini_model') ?: (string) config('services.ai.gemini_model', 'gemini-2.5-flash'))
            : (Settings::string('ai_claude_model') ?: (string) config('services.ai.claude_model', 'claude-opus-5'));
    }

    public function apiKey(): string
    {
        return $this->provider() === 'gemini'
            ? (Settings::string('ai_gemini_key') ?: trim((string) config('services.ai.gemini_key')))
            : (Settings::string('ai_claude_key') ?: trim((string) config('services.ai.claude_key')));
    }

    public function isEnabled(): bool
    {
        return $this->mode() !== 'off';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    /** Ayara göre bu ilan için yapay zeka çağrılmalı mı? */
    public function shouldUseAi(array $parsed): bool
    {
        if (! $this->isEnabled() || ! $this->isConfigured()) {
            return false;
        }
        if ($this->mode() === 'always') {
            return true;
        }
        if (($parsed['success'] ?? false) !== true) {
            return true;
        }
        $unresolved = fn ($v) => ! is_string($v) || $v === '' || TurkishLocations::resolve($v) === null;

        // Eksik alan doldurma: telefon, güzergâh veya araç tipi kural ile çözülemediyse. Tonaj/fiyat ilanların
        // çoğunda zaten yazmaz; yalnız bunlar için kota harcanmaz ("always" kipi her ilanı yapay zekaya gönderir).
        return $unresolved($parsed['pickup_location'] ?? null)
            || $unresolved($parsed['delivery_location'] ?? null)
            || empty($parsed['vehicle_type'])
            || empty($parsed['sender_phone']);
    }

    /**
     * Yapay zekadan yapılandırılmış çözümleme ister.
     *
     * @return array{status:string, data:?array} status: done | failed | pending (kota/ağ; sonra yeniden denenir) | skipped
     */
    public function enrich(string $message, array $parsed = [], bool $manual = false): array
    {
        $message = trim(mb_substr($message, 0, 5000));
        if ($message === '' || ! $this->isConfigured() || (! $manual && ! $this->isEnabled())) {
            return ['status' => 'skipped', 'data' => null];
        }
        $provider = $this->provider();

        try {
            $data = $provider === 'gemini' ? $this->callGemini($message) : $this->callClaude($message);
            $this->recordUsage($provider, true, false, $data['_usage'] ?? []);
            unset($data['_usage']);

            return ['status' => 'done', 'data' => $this->normalizeAi($data, $provider)];
        } catch (Throwable $exception) {
            $msg = $exception->getMessage();
            $retryable = str_contains($msg, '429') || str_contains(strtolower($msg), 'quota') || str_contains($msg, 'provider_http_5') || str_contains($msg, 'cURL') || str_contains($msg, 'timed out');
            $this->recordUsage($provider, false, str_contains($msg, '429') || str_contains(strtolower($msg), 'quota'));
            Log::warning('Yapay zeka çözümlemesi başarısız.', ['provider' => $provider, 'model' => $this->model(), 'error' => $msg, 'retry' => $retryable]);

            return ['status' => $retryable ? 'pending' : 'failed', 'data' => null];
        }
    }

    /**
     * Kural sonucu ile yapay zeka sonucunu birleştirir: yapay zeka boşları doldurur; kuralın çözemediği
     * konumları ve düşük güvenli araç tahminini düzeltir; kesin kural eşleşmeleri (anahtar sözcük) korunur.
     */
    public function merge(array $parsed, array $ai): array
    {
        $out = $parsed;
        $resolvable = fn ($v) => is_string($v) && $v !== '' && TurkishLocations::resolve($v) !== null;

        foreach (['pickup_location', 'delivery_location'] as $key) {
            if (! empty($ai[$key]) && ! $resolvable($out[$key] ?? null)) {
                $out[$key] = $ai[$key];
            }
        }
        if (empty($out['sender_phone']) && ! empty($ai['sender_phone'])) {
            $out['sender_phone'] = $ai['sender_phone'];
        }
        foreach (['weight', 'price', 'goods_type'] as $key) {
            if (empty($out[$key]) && ! empty($ai[$key])) {
                $out[$key] = $ai[$key];
            }
        }
        $ruleKeyword = ($out['vehicle_type_source'] ?? null) === 'keyword';
        if (! empty($ai['vehicle_type']) && (empty($out['vehicle_type']) || ! $ruleKeyword)) {
            $out['vehicle_type'] = $ai['vehicle_type'];
            $out['vehicle_type_source'] = 'ai';
        }
        $out['success'] = ! empty($out['sender_phone']) && ! empty($out['pickup_location']) && ! empty($out['delivery_location']);
        if ($out['success']) {
            unset($out['reason']);
        }
        $base = ($parsed['parsed_by_llm'] ?? null) === 'regex_verified' ? 'regex' : (($parsed['success'] ?? false) ? 'regex' : null);
        $out['parsed_by_llm'] = trim(($base ? $base.'+' : '').($ai['provider'] ?? 'ai'), '+');
        $out['ai'] = $ai;

        return $out;
    }

    /** Yapay zeka çıktısını doğrular ve iç biçime çevirir (araç/yük anahtarları katalogla sınırlıdır). */
    private function normalizeAi(array $data, string $provider): array
    {
        $vehicle = is_string($data['vehicle_type'] ?? null) && VehicleTypes::isValid($data['vehicle_type']) ? $data['vehicle_type'] : null;
        $goodsKey = is_string($data['goods_category'] ?? null) && GoodsCatalog::label($data['goods_category']) ? $data['goods_category'] : null;
        $confidence = is_numeric($data['confidence'] ?? null) ? max(0.0, min(1.0, (float) $data['confidence'])) : null;

        return [
            'provider' => $provider,
            'model' => $this->model(),
            'is_load' => ($data['post_type'] ?? 'load') === 'load' && ($data['is_load'] ?? true) !== false,
            'post_type' => in_array($data['post_type'] ?? null, ['load', 'vehicle_available', 'other'], true) ? $data['post_type'] : 'load',
            'confidence' => $confidence,
            'sender_phone' => $this->normalizePhone($data['sender_phone'] ?? null),
            'pickup_location' => $this->placeText($data['pickup'] ?? null),
            'delivery_location' => $this->placeText($data['delivery'] ?? null),
            'vehicle_type' => $vehicle,
            'vehicle_flexible' => (bool) ($data['vehicle_flexible'] ?? false),
            'weight' => $this->positiveInteger($data['weight_kg'] ?? null),
            'price' => $this->positiveDecimal($data['price_try'] ?? null),
            'goods_type' => $goodsKey ? GoodsCatalog::label($goodsKey) : $this->cleanText($data['goods'] ?? null, 120),
            'goods_category' => $goodsKey,
            'urgent' => (bool) ($data['urgent'] ?? false),
            'pickup_date_text' => $this->cleanText($data['pickup_date_text'] ?? null, 60),
            'multiple_loads' => (bool) ($data['multiple_loads'] ?? false),
            'notes' => $this->cleanText($data['notes'] ?? null, 200),
        ];
    }

    /** {province, district} nesnesini "İl İlçe" metnine çevirir. */
    private function placeText(mixed $place): ?string
    {
        if (is_string($place)) {
            return $this->cleanText($place, 120);
        }
        if (! is_array($place)) {
            return null;
        }
        $province = $this->cleanText($place['province'] ?? null, 60);
        $district = $this->cleanText($place['district'] ?? null, 60);
        if ($province === null) {
            return $this->cleanText($place['text'] ?? null, 120);
        }

        return trim($province.' '.($district ?? ''));
    }

    /** Sağlayıcıdan bağımsız istem ve JSON şeması. */
    public static function systemPrompt(): string
    {
        $vehicles = implode("\n", array_map(fn ($k, $v) => "- {$k}: {$v['label']} (~".number_format($v['capacity_kg'] / 1000, 0).' ton kapasite)', array_keys(VehicleTypes::TYPES), VehicleTypes::TYPES));
        $goods = implode(', ', array_map(fn ($k, $l) => "{$k} ({$l})", array_keys(GoodsCatalog::labels()), GoodsCatalog::labels()));

        return <<<TXT
Sen Türkiye kara nakliye sektöründe WhatsApp gruplarına yazılan yük ilanlarını çözümleyen bir asistansın.
Verilen mesaj çoğunlukla kısa, yazım hatalı, kısaltmalı ve Türkçe karakterleri eksik olabilir ("Diyarbakr", "istanbl", "tn" = ton, "bin" = ×1000, "komple" = aracın tamamı, "parsiyel" = kısmi yük, "acil" = acele).

Görevin: mesajı anlayıp yapılandırılmış alanlara ayırmak. Kurallar:
1. post_type: "load" = taşınacak bir yük ve araç aranıyor; "vehicle_available" = boş araç/şoför yük arıyor (yük ilanı DEĞİL); "other" = sohbet, araç satışı, iş ilanı, reklam.
2. pickup ve delivery: Türkiye il adı (resmi yazım, ör. "Diyarbakır", "İstanbul") ve varsa ilçe/semt. "X'den Y'ye", "X - Y", "X → Y", "Xdan Yya" kalıplarında X kalkış, Y varıştır. İlçe verildiyse ilini sen bul (Kartal → İstanbul, Gebze → Kocaeli, Nazilli → Aydın).
3. vehicle_type: yalnız şu anahtarlardan biri; mesajda araç adı yoksa tonaja/yüke göre EN KÜÇÜK uygun aracı seç ve vehicle_flexible=true yap:
{$vehicles}
"tenteli", "dorse", "çekici", "mega", "lowbed" → tir. "Kapalı kasa kamyon" → tonaja göre kamyon. "Panelvan" → orta_panelvan (uzun yazıyorsa uzun_panelvan).
4. weight_kg: kilogram tam sayı ("24 tn" → 24000, "12,5 ton" → 12500, "800 kg" → 800). "Basar tonaj" = aracın taşıyabildiği azami tonaj, yük tonajı sayılır. Palet adedi tonaj değildir.
5. price_try: Türk lirası ("45 bin" → 45000, "38.000 tl" → 38000, "45k" → 45000). KDV notu fiyatı değiştirmez. Yoksa null.
6. goods_category: yalnız şu anahtarlardan biri ya da null: {$goods}. goods: mesajdaki yük tanımı kısa metin.
7. sender_phone: mesajdaki Türkiye cep numarası, 10 hane "5xxxxxxxxx" biçiminde (0 ve +90 atılır).
8. urgent: acil/hemen/bugün gibi ifadeler varsa true. pickup_date_text: yükleme zamanı ifadesi ("yarın", "pazartesi", "12.05") aynen.
9. multiple_loads: mesajda birden fazla ayrı yük ilanı varsa true; bu durumda alanlara İLK ilanı yaz.
10. confidence: 0 ile 1 arasında; mesaj belirsizse düşük ver. Tahmin etmek zorunda kaldığın alanları notes içinde kısaca belirt.
Bilinmeyen alanları null bırak, uydurma.
TXT;
    }

    /** @return array<string, mixed> JSON şeması (Claude yapılandırılmış çıktı) */
    public static function outputSchema(): array
    {
        $nullable = fn (string $type) => ['anyOf' => [['type' => $type], ['type' => 'null']]];
        $place = ['anyOf' => [
            ['type' => 'object', 'properties' => ['province' => $nullable('string'), 'district' => $nullable('string')], 'required' => ['province', 'district'], 'additionalProperties' => false],
            ['type' => 'null'],
        ]];

        return [
            'type' => 'object',
            'properties' => [
                'post_type' => ['type' => 'string', 'enum' => ['load', 'vehicle_available', 'other']],
                'confidence' => ['type' => 'number'],
                'sender_phone' => $nullable('string'),
                'pickup' => $place,
                'delivery' => $place,
                'vehicle_type' => ['anyOf' => [['type' => 'string', 'enum' => array_keys(VehicleTypes::TYPES)], ['type' => 'null']]],
                'vehicle_flexible' => ['type' => 'boolean'],
                'weight_kg' => $nullable('integer'),
                'price_try' => $nullable('number'),
                'goods' => $nullable('string'),
                'goods_category' => ['anyOf' => [['type' => 'string', 'enum' => array_keys(GoodsCatalog::labels())], ['type' => 'null']]],
                'urgent' => ['type' => 'boolean'],
                'pickup_date_text' => $nullable('string'),
                'multiple_loads' => ['type' => 'boolean'],
                'notes' => $nullable('string'),
            ],
            'required' => ['post_type', 'confidence', 'sender_phone', 'pickup', 'delivery', 'vehicle_type', 'vehicle_flexible', 'weight_kg', 'price_try', 'goods', 'goods_category', 'urgent', 'pickup_date_text', 'multiple_loads', 'notes'],
            'additionalProperties' => false,
        ];
    }

    /** Claude Messages API (yapılandırılmış çıktı). Tek istek, kısa yanıt; sistem istemi önbelleklenir. */
    private function callClaude(string $message): array
    {
        $model = $this->model();
        $body = [
            'model' => $model,
            'max_tokens' => 1024,
            'system' => [['type' => 'text', 'text' => self::systemPrompt(), 'cache_control' => ['type' => 'ephemeral']]],
            'messages' => [['role' => 'user', 'content' => "İlan mesajı:\n".$message]],
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => self::outputSchema()]],
        ];
        $headers = ['x-api-key' => $this->apiKey(), 'anthropic-version' => '2023-06-01'];
        if (! str_contains($model, 'haiku')) {
            $body['output_config']['effort'] = 'low'; // basit çıkarım görevi: hız ve maliyet
        }
        if (str_starts_with($model, 'claude-opus-5') || str_starts_with($model, 'claude-fable')) {
            $body['fallbacks'] = 'default'; // güvenlik sınıflandırıcısı reddederse sunucu tarafında yedek model
            $headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
        }

        $response = Http::timeout(45)->withHeaders($headers)->post('https://api.anthropic.com/v1/messages', $body);
        if ($response->status() === 429) {
            throw new RuntimeException('quota_429');
        }
        if (! $response->successful()) {
            throw new RuntimeException('provider_http_'.$response->status().': '.mb_substr((string) data_get($response->json(), 'error.message', $response->body()), 0, 200));
        }
        $json = $response->json();
        if (($json['stop_reason'] ?? '') === 'refusal') {
            throw new RuntimeException('refusal');
        }
        $text = '';
        foreach ((array) ($json['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }
        $decoded = json_decode($this->cleanJsonString($text), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('invalid_provider_json');
        }
        $decoded['_usage'] = ['input' => (int) data_get($json, 'usage.input_tokens', 0), 'output' => (int) data_get($json, 'usage.output_tokens', 0)];

        return $decoded;
    }

    /** Gemini generateContent (JSON yanıt). */
    private function callGemini(string $message): array
    {
        $response = Http::timeout(45)->acceptJson()->withHeaders(['x-goog-api-key' => $this->apiKey()])->post(
            'https://generativelanguage.googleapis.com/v1beta/models/'.$this->model().':generateContent',
            [
                'systemInstruction' => ['parts' => [['text' => self::systemPrompt()."\nYanıtı yalnız şu JSON şemasına uygun ver: ".json_encode(self::outputSchema(), JSON_UNESCAPED_UNICODE)]]],
                'contents' => [['parts' => [['text' => "İlan mesajı:\n".$message]]]],
                'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0],
            ]
        );
        if ($response->status() === 429) {
            throw new RuntimeException('quota_429');
        }
        if (! $response->successful()) {
            throw new RuntimeException('provider_http_'.$response->status());
        }
        $text = (string) data_get($response->json(), 'candidates.0.content.parts.0.text', '');
        $decoded = json_decode($this->cleanJsonString($text), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('invalid_provider_json');
        }
        $decoded['_usage'] = ['input' => (int) data_get($response->json(), 'usageMetadata.promptTokenCount', 0), 'output' => (int) data_get($response->json(), 'usageMetadata.candidatesTokenCount', 0)];

        return $decoded;
    }

    private function parseWithRegex(string $message): array
    {
        // "0532 123 45 67", "0 (532) 123-45-67" gibi boşluklu/ayraçlı yazımlar da telefon sayılır.
        preg_match('/(?<!\d)(?:\+?90|0)?[\s\-.()]*5(?:[\s\-.()]*\d){9}(?!\d)/u', $message, $phoneMatch);
        $phone = $this->normalizePhone($phoneMatch[0] ?? null);

        // "Ankara'dan İzmir'e" yazımındaki kesme işaretleri rota eşlemesini bozmasın.
        $routeText = preg_replace("/[’'‘`]/u", '', $message) ?? $message;
        // Bağlaçtan önce ve sonra en fazla iki sözcük alınır ("Ankara Ostim" → "İzmir Aliağa").
        $word = '\p{L}+';
        preg_match('/('.$word.'(?:\s+'.$word.')?)\s*(->|→|>|-|–|—|dan|den|tan|ten)\s+('.$word.'(?:\s+'.$word.')?)/iu', $routeText, $route);
        $dativeForm = isset($route[2]) && preg_match('/^(?:dan|den|tan|ten)$/iu', $route[2]) === 1; // "Ankaradan İzmire": varış yönelme eki taşır
        $pickup = $this->tidyLocation($route[1] ?? null);
        $delivery = $this->tidyLocation($route[3] ?? null, $dativeForm);
        if (! $phone || ! $pickup || ! $delivery) {
            return $this->failure('regex_required_fields_missing');
        }

        // "45.000 TL", "45000₺", "1.250,50 TL" ve "12,5 ton" gibi Türkçe sayı yazımları tanınır.
        preg_match('/(?<!\d)(\d{1,3}(?:\.\d{3})+(?:,\d{1,2})?|\d{3,9}(?:[.,]\d{1,2})?)\s*(?:TL|₺|lira)(?!\p{L})/iu', $message, $price);
        preg_match('/(?<!\d)(\d{1,3}(?:\.\d{3})+|\d{1,6}(?:[.,]\d{1,3})?)\s*(kg|ton|tn)(?!\p{L})/iu', $message, $weight);
        $weightKg = $this->positiveDecimal($weight[1] ?? null);
        if ($weightKg !== null && isset($weight[2]) && in_array(strtolower($weight[2]), ['ton', 'tn'], true)) {
            $weightKg *= 1000;
        }
        // "24t", "20-25 ton", "yirmi dört ton" gibi yazımlar sınıflandırıcının tonaj çözümüyle yakalanır.
        $weightKg ??= VehicleClassifier::weightFromText(VehicleClassifier::normalize($message));
        preg_match('/(?:yük|mal|ürün)\s*[:\-]\s*([\p{L}\d\s]{2,80})/iu', $message, $goods);

        return [
            'success' => true,
            'sender_phone' => $phone,
            'pickup_location' => $pickup,
            'delivery_location' => $delivery,
            'goods_type' => $this->cleanText($goods[1] ?? null, 120),
            'weight' => $weightKg !== null ? (int) round($weightKg) : null,
            'price' => $this->positiveDecimal($price[1] ?? null),
            'vehicle_type' => ($vehicle = VehicleTypes::detect($message, $weightKg !== null ? (int) round($weightKg) : null))['type'],
            'vehicle_type_source' => $vehicle['source'],
            'parsed_by_llm' => 'regex_verified',
        ];
    }

    /** Yer adından "acil", "yük", "var" gibi dolgu sözcüklerini atar; il adını ekinden arındırır. */
    private function tidyLocation(?string $value, bool $stripDative = false): ?string
    {
        $value = $this->cleanText($value, 120);
        if ($value === null) {
            return null;
        }
        $stop = ['acil', 'yük', 'yuk', 'var', 'lazım', 'lazim', 'palet', 'ton', 'tır', 'tir', 'kamyon', 'kamyonet', 'tenteli', 'komple', 'parsiyel',
            'araç', 'arac', 'arayan', 'arayanlar', 'için', 'icin', 'ile', 've', 'mal', 'ürün', 'urun', 'çıkış', 'cikis', 'varış', 'varis',
            'yükleme', 'yukleme', 'boşaltma', 'bosaltma', 'hazır', 'hazir', 'gidecek', 'gelecek', 'olan', 'yarın', 'yarin', 'bugün', 'bugun',
            'sabah', 'akşam', 'aksam', 'yükü', 'yuku', 'nakliye', 'dorse', 'frigo', 'kasa'];
        $words = preg_split('/\s+/u', $value) ?: [];
        $isStop = fn (string $w) => in_array(TurkishCities::lower($w), $stop, true);
        while ($words !== [] && $isStop($words[0])) {
            array_shift($words);
        }
        while ($words !== [] && $isStop($words[array_key_last($words)])) {
            array_pop($words);
        }
        if ($words === []) {
            return null;
        }
        // "İzmire Aliağaya" → son sözcükteki yönelme eki (-a/-e/-ya/-ye) atılır; il adları ayrıca normalize edilir.
        $last = array_key_last($words);
        if ($stripDative && TurkishCities::fromText($words[$last]) === null) {
            $w = $words[$last];
            if (preg_match('/(ya|ye)$/iu', $w) && mb_strlen($w) > 5) {
                $words[$last] = mb_substr($w, 0, -2);
            } elseif (preg_match('/[^aeıioöuüAEIİOÖUÜ](a|e)$/iu', $w) && mb_strlen($w) > 4) {
                $words[$last] = mb_substr($w, 0, -1);
            }
        }

        return TurkishCities::normalizeLocation(implode(' ', $words));
    }

    private function normalizePhone(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
        if (str_starts_with($digits, '90') && strlen($digits) === 12) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $digits = substr($digits, 1);
        }

        return preg_match('/^5\d{9}$/', $digits) ? $digits : null;
    }

    private function cleanText(mixed $value, int $limit): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value)) ?? '');

        return $text === '' ? null : mb_substr($text, 0, $limit);
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (! is_scalar($value)) {
            return null;
        }
        $number = (int) round($this->toNumber((string) $value));

        return $number > 0 ? $number : null;
    }

    private function positiveDecimal(mixed $value): ?float
    {
        if (! is_scalar($value)) {
            return null;
        }
        $number = $this->toNumber((string) $value);

        return $number > 0 ? round($number, 2) : null;
    }

    /** Türkçe yazımı sayıya çevirir: "45.000" → 45000, "1.250,50" → 1250.5, "12,5" → 12.5, "45000.50" → 45000.5. */
    private function toNumber(string $value): float
    {
        $v = preg_replace('/[^0-9,.]/', '', $value) ?? '';
        if ($v === '') {
            return 0.0;
        }
        if (str_contains($v, ',') && str_contains($v, '.')) {
            $v = str_replace('.', '', $v);            // nokta binlik, virgül ondalık
            $v = str_replace(',', '.', $v);
        } elseif (str_contains($v, ',')) {
            $v = str_replace(',', '.', $v);           // yalnız virgül: ondalık
        } elseif (preg_match('/^\d{1,3}(?:\.\d{3})+$/', $v)) {
            $v = str_replace('.', '', $v);            // yalnız nokta ve 3'lü gruplar: binlik
        }

        return (float) $v;
    }

    private function recordUsage(string $provider, bool $success, bool $quota, array $tokens = []): void
    {
        try {
            $usage = AiProviderUsage::firstOrCreate(['provider' => $provider, 'usage_date' => now()->toDateString()]);
            $usage->increment('request_count');
            if (! empty($tokens['input']) || ! empty($tokens['output'])) {
                $usage->increment('input_units', (int) ($tokens['input'] ?? 0));
                $usage->increment('output_units', (int) ($tokens['output'] ?? 0));
            }
            if (! $success) {
                $usage->increment('failure_count');
            }
            if ($quota) {
                $usage->update(['quota_exhausted' => true, 'quota_resets_at' => now()->addDay()->startOfDay()]);
            }
        } catch (Throwable) {
            // Ayrıştırma sonucunu telemetri arızası nedeniyle bozma.
        }
    }

    private function failure(string $reason): array
    {
        return ['success' => false, 'reason' => $reason, 'sender_phone' => null, 'pickup_location' => null, 'delivery_location' => null, 'goods_type' => null, 'weight' => null, 'price' => null, 'parsed_by_llm' => null];
    }

    private function cleanJsonString(string $string): string
    {
        $string = trim($string);
        $string = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $string) ?? $string;

        return trim($string);
    }
}
