<?php

namespace App\Services;

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
        $paidEnabled = (bool) config('services.ai.paid_enabled', false);

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
        $prompt = "Aşağıdaki lojistik ilanından yalnız açıkça yazılmış verileri çıkar. Tahmin etme. JSON dışında metin üretme. Alanlar: sender_phone, pickup_location, delivery_location, goods_type, weight, price. Eksik alan null olsun. Mesaj:\n".$message;

        $response = match ($provider) {
            'gemini' => Http::timeout(20)->acceptJson()->post(
                'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key='.rawurlencode((string) config('services.ai.gemini_key')),
                ['contents' => [['parts' => [['text' => $prompt]]]], 'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0]]
            ),
            'claude' => Http::timeout(20)->withHeaders([
                'x-api-key' => (string) config('services.ai.claude_key'),
                'anthropic-version' => '2023-06-01',
            ])->post('https://api.anthropic.com/v1/messages', [
                'model' => 'claude-3-5-haiku-latest', 'max_tokens' => 500, 'temperature' => 0,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]),
            'kimi' => Http::timeout(20)->withToken((string) config('services.ai.kimi_key'))->post('https://api.moonshot.cn/v1/chat/completions', [
                'model' => 'moonshot-v1-8k', 'temperature' => 0,
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
        $pickup = $this->cleanText($data['pickup_location'] ?? null, 120);
        $delivery = $this->cleanText($data['delivery_location'] ?? null, 120);
        if (! $phone || ! $pickup || ! $delivery) {
            return $this->failure('required_fields_missing');
        }

        return [
            'success' => true,
            'sender_phone' => $phone,
            'pickup_location' => $pickup,
            'delivery_location' => $delivery,
            'goods_type' => $this->cleanText($data['goods_type'] ?? null, 120),
            'weight' => $this->positiveInteger($data['weight'] ?? null),
            'price' => $this->positiveDecimal($data['price'] ?? null),
            'parsed_by_llm' => $provider,
        ];
    }

    private function parseWithRegex(string $message): array
    {
        preg_match('/(?<!\d)(?:(?:\+?90|0)?5\d{9})(?!\d)/u', $message, $phoneMatch);
        $phone = $this->normalizePhone($phoneMatch[0] ?? null);

        $city = '[\p{L}][\p{L}\s]{1,38}?';
        preg_match('/('.$city.')\s*(?:->|→|>|-|–|—|den|dan)\s*('.$city.')(?:\s|$|[,.;])/iu', $message.' ', $route);
        $pickup = $this->cleanText($route[1] ?? null, 120);
        $delivery = $this->cleanText($route[2] ?? null, 120);
        if (! $phone || ! $pickup || ! $delivery) {
            return $this->failure('regex_required_fields_missing');
        }

        preg_match('/(?<!\d)(\d{3,9}(?:[.,]\d{1,2})?)\s*(?:TL|₺)(?!\p{L})/iu', $message, $price);
        preg_match('/(?<!\d)(\d{1,6}(?:[.,]\d{1,2})?)\s*(kg|ton)(?!\p{L})/iu', $message, $weight);
        preg_match('/(?:yük|mal|ürün)\s*[:\-]\s*([\p{L}\d\s]{2,80})/iu', $message, $goods);

        return [
            'success' => true,
            'sender_phone' => $phone,
            'pickup_location' => $pickup,
            'delivery_location' => $delivery,
            'goods_type' => $this->cleanText($goods[1] ?? null, 120),
            'weight' => $this->positiveInteger($weight[1] ?? null),
            'price' => $this->positiveDecimal($price[1] ?? null),
            'parsed_by_llm' => 'regex_verified',
        ];
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
        $number = (int) round((float) str_replace(',', '.', preg_replace('/[^0-9,.]/', '', (string) $value) ?? ''));

        return $number > 0 ? $number : null;
    }

    private function positiveDecimal(mixed $value): ?float
    {
        if (! is_scalar($value)) {
            return null;
        }
        $number = (float) str_replace(',', '.', preg_replace('/[^0-9,.]/', '', (string) $value) ?? '');

        return $number > 0 ? round($number, 2) : null;
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
