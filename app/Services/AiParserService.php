<?php

namespace App\Services;

use App\Support\TurkishCities;
use App\Support\VehicleTypes;
use App\Models\AiProviderUsage;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class AiParserService
{
    public function parseMessage(string $message, ?string $preferredProvider = null): array
    {
        $message = trim(mb_substr($message, 0, 5000));
        if ($message === '') {
            return $this->failure('empty_message');
        }

        $regex = $this->parseWithRegex($message);
        if (($regex['success'] ?? false) === true) {
            return $regex;
        }

        return $this->parseWithAi($message, $preferredProvider);
    }

    /** Yapay zekaya gitmeden, yalnız kalıp eşlemeyle ayrıştırır (kota harcamaz). */
    public function parseCheap(string $message): array
    {
        $message = trim(mb_substr($message, 0, 5000));

        return $message === '' ? $this->failure('empty_message') : $this->parseWithRegex($message);
    }

    /** Sırayla sağlayıcıları dener; her çağrı kota harcar. */
    public function parseWithAi(string $message, ?string $preferredProvider = null): array
    {
        $message = trim(mb_substr($message, 0, 5000));
        if ($message === '') {
            return $this->failure('empty_message');
        }

        foreach ($this->providerOrder($preferredProvider) as $provider) {
            try {
                $result = $this->parseWithProvider($provider, $message);
                $this->recordUsage($provider, true, false);
                if (($result['success'] ?? false) === true) {
                    return $result;
                }
            } catch (Throwable $exception) {
                $quota = str_contains(strtolower($exception->getMessage()), 'quota') || str_contains($exception->getMessage(), '429');
                $this->recordUsage($provider, false, $quota);
                Log::warning('AI ayrıştırma sağlayıcısı başarısız.', ['provider' => $provider, 'error' => $exception::class, 'quota' => $quota]);
            }
        }

        return $this->failure('insufficient_verified_fields');
    }

    private function providerOrder(?string $preferred): array
    {
        $active = (string) config('services.ai.active_provider', 'gemini');
        $providers = array_values(array_unique(array_filter([$preferred, $active, 'gemini', 'claude', 'kimi'])));
        $paidEnabled = (bool) config('services.ai.paid_enabled', false) && ! (bool) config('services.ai.free_only', true);

        return array_values(array_filter($providers, function (string $provider) use ($paidEnabled): bool {
            if (! $paidEnabled && in_array($provider, ['claude', 'kimi'], true)) {
                return false;
            }

            return match ($provider) {
                'gemini' => filled(config('services.ai.gemini_key')),
                'claude' => filled(config('services.ai.claude_key')),
                'kimi' => filled(config('services.ai.kimi_key')),
                default => false,
            };
        }));
    }

    private function parseWithProvider(string $provider, string $message): array
    {
        $prompt = "Aşağıdaki lojistik ilanından yalnız açıkça yazılmış verileri çıkar. Tahmin etme. JSON dışında metin üretme. Alanlar: sender_phone, pickup_location, delivery_location, goods_type, weight (kilogram cinsinden tam sayı; ton yazıyorsa 1000 ile çarp), price (Türk lirası, sayı), vehicle_type (yalnız şu anahtarlardan biri: tir, kirkayak, 10_teker_kamyon, 8_teker_kamyon, 6_teker_kamyon, kamyonet, uzun_panelvan, orta_panelvan, minivan, otomobil; yazmıyorsa null). Eksik alan null olsun. Mesaj:\n".$message;

        $response = match ($provider) {
            'gemini' => Http::timeout(20)->acceptJson()->withHeaders(['x-goog-api-key' => (string) config('services.ai.gemini_key')])->post(
                'https://generativelanguage.googleapis.com/v1beta/models/'.config('services.ai.gemini_model').':generateContent',
                ['contents' => [['parts' => [['text' => $prompt]]]], 'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0]]
            ),
            'claude' => Http::timeout(20)->withHeaders([
                'x-api-key' => (string) config('services.ai.claude_key'),
                'anthropic-version' => '2023-06-01',
            ])->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.ai.claude_model'), 'max_tokens' => 500, 'temperature' => 0,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]),
            'kimi' => Http::timeout(20)->withToken((string) config('services.ai.kimi_key'))->post('https://api.moonshot.cn/v1/chat/completions', [
                'model' => config('services.ai.kimi_model'), 'temperature' => 0,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]),
            default => throw new RuntimeException('unsupported_provider'),
        };

        if ($response->status() === 429) {
            throw new RuntimeException('quota_429');
        }
        if (! $response->successful()) {
            throw new RuntimeException('provider_http_'.$response->status());
        }

        $text = $this->responseText($provider, $response);
        $decoded = json_decode($this->cleanJsonString($text), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('invalid_provider_json');
        }

        return $this->normalize($decoded, $provider);
    }

    private function responseText(string $provider, Response $response): string
    {
        return match ($provider) {
            'gemini' => (string) data_get($response->json(), 'candidates.0.content.parts.0.text', ''),
            'claude' => (string) data_get($response->json(), 'content.0.text', ''),
            'kimi' => (string) data_get($response->json(), 'choices.0.message.content', ''),
            default => '',
        };
    }

    private function normalize(array $data, string $provider): array
    {
        $phone = $this->normalizePhone($data['sender_phone'] ?? null);
        $pickup = TurkishCities::normalizeLocation($this->cleanText($data['pickup_location'] ?? null, 120));
        $delivery = TurkishCities::normalizeLocation($this->cleanText($data['delivery_location'] ?? null, 120));
        if (! $phone || ! $pickup || ! $delivery) {
            return $this->failure('required_fields_missing');
        }

        return [
            'success' => true,
            'sender_phone' => $phone,
            'pickup_location' => $pickup,
            'delivery_location' => $delivery,
            'goods_type' => $this->cleanText($data['goods_type'] ?? null, 120),
            'weight' => $weight = $this->positiveInteger($data['weight'] ?? null),
            'price' => $this->positiveDecimal($data['price'] ?? null),
            'vehicle_type' => VehicleTypes::isValid($data['vehicle_type'] ?? null) ? $data['vehicle_type'] : null,
            'vehicle_type_source' => VehicleTypes::isValid($data['vehicle_type'] ?? null) ? 'ai' : null,
            'parsed_by_llm' => $provider,
        ];
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

    private function recordUsage(string $provider, bool $success, bool $quota): void
    {
        try {
            $usage = AiProviderUsage::firstOrCreate(['provider' => $provider, 'usage_date' => now()->toDateString()]);
            $usage->increment('request_count');
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
