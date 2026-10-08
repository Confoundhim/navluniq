<?php

namespace App\Services;

use App\Models\AiProviderUsage;
use App\Support\BodyTypes;
use App\Support\ForeignPlaces;
use App\Support\GoodsCatalog;
use App\Support\Phone;
use App\Support\SeriesAd;
use App\Support\Settings;
use App\Support\TextPrep;
use App\Support\TurkishCities;
use App\Support\TurkishLocations;
use App\Support\VehicleClassifier;
use App\Support\VehicleTypes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class AiParserService
{
    public const MODES = ['off' => 'Kapalı (yalnız kural)', 'fill_gaps' => 'Kural eksik bırakınca', 'always' => 'Her ilanda (yapay zeka öncelikli)'];

    public const CLAUDE_MODELS = ['claude-opus-5' => 'Claude Opus 5 (en isabetli)', 'claude-sonnet-5' => 'Claude Sonnet 5', 'claude-haiku-4-5' => 'Claude Haiku 4.5 (en ucuz)'];

    public const GEMINI_MODELS = ['gemini-3.5-flash-lite' => 'Gemini 3.5 Flash-Lite (daha yüksek ücretsiz kota)', 'gemini-3.5-flash' => 'Gemini 3.5 Flash', 'gemini-2.5-flash-lite' => 'Gemini 2.5 Flash-Lite', 'gemini-2.5-flash' => 'Gemini 2.5 Flash'];

    /**
     * Sağlayıcılar. Varsayılan sıra ücretsiz katmanlardan başlar; anahtarı girilen her sağlayıcı sırayla denenir,
     * kota dolunca (429) sıradakine geçilir. Claude ücretli olduğundan en sondadır.
     */
    public const PROVIDERS = [
        'ollama' => ['label' => 'Yerel model (Ollama, sunucuda)', 'kind' => 'openai', 'local' => true, 'free' => 'Tamamen ücretsiz ve dışa bağımsız: model sunucunuzda çalışır, kota yoktur. Kurulum: docs/BILDIRIM_ILETICI_KURULUM.md → "Yerel model". Açıkken zincirin başındadır; yanıt vermezse diğer sağlayıcılara geçilir.',
            'models' => ['qwen3:4b' => 'Qwen3 4B (önerilen, ~4 GB RAM)', 'qwen2.5:7b-instruct' => 'Qwen2.5 7B (~6 GB RAM)', 'gemma3:4b' => 'Gemma 3 4B', 'llama3.2:3b' => 'Llama 3.2 3B (hafif)'], 'key_hint' => '', 'site' => 'https://ollama.com/download'],
        'gemini' => ['label' => 'Google Gemini', 'kind' => 'gemini', 'free' => 'Ücretsiz katman: kart istemez (aistudio.google.com → Get API key). Günlük istek sınırı modele göre değişir; Flash-Lite daha yüksek kota verir.',
            'models' => self::GEMINI_MODELS, 'key_hint' => 'AIza…', 'site' => 'https://aistudio.google.com/apikey'],
        'groq' => ['label' => 'Groq', 'kind' => 'openai', 'base' => 'https://api.groq.com/openai/v1', 'free' => 'Ücretsiz katman: kart istemez (console.groq.com). Llama 3.3 70B çok hızlı; günlük ~1.000 istek.',
            'models' => ['openai/gpt-oss-120b' => 'GPT-OSS 120B', 'meta-llama/llama-4-scout-17b-16e-instruct' => 'Llama 4 Scout 17B', 'llama-3.3-70b-versatile' => 'Llama 3.3 70B'], 'key_hint' => 'gsk_…', 'site' => 'https://console.groq.com/keys'],
        'cerebras' => ['label' => 'Cerebras', 'kind' => 'openai', 'hidden' => true, 'base' => 'https://api.cerebras.ai/v1', 'free' => 'Ücretsiz katman: kart istemez (cloud.cerebras.ai). Günlük ~1 milyon jeton.',
            'models' => ['llama-3.3-70b' => 'Llama 3.3 70B', 'gpt-oss-120b' => 'GPT-OSS 120B', 'qwen-3-32b' => 'Qwen 3 32B'], 'key_hint' => 'csk-…', 'site' => 'https://cloud.cerebras.ai'],
        'openrouter' => ['label' => 'OpenRouter', 'kind' => 'openai', 'hidden' => true, 'base' => 'https://openrouter.ai/api/v1', 'free' => '":free" modeller ücretsizdir (openrouter.ai). Kart olmadan günlük ~50 istek; bir kez 10 $ kredi alınırsa günlük 1.000.',
            'models' => ['meta-llama/llama-3.3-70b-instruct:free' => 'Llama 3.3 70B (free)', 'qwen/qwen3-235b-a22b:free' => 'Qwen3 235B (free)', 'deepseek/deepseek-chat-v3-0324:free' => 'DeepSeek V3 (free)', 'google/gemma-3-27b-it:free' => 'Gemma 3 27B (free)'], 'key_hint' => 'sk-or-…', 'site' => 'https://openrouter.ai/keys'],
        'mistral' => ['label' => 'Mistral', 'kind' => 'openai', 'hidden' => true, 'base' => 'https://api.mistral.ai/v1', 'free' => 'Ücretsiz deneme katmanı (console.mistral.ai; telefon doğrulaması ister). Aylık ~1 milyar jeton, saniyede 1 istek.',
            'models' => ['mistral-small-latest' => 'Mistral Small', 'open-mistral-nemo' => 'Mistral Nemo 12B', 'mistral-medium-latest' => 'Mistral Medium'], 'key_hint' => '…', 'site' => 'https://console.mistral.ai/api-keys'],
        'claude' => ['label' => 'Claude (Anthropic)', 'kind' => 'claude', 'hidden' => true, 'free' => 'Ücretli. Yalnız istenirse; ücretsizlerden sonra denenir.',
            'models' => self::CLAUDE_MODELS, 'key_hint' => 'sk-ant-…', 'site' => 'https://console.anthropic.com'],
        'openai' => ['label' => 'OpenAI (ChatGPT API)', 'kind' => 'openai', 'hidden' => true, 'base' => 'https://api.openai.com/v1', 'free' => 'Ücretli: ChatGPT uygulaması ücretsiz olsa da API anahtarı kullandıkça ödemelidir (platform.openai.com). "Mini" modeller çok ucuzdur.',
            'models' => ['gpt-5-mini' => 'GPT-5 mini', 'gpt-4.1-mini' => 'GPT-4.1 mini', 'gpt-4o-mini' => 'GPT-4o mini'], 'key_hint' => 'sk-…', 'site' => 'https://platform.openai.com/api-keys'],
        'xai' => ['label' => 'xAI Grok', 'kind' => 'openai', 'base' => 'https://api.x.ai/v1', 'hidden' => true, 'free' => 'Ücretli (console.x.ai); zaman zaman deneme kredisi verilir.',
            'models' => ['grok-4-fast' => 'Grok 4 Fast', 'grok-3-mini' => 'Grok 3 mini', 'grok-4' => 'Grok 4'], 'key_hint' => 'xai-…', 'site' => 'https://console.x.ai'],
        'kimi' => ['label' => 'Moonshot Kimi', 'kind' => 'openai', 'hidden' => true, 'base' => 'https://api.moonshot.ai/v1', 'free' => 'Ücretli ama çok ucuz (platform.moonshot.ai); yeni hesaba deneme kredisi verilir.',
            'models' => ['kimi-k2-turbo-preview' => 'Kimi K2 Turbo', 'kimi-k2-0905-preview' => 'Kimi K2', 'moonshot-v1-8k' => 'Moonshot v1 8k'], 'key_hint' => 'sk-…', 'site' => 'https://platform.moonshot.ai'],
    ];

    public const DEFAULT_ORDER = ['ollama', 'gemini', 'groq', 'cerebras', 'openrouter', 'mistral', 'kimi', 'openai', 'xai', 'claude'];

    /** Panelde gösterilen sağlayıcılar: yalnız gerçekten ücretsiz çalışanlar (Gemini, Groq). Gizliler anahtarı girilmişse zincirde yine çalışır. */
    public static function visibleProviders(): array
    {
        return array_filter(self::PROVIDERS, fn (array $p) => empty($p['hidden']) && (empty($p['local']) || config('services.ai.allow_local_models')));
    }

    /** Sağlayıcı bu hatada bir günlük kotasını mı bitirdi (model değiştirerek devam edilebilir)? */
    public static function isDailyQuota(string $msg): bool
    {
        $l = strtolower($msg);

        return str_contains($l, 'perday') || str_contains($l, 'per day') || str_contains($l, 'daily') || str_contains($l, 'free_tier_requests')
            || str_contains($l, 'tokens per day') || str_contains($l, 'requests per day') || str_contains($l, 'tpd') || str_contains($l, 'rpd')
            || preg_match('/try again in \d+h/i', $msg) === 1 || preg_match('/(\d+)\s*(?:saat|hour)/i', $msg) === 1;
    }

    /** Ödeme/bakiye hatası: ücretsiz katman yok ya da bitti; gün boyu denenmez. */
    public static function isBillingError(string $msg): bool
    {
        $l = strtolower($msg);

        return str_starts_with($msg, 'provider_http_402') || str_contains($l, 'payment required') || str_contains($l, 'payment_required')
            || str_contains($l, 'insufficient balance') || str_contains($l, 'insufficient_quota') || str_contains($l, 'no credits')
            || str_contains($l, 'add credits') || str_contains($l, 'recharge') || str_contains($l, 'billing details') && str_contains($l, 'suspended');
    }

    /** Gemini ücretsiz kotası Pasifik gece yarısında (07:00 UTC) sıfırlanır; diğerleri 24 saat sonra. */
    public static function secondsUntilDailyReset(string $provider): int
    {
        if ($provider === 'gemini') {
            $reset = now('UTC')->setTime(7, 0);
            if ($reset->lessThanOrEqualTo(now('UTC'))) {
                $reset->addDay();
            }

            return max(600, $reset->diffInSeconds(now('UTC')));
        }

        return 86400;
    }

    /**
     * İlan iletişim numarası. Cep: "0532 123 45 67", "+90 (532) 123-45-67", "5321234567" (ayraçlı/boşluklu yazımlar dahil;
     * "kat 5 0532 …": tek başına 5 numarayı başlatmaz). Sabit / kurumsal hat (0212…, 0312…, 0850…, 0800…) yalnız başında 0 ya da
     * +90 ile ve noktasız yazımda tanınır ("05.10.2026 14:30" tarihi sabit hat sanılmasın); 444'lü kısa numara "444 1 234".
     * Kabul edilip edilmeyeceğine `isAdPhone` (panel ayarı) karar verir; bu kalıp metinden numara rakamlarını da temizler.
     */
    public const PHONE_PATTERN = '/(?<![\d.])(?:\+?90|0)[\s\-()]*(?:[234]\d{2}|850|800)(?:[\s\-()]*\d){7}(?![\d.])'
        .'|(?<!\d)(?:\+?90|0)?[\s\-.()]*5(?![\s\-.()]+0\d)(?:[\s\-.()]*\d){9}(?!\d)'
        .'|(?<![\d.,])444[\s\-]?\d[\s\-]?\d{3}(?![\d.,])/u';

    /** IBAN ("TR53 2000 0001 2345 6789 0123 45") telefon değildir; numara aranmadan metinden çıkarılır. */
    public const IBAN_PATTERN = '/\bTR\d{2}(?:[\s\-]?\d{4}){5}[\s\-]?\d{2}\b/iu';

    /** Yalnız kural tabanlı ayrıştırma; başarısızsa (ayar açıksa) yapay zeka ile tamamlar. */
    public function parseMessage(string $message, ?string $preferredProvider = null): array
    {
        $message = trim(mb_substr($message, 0, 8000));
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

    /**
     * Yapay zekaya gitmeden, yalnız kalıp eşlemeyle ayrıştırır (kota harcamaz).
     *
     * @param  ?string  $fallbackPhone  bildirim başlığındaki gönderen numarası: gövdede numara yoksa ilanın numarası sayılır
     */
    public function parseCheap(string $message, ?string $fallbackPhone = null): array
    {
        $message = trim(mb_substr($message, 0, 8000));

        return $message === '' ? $this->failure('empty_message') : $this->parseWithRegex($message, $fallbackPhone);
    }

    public function mode(): string
    {
        $mode = Settings::string('ai_parse_mode');

        return array_key_exists($mode, self::MODES) ? $mode : 'fill_gaps';
    }

    /** Tercih edilen sağlayıcı (panel; eski kurulumda env ACTIVE_AI_PROVIDER). Boş: varsayılan sıra. */
    public function preferredProvider(): string
    {
        $p = Settings::get('ai_provider', '');
        if (! array_key_exists((string) $p, self::PROVIDERS)) {
            $p = (string) config('services.ai.active_provider', '');
        }

        return array_key_exists($p, self::PROVIDERS) ? $p : '';
    }

    /** Deneme sırası: tercih edilen (varsa) önce, ardından varsayılan sıra; yalnız anahtarı girilenler. */
    public function chain(): array
    {
        $order = self::DEFAULT_ORDER;
        if (($pref = $this->preferredProvider()) !== '') {
            $order = array_values(array_unique(array_merge([$pref], $order)));
        }
        // Sunucudaki yerel model açıksa her zaman en başta: kota yok, dışa bağımlılık yok.
        $local = array_keys(array_filter(self::PROVIDERS, fn (array $p) => ! empty($p['local'])));
        $order = array_values(array_unique(array_merge($local, $order)));

        return array_values(array_filter($order, fn (string $p) => $this->apiKey($p) !== ''));
    }

    /** Etiketler için ilk kullanılabilir sağlayıcı. */
    public function provider(): string
    {
        return $this->chain()[0] ?? ($this->preferredProvider() ?: 'gemini');
    }

    /**
     * Kullanılacak model. Ayar boşsa ("Otomatik") sağlayıcının güncel model listesinden tercih sırasına göre
     * seçilir ve 12 saat önbelleğe alınır; liste alınamazsa sabit yedek listenin ilki.
     */
    public function model(?string $provider = null): string
    {
        $provider ??= $this->provider();
        $set = Settings::string('ai_'.$provider.'_model') ?: (string) config('services.ai.'.$provider.'_model', '');

        return $set !== '' ? $set : $this->autoModel($provider);
    }

    /** Otomatik seçilen model (önbellekli). $exclude: az önce 404 dönen model. */
    public function autoModel(string $provider, ?string $exclude = null): string
    {
        $key = 'ai:auto_model:'.$provider;
        if ($exclude === null && ($cached = Cache::get($key))) {
            return $cached;
        }
        $models = $this->listModels($provider, refresh: $exclude !== null);
        $exhausted = $this->exhaustedModels($provider);
        $skip = array_values(array_unique(array_filter(array_merge($exhausted, [$exclude]))));
        $candidates = array_values(array_filter($models, fn ($m) => ! in_array($m, $skip, true)));
        $pick = self::pickModel($provider, $candidates) ?: (self::pickModel($provider, $models, $exclude) ?: (string) array_key_first(self::PROVIDERS[$provider]['models'] ?? []));
        Cache::put($key, $pick, now()->addHours(12));

        return $pick;
    }

    /** Günlük kotası dolan modeller (sağlayıcı bazında, sıfırlanana kadar). */
    public function exhaustedModels(string $provider): array
    {
        return array_values(array_filter((array) Cache::get('ai:exhausted_models:'.$provider, []), fn ($m) => is_string($m) && $m !== ''));
    }

    private function markModelExhausted(string $provider, string $model): void
    {
        $list = array_values(array_unique(array_merge($this->exhaustedModels($provider), [$model])));
        Cache::put('ai:exhausted_models:'.$provider, $list, now()->addSeconds(self::secondsUntilDailyReset($provider)));
        Cache::forget('ai:auto_model:'.$provider);
    }

    /** Sağlayıcının güncel model kimlikleri (6 saat önbellek; hata durumunda boş dizi). */
    public function listModels(string $provider, bool $refresh = false): array
    {
        $key = 'ai:models:'.$provider;
        if (! $refresh && is_array($cached = Cache::get($key))) {
            return $cached;
        }
        if (! array_key_exists($provider, self::PROVIDERS) || $this->apiKey($provider) === '') {
            return [];
        }
        try {
            $models = match (self::PROVIDERS[$provider]['kind']) {
                'gemini' => $this->fetchGeminiModels($provider),
                'claude' => $this->fetchClaudeModels($provider),
                default => $this->fetchOpenAiModels($provider),
            };
        } catch (Throwable $e) {
            Log::warning('Yapay zeka model listesi alınamadı.', ['provider' => $provider, 'error' => $e->getMessage()]);
            $models = [];
        }
        $models = array_values(array_unique(array_filter($models, 'is_string')));
        sort($models);
        Cache::put($key, $models, now()->addHours(6));

        return $models;
    }

    private function fetchGeminiModels(string $provider): array
    {
        $out = [];
        $token = null;
        do {
            $response = Http::timeout(20)->acceptJson()->withHeaders(['x-goog-api-key' => $this->apiKey($provider)])
                ->get('https://generativelanguage.googleapis.com/v1beta/models', array_filter(['pageSize' => 200, 'pageToken' => $token]));
            if (! $response->successful()) {
                throw new RuntimeException('provider_http_'.$response->status().': '.mb_substr((string) data_get($response->json(), 'error.message', ''), 0, 200));
            }
            foreach ((array) $response->json('models', []) as $m) {
                if (in_array('generateContent', (array) ($m['supportedGenerationMethods'] ?? []), true)) {
                    $out[] = preg_replace('~^models/~', '', (string) ($m['name'] ?? ''));
                }
            }
            $token = $response->json('nextPageToken');
        } while ($token);

        return $out;
    }

    private function fetchOpenAiModels(string $provider): array
    {
        $base = $this->baseUrl($provider);
        $response = Http::timeout(20)->acceptJson()->withHeaders(['Authorization' => 'Bearer '.$this->apiKey($provider)])->get($base.'/models');
        if (! $response->successful()) {
            throw new RuntimeException('provider_http_'.$response->status().': '.mb_substr((string) data_get($response->json(), 'error.message', ''), 0, 200));
        }

        return array_map(fn ($m) => (string) ($m['id'] ?? ''), (array) $response->json('data', []));
    }

    private function fetchClaudeModels(string $provider): array
    {
        $response = Http::timeout(20)->acceptJson()->withHeaders(['x-api-key' => $this->apiKey($provider), 'anthropic-version' => '2023-06-01'])->get('https://api.anthropic.com/v1/models', ['limit' => 100]);
        if (! $response->successful()) {
            throw new RuntimeException('provider_http_'.$response->status().': '.mb_substr((string) data_get($response->json(), 'error.message', ''), 0, 200));
        }

        return array_map(fn ($m) => (string) ($m['id'] ?? ''), (array) $response->json('data', []));
    }

    /**
     * Listeden ilan çözümlemeye en uygun modeli seçer: sağlayıcıya göre tercih kalıpları, en yeni sürüm önce;
     * ses/görüntü/embedding/guard gibi uygun olmayanlar elenir.
     */
    public static function pickModel(string $provider, array $models, ?string $exclude = null): ?string
    {
        $bad = '/embed|embedding|tts|audio|image|imagen|veo|vision|live|realtime|whisper|guard|moderation|safety|compound|ocr|transcri|speech|rerank|codestral|devstral|voxtral|pixtral|thinking|codex|search|dall-e|sora|babbage|davinci/i';
        $models = array_values(array_filter($models, fn ($m) => $m !== $exclude && ! preg_match($bad, $m)));
        if ($models === []) {
            return null;
        }
        $prefs = match ($provider) {
            'gemini' => ['/^gemini-[\d.]+-flash-lite$/', '/^gemini-[\d.]+-flash$/', '/flash-lite/', '/flash/', '/^gemini-[\d.]+-pro$/', '/gemini/'],
            'openrouter' => ['/llama-3\.3-70b.*:free$/', '/llama-4.*:free$/', '/llama.*70b.*:free$/', '/qwen3.*:free$/', '/deepseek.*chat.*:free$/', '/gemma-3.*:free$/', '/mistral.*:free$/', '/:free$/'],
            'mistral' => ['/^mistral-small-latest$/', '/^mistral-small/', '/^open-mistral-nemo/', '/^ministral-8b/', '/^mistral-medium-latest$/', '/^mistral-large-latest$/', '/mistral/'],
            'claude' => ['/^claude-sonnet-5/', '/^claude-opus-5/', '/^claude-haiku/', '/^claude-sonnet/', '/claude/'],
            'openai' => ['/^gpt-5(\.\d+)?-mini$/', '/^gpt-4\.1-mini$/', '/^gpt-4o-mini$/', '/^gpt-5-nano$/', '/^gpt-5(\.\d+)?$/', '/^gpt-4\.1$/', '/^gpt-4o$/', '/^gpt-/'],
            'xai' => ['/grok-\d+-fast/', '/grok-\d+-mini/', '/grok-4/', '/grok-3/', '/grok/'],
            'kimi' => ['/kimi-k2.*turbo/', '/kimi-k2/', '/moonshot-v1-8k/', '/moonshot-v1/', '/kimi/'],
            'ollama' => ['/^qwen3(?::|$)/', '/^qwen3:/', '/^qwen2\.5.*instruct/', '/^qwen2\.5/', '/^gemma3/', '/^llama3\.[23]/', '/qwen/', '/gemma/', '/llama/', '/mistral/', '/./'],
            default => ['/llama-3\.3-70b/', '/llama-4.*(scout|maverick)/', '/llama.*70b/', '/gpt-oss-120b/', '/gpt-oss/', '/qwen.*(235b|32b)/', '/qwen/', '/llama-3\.1-8b/', '/llama/', '/mixtral/', '/gemma/'],
        };
        $version = fn (string $m) => preg_match('/(\d+(?:\.\d+)?)/', preg_replace('/^[a-z]+\/?/', '', $m) ?? $m, $v) ? (float) $v[1] : 0.0;
        foreach ($prefs as $pattern) {
            $hits = array_values(array_filter($models, fn ($m) => preg_match($pattern, $m)));
            if ($hits === []) {
                continue;
            }
            // Kararlı sürüm önce (preview/exp sona), sonra en yüksek sürüm numarası, sonra en kısa ad.
            usort($hits, function (string $a, string $b) use ($version): int {
                $pa = (int) preg_match('/preview|exp|beta|latest-preview/i', $a);
                $pb = (int) preg_match('/preview|exp|beta|latest-preview/i', $b);

                return [$pa, -$version($a), strlen($a)] <=> [$pb, -$version($b), strlen($b)];
            });

            return $hits[0];
        }

        return $models[0];
    }

    /** Seçenek listesi: güncel liste (varsa) + sabit yedekler; ayar ekranı için. */
    public function modelOptions(string $provider): array
    {
        $static = self::PROVIDERS[$provider]['models'] ?? [];
        $live = is_array($c = Cache::get('ai:models:'.$provider)) ? $c : [];
        $out = [];
        foreach ($live as $id) {
            if (! preg_match('/embed|embedding|tts|audio|imagen|veo|whisper|guard|moderation|rerank|transcri|speech/i', $id)) {
                $out[$id] = $static[$id] ?? $id;
            }
        }
        foreach ($static as $id => $label) {
            $out[$id] ??= $label.($live !== [] ? ' (listede yok)' : '');
        }

        return $out;
    }

    public function apiKey(?string $provider = null): string
    {
        $provider ??= $this->provider();
        if (! empty(self::PROVIDERS[$provider]['local'])) {
            // Yerel model: anahtar yerine açık/kapalı; sunucu bayrağı (AI_ALLOW_LOCAL_MODELS) yoksa panel ayarı ne olursa olsun kapalı.
            return config('services.ai.allow_local_models') && Settings::bool('ai_'.$provider.'_enabled') ? 'local' : '';
        }

        return Settings::string('ai_'.$provider.'_key') ?: trim((string) config('services.ai.'.$provider.'_key', ''));
    }

    /** OpenAI uyumlu sağlayıcının adresi; yerel model için panelden ayarlanan adres. */
    public function baseUrl(string $provider): string
    {
        if (! empty(self::PROVIDERS[$provider]['local'])) {
            return rtrim(Settings::string('ai_'.$provider.'_base') ?: 'http://127.0.0.1:11434/v1', '/');
        }

        return rtrim((string) (self::PROVIDERS[$provider]['base'] ?? ''), '/');
    }

    /** Yerel model işlemcide yavaş çalışır; ona daha uzun süre tanınır. Uzak sağlayıcı 15 sn'de cevap vermediyse vermez (eski 45 sn her ölü sağlayıcıda bekliyordu). */
    private function timeout(string $provider): int
    {
        return ! empty(self::PROVIDERS[$provider]['local']) ? 180 : 15;
    }

    /** Bağlantı kurma süresi (sn): uzak sağlayıcı 5 sn'de bağlanmazsa sıradakine geçilir. */
    private function connectTimeout(string $provider): int
    {
        return ! empty(self::PROVIDERS[$provider]['local']) ? 10 : 5;
    }

    public function isEnabled(): bool
    {
        return $this->mode() !== 'off';
    }

    /** Yapay zeka öncelikli kip: "Her ilanda" seçili ve en az bir anahtar var. Telefonu olan her mesaj yapay zekaya gider. */
    public function aiFirst(): bool
    {
        return $this->mode() === 'always' && $this->isConfigured();
    }

    /** En az bir sağlayıcının anahtarı var mı? */
    public function isConfigured(): bool
    {
        return $this->chain() !== [];
    }

    /** Bugün kotası dolmuş (429 almış) sağlayıcılar; sıfırlanma saatine kadar atlanır. */
    public function exhaustedToday(): array
    {
        try {
            return AiProviderUsage::query()->whereDate('usage_date', now()->toDateString())->where('quota_exhausted', true)
                ->where(fn ($q) => $q->whereNull('quota_resets_at')->orWhere('quota_resets_at', '>', now()))
                ->pluck('provider')->all();
        } catch (Throwable) {
            return [];
        }
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

        // Eksik alan doldurma: telefon ya da güzergâh kural ile çözülemediyse. Araç tipi, tonaj ve fiyat ilanların
        // çoğunda yazmaz; metinde yoksa yapay zeka da uyduramaz, bunlar için kota harcanmaz ("always" kipi her ilanı gönderir).
        $resolvableAbroad = fn ($v) => is_string($v) && $v !== '' && ForeignPlaces::match($v) !== null;

        return ($unresolved($parsed['pickup_location'] ?? null) && ! $resolvableAbroad($parsed['pickup_location'] ?? null))
            || ($unresolved($parsed['delivery_location'] ?? null) && ! $resolvableAbroad($parsed['delivery_location'] ?? null))
            || empty($parsed['sender_phone']);
    }

    /**
     * Yapay zekadan yapılandırılmış çözümleme ister. Anahtarı girilen sağlayıcılar sırayla denenir; kota (429),
     * sunucu veya ağ hatasında sıradakine geçilir.
     *
     * @return array{status:string, data:?array} status: done | failed | pending (tümü kota/ağ; sonra yeniden denenir) | skipped
     */
    public function enrich(string $message, array $parsed = [], bool $manual = false): array
    {
        // Yapay zekaya en çok ~3.000 karakter gider: ilan metni bundan uzunsa kalanı imza/reklam ya da kuralın zaten böldüğü seri listedir.
        $message = trim(mb_substr($message, 0, self::AI_MESSAGE_CHARS));
        if ($message === '' || ! $this->isConfigured() || (! $manual && ! $this->isEnabled())) {
            return ['status' => 'skipped', 'data' => null];
        }
        // Aynı metin için yapay zeka sonucu 7 gün önbellekte: alım, kuyruk ve denetim aynı mesaja ikinci kez kota harcamaz;
        // yarım kalan iş yeniden denendiğinde çağrı tekrarlanmaz (idempotent).
        $cacheKey = self::resultCacheKey($message);
        if ($manual) {
            Cache::forget($cacheKey); // yönetici "yeniden çözümle" dedi: önbellekten değil, sağlayıcıdan taze cevap
        } elseif (is_array($cached = Cache::get($cacheKey))) {
            return ['status' => 'done', 'data' => $cached, 'cached' => true];
        }
        $exhausted = $this->exhaustedToday();
        $anyRetryable = false;
        $tried = 0;
        $started = microtime(true);
        foreach ($this->chain() as $provider) {
            if (in_array($provider, $exhausted, true) && ! $manual) {
                $anyRetryable = true;

                continue;
            }
            // Devre kesici: art arda 3 zaman aşımı / 5xx veren sağlayıcı 10 dakika atlanır; her mesajda 45 sn beklenmez.
            if (! $manual && $this->coolingDownUntil($provider) !== null) {
                $anyRetryable = true;

                continue;
            }
            // Mesaj başına duvar bütçesi: en çok 2 sağlayıcı ve ~20 sn; kalanı kuyruk (ai-enrich) tamamlar. Yönetici düğmesinde sınır yok.
            if (! $manual && ($tried >= self::AI_MAX_PROVIDERS_PER_MESSAGE || microtime(true) - $started > self::AI_WALL_BUDGET_SECONDS)) {
                $anyRetryable = true;
                break;
            }
            $tried++;
            try {
                $data = $this->callWithModelRepair($message, $provider);
                $this->recordUsage($provider, true, false, $data['_usage'] ?? []);
                $this->rememberError($provider, null);
                $this->noteProviderSuccess($provider);
                unset($data['_usage']);
                $normalized = $this->normalizeAi($data, $provider);
                Cache::put($cacheKey, $normalized, now()->addDays(self::AI_RESULT_CACHE_DAYS));

                return ['status' => 'done', 'data' => $normalized];
            } catch (Throwable $exception) {
                $msg = $exception->getMessage();
                $billing = self::isBillingError($msg);
                $quota = $billing || str_contains($msg, '429') || str_contains(strtolower($msg), 'quota');
                $infrastructure = str_contains($msg, 'provider_http_5') || str_contains($msg, 'cURL') || str_contains($msg, 'timed out') || str_contains($msg, 'Connection');
                $retryable = $quota || $infrastructure;
                $anyRetryable = $anyRetryable || $retryable;
                // Bakiye yok / günlük kota: gün boyu bu sağlayıcıya dönülmez; dakikalık sınır: mesajdaki süre kadar.
                $cooldown = $billing ? 86400 : (self::isDailyQuota($msg) ? self::secondsUntilDailyReset($provider) : ($provider === 'mistral' ? 15 : self::cooldownSeconds($msg)));
                $this->recordUsage($provider, false, $quota, [], $quota ? $cooldown : null);
                $this->rememberError($provider, $msg);
                if ($infrastructure) {
                    $this->noteInfrastructureFailure($provider);
                }
                Log::warning('Yapay zeka çözümlemesi başarısız; sıradaki sağlayıcı denenecek.', ['provider' => $provider, 'model' => $this->model($provider), 'error' => $msg, 'retry' => $retryable]);
            }
        }

        return ['status' => $anyRetryable || $tried === 0 ? 'pending' : 'failed', 'data' => null];
    }

    /** Yapay zekaya giden metnin azami uzunluğu (karakter). */
    public const AI_MESSAGE_CHARS = 3000;

    /** Bir mesaj için art arda en çok bu kadar sağlayıcı denenir (zamanlanmış/alım yolunda). */
    public const AI_MAX_PROVIDERS_PER_MESSAGE = 2;

    /** Bir mesajın yapay zeka çağrıları toplamda yaklaşık bu kadar saniyeyi aşmaz. */
    public const AI_WALL_BUDGET_SECONDS = 20;

    /** Aynı metnin yapay zeka sonucu bu kadar gün saklanır. */
    public const AI_RESULT_CACHE_DAYS = 7;

    /** Devre kesici: art arda bu kadar altyapı hatası (zaman aşımı, 5xx, bağlantı) → sağlayıcı bekletilir. */
    public const BREAKER_FAILURES = 3;

    public const BREAKER_COOLDOWN_MINUTES = 10;

    /** Yapay zeka komutu / şema değişince eski cevaplar önbellekten dönmesin; komuta kural eklenince bu sürüm artırılır. */
    public const PROMPT_VERSION = '2026-10-05b';

    public static function resultCacheKey(string $message): string
    {
        return 'ai:result:'.self::PROMPT_VERSION.':'.hash('sha256', LoadIntakeService::normalizeText(mb_substr($message, 0, self::AI_MESSAGE_CHARS)));
    }

    /** Sağlayıcı devre kesicide bekliyorsa bitiş zamanı, değilse null. */
    public function coolingDownUntil(string $provider): ?Carbon
    {
        $until = Cache::get('ai:breaker:until:'.$provider);

        return is_numeric($until) && (int) $until > now()->timestamp ? Carbon::createFromTimestamp((int) $until, config('app.timezone')) : null;
    }

    private function noteInfrastructureFailure(string $provider): void
    {
        $key = 'ai:breaker:fails:'.$provider;
        $fails = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $fails, now()->addMinutes(30));
        if ($fails >= self::BREAKER_FAILURES) {
            Cache::put('ai:breaker:until:'.$provider, now()->addMinutes(self::BREAKER_COOLDOWN_MINUTES)->timestamp, now()->addMinutes(self::BREAKER_COOLDOWN_MINUTES + 1));
            Cache::forget($key);
            Log::warning('Yapay zeka sağlayıcısı art arda yanıt vermedi; 10 dakika bekletiliyor.', ['provider' => $provider]);
        }
    }

    private function noteProviderSuccess(string $provider): void
    {
        Cache::forget('ai:breaker:fails:'.$provider);
        Cache::forget('ai:breaker:until:'.$provider);
    }

    /**
     * Zincirdeki her sağlayıcının anlık durumu (panel kart satırı): ok | cooldown (devre kesici, bitiş) | exhausted (kota, sıfırlanma).
     *
     * @return array<string, array{state:string, until:?string}>
     */
    public function providerStatus(): array
    {
        $exhausted = $this->exhaustedToday();
        $out = [];
        foreach ($this->chain() as $provider) {
            if (in_array($provider, $exhausted, true)) {
                $until = null;
                try {
                    $until = AiProviderUsage::query()->where('provider', $provider)->whereDate('usage_date', now()->toDateString())->value('quota_resets_at');
                } catch (Throwable) {
                }
                $out[$provider] = ['state' => 'exhausted', 'until' => $until ? Carbon::parse($until)->format('H:i') : null];
            } elseif (($cool = $this->coolingDownUntil($provider)) !== null) {
                $out[$provider] = ['state' => 'cooldown', 'until' => $cool->format('H:i')];
            } else {
                $out[$provider] = ['state' => 'ok', 'until' => null];
            }
        }

        return $out;
    }

    /**
     * 429 gövdesindeki bekleme süresi: Groq "try again in 3.5s / 2m10s", Gemini "retryDelay: 30s".
     * Bulunamazsa 5 dakika (dakikalık hız sınırı gün boyu kilitlemesin); günlük kota mesajında saatler döner.
     */
    public static function cooldownSeconds(string $message): int
    {
        $seconds = 0;
        if (preg_match_all('/(\d+(?:\.\d+)?)\s*(ms|h|m|s)(?=\d|[^a-z]|$)/i', $message, $m, PREG_SET_ORDER)) {
            foreach ($m as $part) {
                $n = (float) $part[1];
                $seconds += match (strtolower($part[2])) {
                    'h' => $n * 3600, 'm' => $n * 60, 'ms' => $n / 1000, default => $n
                };
            }
        }
        if (preg_match('/(\d+)\s*(saat|hour)/iu', $message, $h)) {
            $seconds = max($seconds, (int) $h[1] * 3600);
        }

        return (int) max(10, min(86400, $seconds > 0 ? ceil($seconds) + 5 : 300));
    }

    /** Sağlayıcının son hatası (panelde gösterilir); null başarıyı işler. */
    private function rememberError(string $provider, ?string $message): void
    {
        $key = 'ai:last_error:'.$provider;
        $message === null ? Cache::forget($key) : Cache::put($key, ['message' => mb_substr($message, 0, 300), 'at' => now()->toDateTimeString()], now()->addDays(3));
    }

    /** @return array<string, array{message:string, at:string}> */
    public function lastErrors(): array
    {
        $out = [];
        foreach (array_keys(self::PROVIDERS) as $provider) {
            if ($e = Cache::get('ai:last_error:'.$provider)) {
                $out[$provider] = $e;
            }
        }

        return $out;
    }

    /** Sağlayıcıyı küçük bir örnek ilanla dener; paneldeki "Bağlantıyı sına" düğmesi. */
    public function testProvider(string $provider): array
    {
        if (! array_key_exists($provider, self::PROVIDERS)) {
            return ['ok' => false, 'message' => 'Bilinmeyen sağlayıcı.'];
        }
        if ($this->apiKey($provider) === '') {
            return ['ok' => false, 'message' => 'Anahtar girilmemiş.'];
        }
        $started = microtime(true);
        try {
            $sample = "Ankara Ostim'den İzmir'e 24 ton palet yük, tenteli tır lazım 0532 123 45 67";
            $before = $this->model($provider);
            $data = $this->callWithModelRepair($sample, $provider);
            $this->rememberError($provider, null);
            $norm = $this->normalizeAi($data, $provider);
            $used = $this->lastModelUsed[$provider] ?? $this->model($provider);
            $note = $used !== $before ? " (ayarlı model \"{$before}\" bulunamadı; otomatik seçilen güncel model kullanıldı)" : '';

            return ['ok' => true, 'message' => sprintf('%s · %s → %s · %d ms%s', $used, $norm['pickup_location'] ?? '?', $norm['delivery_location'] ?? '?', (int) ((microtime(true) - $started) * 1000), $note)];
        } catch (Throwable $e) {
            $this->rememberError($provider, $e->getMessage());

            return ['ok' => false, 'message' => self::humanizeError($e->getMessage())];
        }
    }

    /** Sağlayıcı hatasını yöneticiye anlaşılır cümleye çevirir. */
    public static function humanizeError(string $msg): string
    {
        $lower = strtolower($msg);

        return match (true) {
            self::isBillingError($msg) => 'Kredi/bakiye yok: bu sağlayıcının ücretsiz katmanı bitmiş ya da hesaba kredi yüklenmemiş. Bugün bir daha denenmez. '.mb_substr(trim(substr($msg, strpos($msg, ':') !== false ? strpos($msg, ':') + 1 : 0)), 0, 140),
            str_starts_with($msg, 'quota_429') && self::isDailyQuota($msg) => 'Günlük ücretsiz kota doldu (429). Modelin günlük sınırı dolunca başka modelle devam edilir; tüm modeller dolduysa sıfırlanma saatine kadar beklenir. '.mb_substr(trim(substr($msg, 10)), 0, 160),
            str_starts_with($msg, 'quota_429') => 'Dakikalık hız sınırı (429); kısa süre sonra yeniden denenir. '.mb_substr(trim(substr($msg, 10)), 0, 160),
            str_contains($lower, 'api key not valid') || str_contains($lower, 'invalid api key') || str_contains($lower, 'invalid_api_key') || str_starts_with($msg, 'provider_http_401') => 'Anahtar geçersiz (401). Anahtarı sağlayıcı panelinden yeniden kopyalayın.',
            str_contains($lower, 'api_key_service_blocked') || str_contains($lower, 'permission_denied') || str_starts_with($msg, 'provider_http_403') => 'Anahtar bu servise kapalı (403). Google anahtarı Haritalar için kısıtlanmış olabilir; aistudio.google.com/apikey adresinden Gemini için yeni anahtar alın.',
            self::isModelMissing($msg) => 'Model bulunamadı; sağlayıcıda güncel model de seçilemedi. "Modelleri getir" ile listeyi yenileyip model seçin. '.mb_substr(trim(substr($msg, strpos($msg, ':') !== false ? strpos($msg, ':') + 1 : 0)), 0, 160),
            str_starts_with($msg, 'provider_http_400') => 'İstek reddedildi (400). '.trim(substr($msg, 18)),
            str_starts_with($msg, 'provider_http_5') => 'Sağlayıcı geçici olarak yanıt vermiyor (5xx); biraz sonra yeniden denenir.',
            str_contains($lower, 'curl') || str_contains($lower, 'timed out') || str_contains($lower, 'connection') => 'Sunucudan sağlayıcıya bağlanılamadı (ağ/zaman aşımı): '.mb_substr($msg, 0, 160),
            str_contains($msg, 'invalid_provider_json') => 'Sağlayıcı geçerli JSON döndürmedi; model değiştirmeyi deneyin.',
            str_contains($msg, 'refusal') => 'Model yanıtı reddetti.',
            default => mb_substr($msg, 0, 200),
        };
    }

    /**
     * Kural sonucu ile yapay zeka sonucunu birleştirir: yapay zeka boşları doldurur; kuralın çözemediği
     * konumları ve düşük güvenli araç tahminini düzeltir; kesin kural eşleşmeleri (anahtar sözcük) korunur.
     */
    public function merge(array $parsed, array $ai): array
    {
        $out = $parsed;
        $resolvable = fn ($v) => is_string($v) && $v !== '' && TurkishLocations::resolve($v) !== null;

        $conflicts = [];
        foreach (['pickup_location', 'delivery_location'] as $key) {
            if (! empty($ai[$key]) && ! $resolvable($out[$key] ?? null)) {
                $out[$key] = $ai[$key];
            } elseif (! empty($ai[$key]) && $resolvable($out[$key] ?? null)) {
                // İkisi de il buldu ama farklı: yayınlamadan önce insan bakmalı (otomatik onay engeli).
                $ruleResolved = TurkishLocations::resolve((string) $out[$key]);
                $aiResolved = TurkishLocations::resolve((string) $ai[$key]);
                $ruleProvince = $ruleResolved['province_code'] ?? null;
                $aiProvince = $aiResolved['province_code'] ?? null;
                if ($ruleProvince && $aiProvince && $ruleProvince !== $aiProvince) {
                    // Eş adlı ilçe ("Kemalpaşa"): kural metni il adı taşımıyor, yapay zeka aynı ilçeyi iliyle yazdı → yapay zeka doğru okumuştur
                    $ruleDistrict = $ruleResolved['district'] ?? null;
                    $aiDistrict = $aiResolved['district'] ?? null;
                    $ruleNamesProvince = TurkishCities::match((string) $out[$key], fuzzy: false) !== null;
                    if (! $ruleNamesProvince && $ruleDistrict !== null && $aiDistrict !== null && TurkishCities::ascii($ruleDistrict) === TurkishCities::ascii($aiDistrict)
                        && TurkishCities::match((string) $ai[$key], fuzzy: false) !== null) {
                        $out[$key] = $ai[$key];
                    } else {
                        $conflicts[$key] = ['rule' => $out[$key], 'ai' => $ai[$key]];
                    }
                } elseif ($ruleProvince && $aiProvince) {
                    // Aynı il: ilçe/semt bilgisi olan taraf kazanır ("Ankara" → "Ankara Ostim"); ikisinde de varsa kural kalır.
                    $ruleDistrict = TurkishLocations::resolve((string) $out[$key])['district'] ?? null;
                    $aiDistrict = TurkishLocations::resolve((string) $ai[$key])['district'] ?? null;
                    if ($ruleDistrict === null && $aiDistrict !== null) {
                        $out[$key] = $ai[$key];
                    }
                }
            }
        }
        if ($conflicts !== []) {
            $out['ai_conflict'] = $conflicts;
        }
        if (empty($out['sender_phone']) && ! empty($ai['sender_phone'])) {
            $out['sender_phone'] = $ai['sender_phone'];
        }
        // Bir ilanda birden çok numara olabilir: kuralın bulduğu ilk numara asıl, yapay zekanın eklediği diğerleri yedek.
        $phones = array_values(array_unique(array_filter(array_merge(
            [$out['sender_phone'] ?? null], (array) ($out['phones'] ?? []), (array) ($ai['phones'] ?? [])
        ), fn ($p) => is_string($p) && self::isAdPhone($p))));
        if ($phones !== []) {
            $out['phones'] = $phones;
        }
        foreach (['weight', 'price', 'goods_type'] as $key) {
            if (empty($out[$key]) && ! empty($ai[$key])) {
                $out[$key] = $ai[$key];
                if ($key === 'price') {
                    $out['price_unit'] = $ai['price_unit'] ?? null;
                }
            }
        }
        // Kasa, yük biçimi, araç adedi, teslim noktaları: kural bulamadıysa yapay zekanınki (standartlaştırıcı yeniden değerlendirir).
        foreach (['body_types', 'load_kind', 'vehicle_count', 'delivery_stops'] as $key) {
            if (empty($out[$key]) && ! empty($ai[$key])) {
                $out[$key] = $ai[$key];
            }
        }
        $ruleKeyword = ($out['vehicle_type_source'] ?? null) === 'keyword';
        if (! empty($ai['vehicle_type']) && (empty($out['vehicle_type']) || ! $ruleKeyword)) {
            $out['vehicle_type'] = $ai['vehicle_type'];
            // Mesajda araç adı yokken modelin "en küçük uygun araç" tahmini (vehicle_flexible) kesin değildir: filtre ve karar tahmin sayar.
            $out['vehicle_type_source'] = ! empty($ai['vehicle_flexible']) ? 'ai_guess' : 'ai';
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

    /**
     * Yapay zeka çıktısını doğrular ve iç biçime çevirir (araç/yük anahtarları katalogla sınırlıdır).
     * Yeni şema: mesajdaki her ilan "ads" listesinde ayrı bir öğe (her birinin kendi numaraları ve alıntısı var).
     * Eski düz şema (tek nesne, sender_phone) da kabul edilir. Dönen dizide düz anahtarlar İLK ilanı,
     * "ads" tüm ilanları verir.
     */
    private function normalizeAi(array $data, string $provider): array
    {
        $model = $this->lastModelUsed[$provider] ?? $this->model($provider);
        $rawAds = is_array($data['ads'] ?? null) ? array_values(array_filter($data['ads'], 'is_array')) : [];
        $ads = [];
        foreach ($rawAds as $i => $ad) {
            $ads[] = $this->normalizeAd($ad, $provider, $model, $i, count($rawAds));
        }
        if ($ads === []) {
            $ads[] = $this->normalizeAd($data, $provider, $model, 0, 1);
        }
        $messageType = in_array($data['post_type'] ?? null, ['load', 'vehicle_available', 'other'], true) ? $data['post_type'] : null;
        $anyLoad = array_filter($ads, fn (array $a) => $a['is_load']) !== [];
        $first = $ads[0];
        $confidence = self::normalizeConfidence($data['confidence'] ?? null) ?? $first['confidence'];

        return array_merge($first, [
            'post_type' => $messageType ?? $first['post_type'],
            // Mesaj düzeyinde "yük ilanı mı": en az bir ilan yükse evet (mesajın geneli "other" dense bile).
            'is_load' => $anyLoad || ($messageType === 'load'),
            'confidence' => $confidence,
            'multiple_loads' => count($ads) > 1 || (bool) ($data['multiple_loads'] ?? false),
            'notes' => self::cleanText($data['notes'] ?? null, 200) ?? $first['notes'],
            'ads' => $ads,
        ]);
    }

    /** Güven 0-1 beklenir; küçük modeller yüzde (85) yazar → 0,85; 100'den büyük anlamsız → null (eski sürüm 85'i 1,0'a kırpıp "kesin ilan değil" sayıyordu). */
    public static function normalizeConfidence(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $v = (float) $value;
        if ($v > 1.0 && $v <= 100.0) {
            $v /= 100;
        }

        return $v > 1.0 || $v < 0.0 ? null : $v;
    }

    /** Tek bir ilan nesnesini (yeni "ads" öğesi ya da eski düz nesne) iç biçime çevirir. */
    private function normalizeAd(array $data, string $provider, string $model, int $index, int $count): array
    {
        $vehicle = is_string($data['vehicle_type'] ?? null) && VehicleTypes::isValid($data['vehicle_type']) ? $data['vehicle_type'] : null;
        $goodsKey = is_string($data['goods_category'] ?? null) && GoodsCatalog::label($data['goods_category']) ? $data['goods_category'] : null;
        $confidence = self::normalizeConfidence($data['confidence'] ?? null);
        $phones = [];
        foreach (array_merge([$data['sender_phone'] ?? null], is_array($data['phones'] ?? null) ? $data['phones'] : [$data['phones'] ?? null]) as $candidate) {
            $normalized = $this->normalizePhone($candidate);
            if ($normalized !== null && ! in_array($normalized, $phones, true)) {
                $phones[] = $normalized;
            }
        }
        $postType = in_array($data['post_type'] ?? null, ['load', 'vehicle_available', 'other'], true) ? $data['post_type'] : 'load';

        return [
            'provider' => $provider,
            'model' => $model,
            'is_load' => $postType === 'load' && ($data['is_load'] ?? true) !== false,
            'post_type' => $postType,
            'confidence' => $confidence,
            'sender_phone' => $phones[0] ?? null,
            'phones' => $phones,
            'excerpt' => self::cleanText($data['excerpt'] ?? null, 1500),
            'pickup_location' => $this->placeText($data['pickup'] ?? null),
            'delivery_location' => $this->placeText($data['delivery'] ?? null),
            'vehicle_type' => $vehicle,
            'vehicle_flexible' => (bool) ($data['vehicle_flexible'] ?? false),
            'weight' => $this->positiveInteger($data['weight_kg'] ?? null),
            'price' => $this->positiveDecimal($data['price_try'] ?? null),
            'price_unit' => ! empty($data['price_per_ton']) ? 'per_ton' : 'total',
            'goods_type' => $goodsKey ? GoodsCatalog::label($goodsKey) : self::cleanText($data['goods'] ?? null, 120),
            'goods_category' => $goodsKey,
            'goods_text' => self::cleanText($data['goods'] ?? null, 120), // mesajdaki yazım (öğrenme çemberi: sözlük önerisi)
            'urgent' => (bool) ($data['urgent'] ?? false),
            'pickup_date_text' => self::cleanText($data['pickup_date_text'] ?? null, 60),
            'body_types' => BodyTypes::clean($data['body_types'] ?? []),
            'load_kind' => in_array($data['load_kind'] ?? null, ['komple', 'parca'], true) ? $data['load_kind'] : null,
            'vehicle_count' => ($n = $this->positiveInteger($data['vehicle_count'] ?? null)) !== null && $n >= 2 && $n <= 30 ? $n : null,
            'delivery_stops' => array_values(array_filter(array_map(fn ($p) => $this->placeText($p), is_array($data['delivery_stops'] ?? null) ? $data['delivery_stops'] : []))),
            'multiple_loads' => $count > 1 || (bool) ($data['multiple_loads'] ?? false),
            'notes' => self::cleanText($data['notes'] ?? null, 200),
            'ad_index' => $index,
            'ad_count' => $count,
        ];
    }

    /**
     * Yapay zeka sonucundaki ilanlardan verilen numaraya ait olanı seçer (yoksa ilkini). Dönen dizi düz ilan
     * alanlarını taşır; merge() ile kural sonucuna birleştirilir.
     *
     * @return array<string,mixed>
     */
    public static function pickAd(array $aiData, ?string $phone = null, ?string $pickup = null, ?string $delivery = null): array
    {
        $ads = is_array($aiData['ads'] ?? null) && $aiData['ads'] !== [] ? $aiData['ads'] : [array_diff_key($aiData, ['ads' => 1])];
        $chosen = null;
        if ($phone !== null) {
            $byPhone = array_values(array_filter($ads, fn (array $a) => in_array($phone, (array) ($a['phones'] ?? []), true)));
            if (count($byPhone) === 1) {
                $chosen = $byPhone[0];
            } elseif (count($byPhone) > 1 && $pickup && $delivery) {
                // Aynı numaranın birden çok ilanı: il çiftine göre eşle.
                $key = fn (?string $v) => TurkishCities::ascii(TurkishCities::fromText((string) $v) ?? (string) $v);
                foreach ($byPhone as $a) {
                    if ($key($a['pickup_location'] ?? null) === $key($pickup) && $key($a['delivery_location'] ?? null) === $key($delivery)) {
                        $chosen = $a;
                        break;
                    }
                }
                $chosen ??= $byPhone[0];
            }
        }
        $chosen ??= $ads[0];
        unset($chosen['ads']);

        return $chosen;
    }

    /**
     * Metindeki TÜM Türkiye cep numaraları, yazım sırasıyla ve tekrarsız, 10 hane "5xxxxxxxxx".
     *
     * @return list<string>
     */
    public static function phonesIn(string $text): array
    {
        $text = TextPrep::stripInvisible($text);
        // "Fax: 0272…" faks hattıdır, aranmaz; ilan numarası sayılmaz (firma imza bloklarında Cep / İş / Fax alt alta yazılır).
        $text = preg_replace('/(?<!\p{L})fa(?:x|ks)\.?\s*[:\-]?\s*(?:\+?\d[\d\s().\-]{8,}\d)/iu', ' ', $text) ?? $text;
        preg_match_all(self::PHONE_PATTERN, preg_replace(self::IBAN_PATTERN, ' ', $text) ?? $text, $matches);
        $out = [];
        foreach ($matches[0] as $match) {
            $digits = preg_replace('/\D+/', '', $match) ?? '';
            if (str_starts_with($digits, '90') && strlen($digits) === 12) {
                $digits = substr($digits, 2);
            }
            if (str_starts_with($digits, '0') && strlen($digits) === 11) {
                $digits = substr($digits, 1);
            }
            if (self::isAdPhone($digits) && ! in_array($digits, $out, true)) {
                $out[] = $digits;
            }
        }

        return $out;
    }

    /**
     * Normalleştirilmiş rakam dizisi ilan numarası olarak kabul edilir mi? Cep her zaman; sabit hat, 0850/0800 ve 444'lü
     * numara panel ayarı (scraper_landline_phones) açıkken. Nakliye firmaları çağrı merkeziyle ilan verir; cep zorunluluğu
     * bu ilanları "numara yok" diye düşürüyordu (2026-10-05, Osman).
     */
    public static function isAdPhone(?string $digits): bool
    {
        if (Phone::isMobile($digits)) {
            return true;
        }

        return Phone::isContact($digits) && Settings::bool('scraper_landline_phones');
    }

    /** {province, district} nesnesini "İl İlçe" metnine çevirir. */
    private function placeText(mixed $place): ?string
    {
        if (is_string($place)) {
            return self::cleanText($place, 120);
        }
        if (! is_array($place)) {
            return null;
        }
        $province = self::cleanText($place['province'] ?? null, 60);
        $district = self::cleanText($place['district'] ?? null, 60);
        if ($province === null) {
            return self::cleanText($place['text'] ?? null, 120);
        }

        return trim($province.' '.($district ?? ''));
    }

    /** Sağlayıcıdan bağımsız istem ve JSON şeması. */
    public static function systemPrompt(): string
    {
        $vehicles = implode("\n", array_map(fn ($k, $v) => "- {$k}: {$v['label']} (~".number_format($v['capacity_kg'] / 1000, 0).' ton kapasite)', array_keys(VehicleTypes::TYPES), VehicleTypes::TYPES));
        $goods = implode(', ', array_map(fn ($k, $l) => "{$k} ({$l})", array_keys(GoodsCatalog::labels()), GoodsCatalog::labels()));
        $bodies = implode(', ', array_map(fn ($k, $l) => "{$k} ({$l})", array_keys(BodyTypes::labels()), BodyTypes::labels()));

        return <<<TXT
Sen Türkiye kara nakliye sektöründe WhatsApp gruplarına yazılan yük ilanlarını çözümleyen bir asistansın.
Verilen mesaj çoğunlukla kısa, yazım hatalı, kısaltmalı ve Türkçe karakterleri eksik olabilir ("Diyarbakr", "istanbl", "tn" = ton, "bin" = ×1000, "komple" = aracın tamamı, "parsiyel" = kısmi yük, "acil" = acele).

Görevin: mesajı anlayıp yapılandırılmış alanlara ayırmak. Kurallar:
1. post_type: "load" = taşınacak bir yük ve araç aranıyor; "vehicle_available" = boş araç/şoför yük arıyor (yük ilanı DEĞİL); "other" = sohbet, araç satışı, iş ilanı, reklam. Üstteki post_type mesajın geneli, her ilanın içindeki kendi türüdür (karışık mesajda yalnız yük olanlar ads listesine girer).
2. pickup ve delivery: Türkiye il adı (resmi yazım, ör. "Diyarbakır", "İstanbul") ve varsa ilçe/semt. "X'den Y'ye", "X - Y", "X → Y", "Xdan Yya" kalıplarında X kalkış, Y varıştır. İlçe verildiyse ilini sen bul (Kartal → İstanbul, Gebze → Kocaeli, Nazilli → Aydın). Gün adları ("Pazar günü", "Çarşamba sabahı") yer değildir; "X yüklemeleri" başlığı altındaki il/ilçe satırları ayrı varış noktalarıdır.
3. vehicle_type: yalnız şu anahtarlardan biri; mesajda araç adı yoksa tonaja/yüke göre EN KÜÇÜK uygun aracı seç ve vehicle_flexible=true yap:
{$vehicles}
"tenteli", "dorse", "çekici", "mega", "lowbed", "silobas" → tir. "Kapalı kasa kamyon" → tonaja göre kamyon. Panelvan, minivan, Doblo/Transit gibi hafif ticari → panelvan. Otomobil yoktur; evrak gibi küçük yük → panelvan.
4. weight_kg: kilogram tam sayı ("24 tn" → 24000, "12,5 ton" → 12500, "800 kg" → 800). "Basar tonaj" = aracın taşıyabildiği azami tonaj, yük tonajı sayılır. Palet adedi tonaj değildir.
5. price_try: Türk lirası ("45 bin" → 45000, "38.000 tl" → 38000, "45k" → 45000). KDV notu fiyatı değiştirmez. Yoksa null. price_per_ton: fiyat ton başına ise true ("ton başı 1200", "tonu 950", "+basar", dökme yükte "1000+kdv"), toplam navlun ise false.
6. goods_category: yalnız şu anahtarlardan biri ya da null: {$goods}. goods: mesajdaki yük tanımı kısa metin.
7. phones: o ilana ait TÜM Türkiye numaraları, başındaki 0 ve +90 atılmış rakamlarla: cep "5xxxxxxxxx" (10 hane), sabit hat "212xxxxxxx" / "312xxxxxxx" (10 hane), kurumsal hat "850xxxxxxx" (10 hane), çağrı merkezi "444xxxx" (7 hane). Bir ilanda birden fazla kişi/numara olabilir ("Ahmet 0532…, Mehmet 0533…"); hepsini sırayla yaz. Yabancı ülke numaralarını yazma.
8. urgent: acil/hemen/bugün gibi ifadeler varsa true. pickup_date_text: yükleme zamanı ifadesi ("yarın", "pazartesi", "12.05") aynen.
9. ads: mesajdaki HER ayrı yük ilanı için bir öğe (bir mesajda 5-10 ilan olabilir). Ayrı ilan = ayrı rota ya da ayrı yük. Aynı firmanın farklı rotaları ayrı ilandır; aynı rotanın tekrar yazılması tek ilandır. Mesajın sonunda/başında ortak bir irtibat numarası varsa o numarayı her ilanın phones listesine ekle. excerpt: o ilana ait satırları mesajdan AYNEN kopyala (kısaltma, düzeltme, çeviri yapma); sistem her ilanı ayrı saklar ve alıntıyı gösterir. Yük ilanı yoksa ads boş liste olsun.
10. confidence: 0 ile 1 arasında; mesaj belirsizse düşük ver (mesaj geneli için üstte, her ilan için ilanın içinde). Tahmin etmek zorunda kaldığın alanları notes içinde kısaca belirt.
11. Reklam/imza satırlarını yok say ("Bu ilan VIP grubundan paylaşılmıştır", "gruba katılmak için…", web adresleri); bunlar konum ya da yük değildir.
12. Emoji etiketli biçim yaygındır: 📍 genelde kalkış, 📦 ya da 🏁 varış, 💰 fiyat, 🚚 araç, ☎️ telefon. "1200+KDV" fiyatı 1200 TL, KDV hariç demektir (notes'a "KDV hariç" yaz). "1 araç" araç adedi, tonaj değildir. Yük emojileri yükü anlatır: 🍇 üzüm, 🦴 kemik, 🍅 domates, 🌾 buğday.
13. body_types: kasa/dorse tipleri, şu anahtarlardan liste: {$bodies}. Sektör dili: "13.60" tek başına = damper hariç (tenteli, kapali, acik, frigo + uzun_dorse); "sadece frigo/damper/tenteli/sal açık" = yalnız o; "her türlü dorseye uygun / fark etmez" = boş liste; "dökme yük" = damperli; "dökme, damper ile 13.60 açık sal da uyar" = damperli + acik; "kasalı" (meyve/sebze) = kapali, tenteli, frigo; kemik/kömür/dökme üzüm/buğday/cüruf = damperli; donuk gıda = frigo. Kapalı ve tenteli AYRI tiplerdir; "tenteli" yazıyorsa kapali ekleme. Kasa yazmıyorsa ve yükten çıkmıyorsa boş liste.
14. load_kind: "komple"/"tırlık"/tam araç ya da 18 ton ve üstü = "komple"; "parça yük"/"parsiyel"/birkaç palet/"araçta boşluk" = "parca"; belirsizse null. vehicle_count: istenen araç adedi ("2 yer" = 2 ayrı araç, "5 araç", "3 tır"); tek araçsa null.
15. delivery_stops: aynı satırda "+" ile bağlı varışlar tek araçla sırayla boşaltılır ("Çorum+Ankara+Denizli" = 1 ilan, 3 teslim noktası); bunları sırayla yaz, delivery ilk nokta olsun. Alt alta yazılan farklı iller ise AYRI ilanlardır (her biri ayrı araç); onları ads listesine ayrı ayrı koy.
Bilinmeyen alanları null bırak, uydurma.
TXT;
    }

    /**
     * İstemde kullanılan kısa alan listesi (OpenAI uyumlu uçlar ve Gemini). Tam JSON şeması (~2.000 jeton) her çağrıda gidiyordu;
     * bu özet aynı alanları ~300 jetonla anlatır. Claude kendi yapılandırılmış çıktı şemasını kullanır (outputSchema).
     */
    public static function compactSchema(): string
    {
        $vehicles = implode('|', array_keys(VehicleTypes::TYPES));
        $goods = implode('|', array_keys(GoodsCatalog::labels()));
        $bodies = implode('|', array_keys(BodyTypes::TYPES));

        return '{"post_type":"load|vehicle_available|other","confidence":0-1,"notes":metin|null,"ads":[{'
            .'"post_type":"load|vehicle_available|other","confidence":0-1,"phones":["5xxxxxxxxx"],"excerpt":metin|null,'
            .'"pickup":{"province":il|null,"district":ilçe|null}|null,"delivery":{"province":il|null,"district":ilçe|null}|null,'
            .'"vehicle_type":"'.$vehicles.'"|null,"vehicle_flexible":bool,"weight_kg":tam sayı|null,"price_try":sayı|null,"price_per_ton":bool,'
            .'"goods":metin|null,"goods_category":"'.$goods.'"|null,"urgent":bool,"pickup_date_text":metin|null,'
            .'"body_types":["'.$bodies.'"],"load_kind":"komple|parca"|null,"vehicle_count":tam sayı|null,'
            .'"delivery_stops":[{"province":il,"district":ilçe|null}],"notes":metin|null}]}';
    }

    /** @return array<string, mixed> JSON şeması (Claude yapılandırılmış çıktı): mesaj geneli + her ilan için "ads" öğesi */
    public static function outputSchema(): array
    {
        $nullable = fn (string $type) => ['anyOf' => [['type' => $type], ['type' => 'null']]];
        $place = ['anyOf' => [
            ['type' => 'object', 'properties' => ['province' => $nullable('string'), 'district' => $nullable('string')], 'required' => ['province', 'district'], 'additionalProperties' => false],
            ['type' => 'null'],
        ]];
        $ad = [
            'type' => 'object',
            'properties' => [
                'post_type' => ['type' => 'string', 'enum' => ['load', 'vehicle_available', 'other']],
                'confidence' => ['type' => 'number'],
                'phones' => ['type' => 'array', 'items' => ['type' => 'string']],
                'excerpt' => $nullable('string'),
                'pickup' => $place,
                'delivery' => $place,
                'vehicle_type' => ['anyOf' => [['type' => 'string', 'enum' => array_keys(VehicleTypes::TYPES)], ['type' => 'null']]],
                'vehicle_flexible' => ['type' => 'boolean'],
                'weight_kg' => $nullable('integer'),
                'price_try' => $nullable('number'),
                'price_per_ton' => ['type' => 'boolean'],
                'goods' => $nullable('string'),
                'goods_category' => ['anyOf' => [['type' => 'string', 'enum' => array_keys(GoodsCatalog::labels())], ['type' => 'null']]],
                'urgent' => ['type' => 'boolean'],
                'pickup_date_text' => $nullable('string'),
                'body_types' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys(BodyTypes::TYPES)]],
                'load_kind' => ['anyOf' => [['type' => 'string', 'enum' => ['komple', 'parca']], ['type' => 'null']]],
                'vehicle_count' => $nullable('integer'),
                'delivery_stops' => ['type' => 'array', 'items' => $place],
                'notes' => $nullable('string'),
            ],
            'required' => ['post_type', 'confidence', 'phones', 'excerpt', 'pickup', 'delivery', 'vehicle_type', 'vehicle_flexible', 'weight_kg', 'price_try', 'price_per_ton', 'goods', 'goods_category', 'urgent', 'pickup_date_text', 'body_types', 'load_kind', 'vehicle_count', 'delivery_stops', 'notes'],
            'additionalProperties' => false,
        ];

        return [
            'type' => 'object',
            'properties' => [
                'post_type' => ['type' => 'string', 'enum' => ['load', 'vehicle_available', 'other']],
                'confidence' => ['type' => 'number'],
                'notes' => $nullable('string'),
                'ads' => ['type' => 'array', 'items' => $ad],
            ],
            'required' => ['post_type', 'confidence', 'notes', 'ads'],
            'additionalProperties' => false,
        ];
    }

    /** Claude Messages API (yapılandırılmış çıktı). Tek istek, kısa yanıt; sistem istemi önbelleklenir. */
    /** @var array<string,string> Son çağrıda gerçekten kullanılan model (404 onarımı sonrası) */
    private array $lastModelUsed = [];

    /**
     * Sağlayıcıyı çağırır; model bulunamadı (404 / "does not exist" / "no longer available") yanıtı gelirse
     * güncel listeden otomatik model seçip bir kez daha dener. Ayarlı model geçersizse ayar "Otomatik"e çevrilir.
     */
    private function callWithModelRepair(string $message, string $provider): array
    {
        $model = $this->model($provider);
        try {
            $data = $this->callProvider($message, $provider, $model);
            $this->lastModelUsed[$provider] = $model;

            return $data;
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            if (str_starts_with($msg, 'quota_429') && self::isDailyQuota($msg) && ! self::isBillingError($msg) && Settings::string('ai_'.$provider.'_model') === '') {
                // Günlük kota modele özeldir (Gemini, Groq): bu modeli gün sonuna kadar dışla, sıradaki uygun modelle sürdür.
                $this->markModelExhausted($provider, $model);
                for ($i = 0; $i < 2; $i++) {
                    $next = $this->autoModel($provider, exclude: $model);
                    if ($next === $model || $next === '') {
                        break;
                    }
                    Log::info('Modelin günlük kotası doldu; başka modelle devam ediliyor.', ['provider' => $provider, 'old' => $model, 'new' => $next]);
                    try {
                        $data = $this->callProvider($message, $provider, $next);
                        $this->lastModelUsed[$provider] = $next;

                        return $data;
                    } catch (RuntimeException $again) {
                        if (! (str_starts_with($again->getMessage(), 'quota_429') && self::isDailyQuota($again->getMessage()))) {
                            throw $again;
                        }
                        $this->markModelExhausted($provider, $next);
                        $model = $next;
                        $e = $again;
                    }
                }
                throw $e;
            }
            if (! self::isModelMissing($msg)) {
                throw $e;
            }
            $replacement = $this->autoModel($provider, exclude: $model);
            if ($replacement === $model) {
                throw $e;
            }
            Log::warning('Yapay zeka modeli bulunamadı; otomatik model seçildi.', ['provider' => $provider, 'old' => $model, 'new' => $replacement]);
            if (Settings::string('ai_'.$provider.'_model') === $model) {
                Settings::set('ai_'.$provider.'_model', ''); // ayar "Otomatik": bir daha kalkan modele takılmasın
            }
            $data = $this->callProvider($message, $provider, $replacement);
            $this->lastModelUsed[$provider] = $replacement;

            return $data;
        }
    }

    public static function isModelMissing(string $msg): bool
    {
        $l = strtolower($msg);

        return str_starts_with($msg, 'provider_http_404')
            || str_contains($l, 'does not exist')
            || str_contains($l, 'no longer available')
            || str_contains($l, 'not found')
            || str_contains($l, 'is not supported')
            || str_contains($l, 'model_not_found')
            || str_contains($l, 'decommissioned')
            || (str_starts_with($msg, 'provider_http_400') && str_contains($l, 'model'));
    }

    private function callProvider(string $message, string $provider, string $model): array
    {
        return match (self::PROVIDERS[$provider]['kind']) {
            'gemini' => $this->callGemini($message, $provider, $model),
            'claude' => $this->callClaude($message, $provider, $model),
            default => $this->callOpenAiCompatible($message, $provider, $model),
        };
    }

    private function callClaude(string $message, string $provider = 'claude', ?string $model = null): array
    {
        $model ??= $this->model($provider);
        $body = [
            'model' => $model,
            'max_tokens' => 4096,
            'system' => [['type' => 'text', 'text' => self::systemPrompt(), 'cache_control' => ['type' => 'ephemeral']]],
            'messages' => [['role' => 'user', 'content' => "İlan mesajı:\n".$message]],
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => self::outputSchema()]],
        ];
        $headers = ['x-api-key' => $this->apiKey($provider), 'anthropic-version' => '2023-06-01'];
        if (! str_contains($model, 'haiku')) {
            $body['output_config']['effort'] = 'low'; // basit çıkarım görevi: hız ve maliyet
        }
        if (str_starts_with($model, 'claude-opus-5') || str_starts_with($model, 'claude-fable')) {
            $body['fallbacks'] = 'default'; // güvenlik sınıflandırıcısı reddederse sunucu tarafında yedek model
            $headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
        }

        $response = Http::timeout($this->timeout($provider))->connectTimeout($this->connectTimeout($provider))->withHeaders($headers)->post('https://api.anthropic.com/v1/messages', $body);
        if ($response->status() === 429) {
            throw new RuntimeException('quota_429: '.mb_substr((string) data_get($response->json(), 'error.message', $response->body()), 0, 300));
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
    /** OpenAI uyumlu sohbet ucu (Groq, Cerebras, OpenRouter, Mistral): JSON kipi + şema istemde. */
    private function callOpenAiCompatible(string $message, string $provider, ?string $model = null): array
    {
        $model ??= $this->model($provider);
        $base = $this->baseUrl($provider);
        $headers = ['Authorization' => 'Bearer '.$this->apiKey($provider)];
        if ($provider === 'openrouter') {
            $headers['HTTP-Referer'] = (string) config('app.url');
            $headers['X-Title'] = 'NavlunIQ';
        }
        $body = [
            'model' => $model,
            'temperature' => 0,
            'max_tokens' => 4096,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => self::systemPrompt()."\nYanıtı yalnız şu biçimde tek bir JSON nesnesi olarak ver, başka metin yazma:\n".self::compactSchema()],
                // Yerel model (Qwen3): "/no_think" düşünme kipini kapatır; işlemcide dakikalar süren akıl yürütme metni üretilmez.
                ['role' => 'user', 'content' => "İlan mesajı:\n".$message.(! empty(self::PROVIDERS[$provider]['local']) ? "\n/no_think" : '')],
            ],
        ];
        $this->paceRequests($provider);
        $response = Http::timeout($this->timeout($provider))->connectTimeout($this->connectTimeout($provider))->acceptJson()->withHeaders($headers)->post($base.'/chat/completions', $body);
        if ($response->status() === 429 || $response->status() === 402) {
            throw new RuntimeException(($response->status() === 402 ? 'provider_http_402: ' : 'quota_429: ').mb_substr((string) data_get($response->json(), 'error.message', $response->body()), 0, 300));
        }
        $decoded = null;
        if ($response->status() === 400 && is_string($failed = data_get($response->json(), 'error.failed_generation'))) {
            // Groq: model JSON kipinde geçersiz çıktı verdi ("Failed to validate JSON"); ham üretim yine de gövdede gelir.
            // Önce onu kurtarmayı dener, olmazsa JSON kipi olmadan bir kez daha ister (yanıt metinden ayıklanır).
            $decoded = self::decodeLenient($failed);
            if ($decoded === null) {
                unset($body['response_format']);
                $response = Http::timeout($this->timeout($provider))->connectTimeout($this->connectTimeout($provider))->acceptJson()->withHeaders($headers)->post($base.'/chat/completions', $body);
            }
        }
        if ($decoded === null) {
            if (! $response->successful()) {
                throw new RuntimeException('provider_http_'.$response->status().': '.mb_substr((string) data_get($response->json(), 'error.message', $response->body()), 0, 200));
            }
            $text = (string) data_get($response->json(), 'choices.0.message.content', '');
            $decoded = self::decodeLenient($text);
        }
        if (! is_array($decoded)) {
            throw new RuntimeException('invalid_provider_json');
        }
        $decoded['_usage'] = ['input' => (int) data_get($response->json(), 'usage.prompt_tokens', 0), 'output' => (int) data_get($response->json(), 'usage.completion_tokens', 0)];

        return $decoded;
    }

    /** Saniyede 1 istek sınırı olan sağlayıcılarda (Mistral) ardışık çağrılar arasında en az 1,2 sn bırakır. */
    private function paceRequests(string $provider): void
    {
        if ($provider !== 'mistral') {
            return;
        }
        $key = 'ai:last_call:'.$provider;
        $last = (float) Cache::get($key, 0);
        $wait = 1.2 - (microtime(true) - $last);
        if ($wait > 0 && $wait < 5) {
            usleep((int) ($wait * 1_000_000));
        }
        Cache::put($key, microtime(true), now()->addMinute());
    }

    private function callGemini(string $message, string $provider = 'gemini', ?string $model = null): array
    {
        $model ??= $this->model($provider);
        $response = Http::timeout($this->timeout($provider))->connectTimeout($this->connectTimeout($provider))->acceptJson()->withHeaders(['x-goog-api-key' => $this->apiKey($provider)])->post(
            'https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent',
            [
                'systemInstruction' => ['parts' => [['text' => self::systemPrompt()."\nYanıtı yalnız şu biçimde ver:\n".self::compactSchema()]]],
                'contents' => [['parts' => [['text' => "İlan mesajı:\n".$message]]]],
                'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0, 'maxOutputTokens' => 4096],
            ]
        );
        if ($response->status() === 429) {
            $quotaIds = collect((array) data_get($response->json(), 'error.details', []))->flatMap(fn ($d) => (array) ($d['violations'] ?? []))->pluck('quotaId')->filter()->implode(',');
            $retry = (string) collect((array) data_get($response->json(), 'error.details', []))->pluck('retryDelay')->filter()->first();
            throw new RuntimeException('quota_429: '.mb_substr((string) data_get($response->json(), 'error.message', $response->body()), 0, 240).($quotaIds !== '' ? ' quotaId='.$quotaIds : '').($retry !== '' ? ' retryDelay='.$retry : ''));
        }
        if (! $response->successful()) {
            throw new RuntimeException('provider_http_'.$response->status().': '.mb_substr((string) data_get($response->json(), 'error.message', $response->body()), 0, 200));
        }
        $text = (string) data_get($response->json(), 'candidates.0.content.parts.0.text', '');
        $decoded = json_decode($this->cleanJsonString($text), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('invalid_provider_json');
        }
        $decoded['_usage'] = ['input' => (int) data_get($response->json(), 'usageMetadata.promptTokenCount', 0), 'output' => (int) data_get($response->json(), 'usageMetadata.candidatesTokenCount', 0)];

        return $decoded;
    }

    /** Metinde geçen ilk iki farklı ili (yazım sırasıyla, yakın eşleme olmadan) döndürür. */
    public static function firstTwoProvinces(string $text): array
    {
        return self::provincesIn($text, 2);
    }

    /**
     * Metinde geçen farklı illeri yazım sırasıyla (yakın eşleme olmadan) döndürür; en fazla $limit tane.
     *
     * @return list<string>
     */
    public static function provincesIn(string $text, int $limit = PHP_INT_MAX): array
    {
        $found = [];
        foreach (preg_split('/[\s,\/;:()]+/u', $text) ?: [] as $word) {
            $word = trim($word, '.-!?');
            if (mb_strlen($word) < 3 || preg_match('/\d/u', $word)) {
                continue;
            }
            $province = TurkishCities::fromText($word, fuzzy: false);
            if ($province !== null && ! in_array($province, $found, true)) {
                $found[] = $province;
                if (count($found) >= $limit) {
                    break;
                }
            }
        }

        return $found;
    }

    private function parseWithRegex(string $message, ?string $fallbackPhone = null): array
    {
        // "0532 123 45 67", "0 (532) 123-45-67" gibi boşluklu/ayraçlı yazımlar da telefon sayılır.
        $phones = self::phonesIn($message);
        if ($phones === [] && $fallbackPhone !== null && self::isAdPhone($fallbackPhone)) {
            $phones = [$fallbackPhone]; // gönderen numarası bildirim başlığından geldi
        }
        $phone = $phones[0] ?? null;

        // Biçim işaretleri, emoji oklar, süs satırları temizlenir; kesme işaretleri rota eşlemesini bozmasın.
        $message = TextPrep::prepare($message);
        $routeText = preg_replace("/[’'‘`]/u", '', $message) ?? $message;
        // Bağlaçtan önce ve sonra en fazla iki sözcük alınır ("Ankara Ostim" → "İzmir Aliağa").
        // Kesin çözüm: birebir il/ilçe, yurt dışı yer ya da 6+ harfli il adında yazım hatası. İlçe adında yakın eşleme yok
        // ("pres saman" → Kaman olmasın).
        $resolvable = fn (?string $v) => $v !== null && (TurkishLocations::resolve($v, false) !== null || ForeignPlaces::match($v) !== null
            || (mb_strlen($v) >= 6 && ! str_contains(trim($v), ' ') && TurkishCities::fromText($v, fuzzy: true) !== null));
        // Bağlaç eşleşmeleri arasında iki ucu da çözülen ilki alınır ("Beykoz-Şanlıurfa … Bursa - Gaziantep" → Beykoz-Şanlıurfa).
        [$pickup, $delivery] = [null, null];
        foreach (self::connectorMatches($routeText) as $m) {
            if ($resolvable($m['pickup']) && $resolvable($m['delivery'])) {
                [$pickup, $delivery] = [$m['pickup'], $m['delivery']];
                break;
            }
            if ($pickup === null && $delivery === null) {
                [$pickup, $delivery] = [$m['pickup'], $m['delivery']];
            }
        }
        // Bağlaç yoksa ya da çözülmüyorsa: satır rolleri ("X yükler / Y iner"), sonra metindeki ilk iki farklı yer
        // (il ya da il+ilçe; "Denizli Bursa 8 ton…", "📍 Ankara 📦 İstanbul", "İstanbul Arnavutköy Şırnak Silopi").
        if (! $resolvable($pickup) || ! $resolvable($delivery)) {
            if (($byLines = self::routeFromLines($routeText)) !== null) {
                [$pickup, $delivery] = $byLines;
            } else {
                $places = self::placesIn($routeText, 4);
                // İl adı yazılmış (kesin) yerler en az ikiyse tek başına ilçe/semt sözcükleri ("Kartal", "Kale") çifte girmez
                $strongOnes = array_values(array_filter($places, fn ($p) => $p['strong'] ?? false));
                if (count($strongOnes) >= 2) {
                    $places = $strongOnes;
                }
                $pair = null;
                foreach ($places as $i => $p) {
                    foreach (array_slice($places, $i + 1) as $q) {
                        if ($q['label'] !== $p['label']) {
                            // Hal eki yönü belirler: "İzmire gidecek Bursadan" → Bursa → İzmir
                            $pCase = $p['case'] ?? null;
                            $qCase = $q['case'] ?? null;
                            $pair = ($pCase === 'to' && $qCase !== 'to') || ($qCase === 'from' && $pCase !== 'from') ? [$q['label'], $p['label']] : [$p['label'], $q['label']];
                            break 2;
                        }
                    }
                }
                if ($pair !== null) {
                    [$pickup, $delivery] = $pair;
                } elseif (count($places) === 1 && preg_match('/(?<!\p{L})(?:şehir\s*içi|sehir\s*ici|şehiriçi|il\s*içi|il\s*ici|dahili)(?!\p{L})/u', TurkishCities::lower($routeText))) {
                    [$pickup, $delivery] = [$places[0]['label'], $places[0]['label']]; // şehir içi: kalkış = varış
                } else {
                    $pickup = $resolvable($pickup) ? $pickup : null;
                    $delivery = $resolvable($delivery) ? $delivery : null;
                }
            }
        }
        if (! $phone || ! $pickup || ! $delivery) {
            // Yarım çözüm kaybolmaz: çözülen uç ve numaralar döner ki yapay zeka ulaşılamazsa eksik kayıt açılabilsin (alım) ve
            // yapay zeka yalnız boş kalan ucu doldursun (merge). Çözülemeyen uç null kalır.
            return array_merge($this->failure('regex_required_fields_missing'), [
                'sender_phone' => $phone,
                'phones' => $phones,
                'pickup_location' => $resolvable($pickup) ? $pickup : null,
                'delivery_location' => $resolvable($delivery) ? $delivery : null,
            ]);
        }

        // "45.000 TL", "45000₺", "1.250,50 TL" ve "12,5 ton" gibi Türkçe sayı yazımları tanınır.
        preg_match('/(?<!\d)(\d{1,3}(?:\.\d{3})+(?:,\d{1,2})?|\d{1,3}(?: \d{3})+|\d{3,9}(?:[.,]\d{1,2})?)\s*(?:TL|₺|lira)(?!\p{L})/iu', $message, $price);
        if (empty($price[1])) { // "1200+KDV", "1.200 + kdv", "1200 tl+kdv", "950+BASAR", "900+TONAJLI", "40.000 Peşin": KDV/basar tonaj hariç tutar
            // "artı" = "+": "1500 artı kdv", "1500 artı" (Engin Abi, 2026-10-05: Mersin yem ilanı fiyatsız kalıyordu)
            // "art" = "artı" kısaltması; virgül ya da bitişik yazım da olur: "2450art", "2330artkdv", "2450,art", "yüklenir2450art" (Engin Abi, 2026-10-08)
            preg_match('/(?<!\d)(\d{1,3}(?:\.\d{3})+|\d{3,9})\s*(?:TL|₺)?\s*,?\s*(?:(?:\+|artı|arti|art|ARTI|ARTİ|ART|Artı)\s*(?:KDV|kdv|Kdv|BASAR|basar|TONAJLI|TONAJLİ|tonajlı|KDV\s*HARİÇ|kdv\s*hariç)|(?=\s*(?:PEŞİN|PESİN|PESIN|NAKİT|NAKIT)))/iu', $message, $price);
        }
        if (empty($price[1]) && preg_match('/(?<![\d.,])(\d{1,2}\.\d{3}|\d{3,5})\s*,?\s*(?:artı|arti|art)(?!\p{L})/iu', $message, $m)) { // "3350art 1360", "2750 art", "2450,art"
            $price = [1 => $m[1]];
        }
        if (empty($price[1]) && preg_match('/(?<![\d.,])(\d{1,2}\.\d{3}|\d{3,5})\s*(?:TL|₺)?\s*(?:almaz\s*vermez|net|sabit)(?!\p{L})/iu', $message, $m)) { // "1500 almaz vermez": pazarlıksız fiyat
            $price = [1 => $m[1]];
        }
        if (empty($price[1]) && preg_match('/(?<![\d.,])(\d{1,2}\.\d{3}|\d{3,5})\s*\+(?=\s+(?:bir|iki|üç|uc|dört|dort|beş|bes|\d{1,2}|tır|tir|araç|arac|hemen|nakit|peşin|pesin)\b)/iu', $message, $m)) { // "2300 + Bir tır", "2900+ nakit"
            $price = [1 => $m[1]];
        }
        if (empty($price[1]) && preg_match('/(?<![\d.,])(\d{3,5})\s*(?:\+|artı|arti|ARTI|ARTİ|Artı)\s*$/mu', $message, $m)) { // "BOLU 1600+", "ORDU 1 TIR 2300+", "1500 artı": satır sonunda "+" = KDV hariç fiyat
            $price = [1 => $m[1]];
        }
        if (empty($price[1]) && preg_match('/^[^\d\n]{3,}?\s(\d{1,2}\.\d{3}|[1-9]\d{3})[ \t]*$/mu', $message, $m) && (int) str_replace('.', '', $m[1]) !== 1360) {
            // Varış listesinde yer adının yanında çıplak sayı: "KARAPINAR 1.750", "BOZKIR 1850" (ton başı navlun; 13.60 dorse ölçüsü değil)
            $price = [1 => $m[1]];
        }
        if (empty($price[1])) { // "28+kdv" = 28 bin (nakliyecinin "bin" düşürme alışkanlığı): 10-299 arası +kdv → ×1000
            if (preg_match('/(?<![\d.,])(\d{2,3})\s*\+\s*KDV/iu', $message, $m)) {
                $price = [1 => (string) ((int) $m[1] * 1000)];
            }
        }
        $currency = 'TRY';
        if (empty($price[1]) && preg_match('/(?<!\d)(\d{1,3}(?:\.\d{3})+|\d{3,6})\s*(?:\$|USD|usd|dolar|DOLAR|€|EUR|euro|EURO)/u', $message, $m)) {
            $price = [1 => $m[1]];
            $currency = str_contains($m[0], '€') || stripos($m[0], 'eur') !== false ? 'EUR' : 'USD';
        }
        $priceValue = $this->positiveDecimal(isset($price[1]) ? str_replace(' ', '', (string) $price[1]) : null);
        preg_match('/(?<!\d)(\d{1,3}(?:\.\d{3})+|\d{1,6}(?:[.,]\d{1,3})?)\s*(kg|ton|tn)(?!\p{L})/iu', $message, $weight);
        $weightKg = $this->positiveDecimal($weight[1] ?? null);
        if ($weightKg !== null && isset($weight[2]) && in_array(strtolower($weight[2]), ['ton', 'tn'], true)) {
            $weightKg *= 1000;
        }
        if ($weightKg !== null && ($weightKg < 50 || $weightKg > 60000)) {
            $weightKg = null; // "24.500 ton" gibi okuma hatası: üst sınır 60 ton (weightFromText ile aynı)
        }
        // "24t", "20-25 ton", "yirmi dört ton" gibi yazımlar sınıflandırıcının tonaj çözümüyle yakalanır.
        $weightKg ??= VehicleClassifier::weightFromText(VehicleClassifier::normalize($message));
        preg_match('/(?:yük|mal|ürün)\s*[:\-]\s*([\p{L}\d\s]{2,80})/iu', $message, $goods);

        return [
            'success' => true,
            'sender_phone' => $phone,
            'phones' => $phones,
            'pickup_location' => $pickup,
            'delivery_location' => $delivery,
            'goods_type' => self::cleanText($goods[1] ?? null, 120),
            'weight' => $weightKg !== null ? (int) round($weightKg) : null,
            'price' => $priceValue,
            'price_unit' => $priceValue !== null ? self::priceUnitFromText($message, $priceValue) : null,
            'currency' => $currency,
            'vehicle_type' => ($vehicle = VehicleTypes::detect($message, $weightKg !== null ? (int) round($weightKg) : null))['type'],
            'vehicle_type_source' => $vehicle['source'],
            'parsed_by_llm' => 'regex_verified',
        ];
    }

    /** Dökme/ağırlığa göre fiyatlanan yükler: ton başına fiyat yazımı yaygındır. */
    public const BULK_GOODS_PATTERN = '/\b(?:dökme|dokme|damper|danper|basar|tonaj|kömür|komur|mucur|kum|çakıl|cakil|maden|tuz|gübre|gubre|hububat|buğday|bugday|arpa|mısır|misir|silaj|saman|pancar|klinker|çimento|cimento|kireç|kirec|taş|tas|toprak|üzüm|uzum|cüruf|curuf|kül|kul|talaş|talas|yonca|hurda|demir|rulo|bims|tuğla|tugla|kiremit|mermer|blok)\b/iu';

    /**
     * Fiyat birimi: "total" (toplam navlun) ya da "per_ton" (ton başına). Ton başına sayılanlar: açık yazım
     * ("ton başı 1200", "tonu 950", "1000 tl/ton"), "+basar" / "+tonajlı" (kantar tonajıyla ödenir) ve dökme yüklerde
     * 5.000'in altındaki "+kdv" / satır sonu "+" tutarları ("dökme üzüm 1000+kdv").
     */
    public static function priceUnitFromText(string $message, float $price): string
    {
        $lower = mb_strtolower(str_replace(['İ', 'I'], ['i', 'ı'], $message));
        if (preg_match('/\b(?:ton\s*(?:başı|basi|başına|basina|fiyat[ıi]?)|tonu|tona|ton\s*ücreti|ton\s*ucreti)\b|(?:tl|₺|lira)?\s*\/\s*ton\b|\btl\s*ton\b/u', $lower)) {
            return 'per_ton';
        }
        if (preg_match('/\+\s*(?:basar|tonajl[ıi])\b/u', $lower)) {
            return 'per_ton';
        }
        if ($price < 5000 && preg_match('/\d\s*(?:tl|₺)?\s*,?\s*(?:\+|artı|arti|art)\s*(?:kdv|$|\s)/mu', $lower) && preg_match(self::BULK_GOODS_PATTERN, $lower)) {
            return 'per_ton';
        }

        return 'total';
    }

    /**
     * Metindeki "X - Y", "X→Y", "X'den Y'ye" kalıplarının tümü, sırayla (temizlenmiş kalkış/varış metinleriyle).
     * Sembol bağlaçlarda boşluk zorunlu değildir ("Beykoz-Şanlıurfa"); ek bağlaçlarda ("dan/den") varıştan önce boşluk gerekir.
     *
     * @return list<array{pickup:?string, delivery:?string}>
     */
    public static function connectorMatches(string $text): array
    {
        $text = TextPrep::prepare($text);
        $text = preg_replace("/[’'‘`]/u", '', $text) ?? $text;
        $text = str_replace(['(', ')'], ' ', $text); // "Samsun (bafra) -> Antalya (kepez)"
        $text = self::unglueProvinceDistrict($text); // "ANKARA-SİNCAN - İZMİR", "İzmir/Kemalpaşa > Bursa": il-ilçe tek yerdir, rota değil
        $word = '(?:\p{L}\.)?\p{L}{2,}(?:\.\p{L}+)*'; // "M.Kemalpaşa" tek sözcük; tek harf ("İ.", "B.") başına eklenmedikçe sayılmaz
        // Ek bağlaç: sözcüğe bitişik ("Ankaradan", en az 3 harften sonra) ya da ayrı yazılmış ("Diyarbakr dan"); "MADEN" gibi sözcük içi "den" sayılmaz.
        // "Konya=Ankara", "Konya => Ankara" (derin inceleme 2026-10-05: "=" tek satırlık ilanda rota bağlacı değildi, ilan eleniyordu)
        $pattern = '/('.$word.'(?:[ \t]+'.$word.'){0,2})(?:[ \t]*(->|=>|=|-|–|—|\/|,)[ \t]*|(?:(?<=\p{L}{3})|[ \t]+)(dan|den|tan|ten)[ \t]+)('.$word.'(?:[ \t]+'.$word.'){0,2})/iu';
        // Eşlemeler satır satır: kalıp satır aşmaz, satırın tamamı ("çıkışlı:" başlığı, fiil, yer sayısı) virgül kararını belirler.
        $all = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (preg_match_all($pattern, $line, $hits, PREG_SET_ORDER)) {
                foreach ($hits as $hit) {
                    $all[] = $hit + ['line' => $line];
                }
            }
        }
        if ($all === []) {
            return [];
        }
        $out = [];
        foreach ($all as $m) {
            $dative = ($m[3] ?? '') !== '';
            $connector = $m[2] ?? '';
            $pickup = self::tidyLocation($m[1]);
            $delivery = self::tidyLocation($m[4], $dative);
            if ($pickup === null || $delivery === null) {
                continue;
            }
            // Virgül yalnız düz bir satırda rota bağlacıdır ("Mersin Tarsus, Kayseri 20 ton"): satırda kalkış/varış fiili, "-dan" ekli yer
            // ya da ikiden çok yer varsa ("İSTANBUL ÇIKIŞLI: Ankara, İzmir, Bursa") virgül liste ayracıdır, rota değil (SeriesAd::commaList bakar).
            if ($connector === ',') {
                $lineLower = TurkishCities::lower($m['line']);
                $hasVerb = preg_match(self::PICKUP_VERBS, $lineLower) === 1 || preg_match(self::DELIVERY_VERBS, $lineLower) === 1
                    || self::hasTrueAblative(preg_replace(self::PHONE_PATTERN, ' ', $lineLower) ?? $lineLower);
                if ($hasVerb || count(self::placesIn($m['line'], 3)) !== 2) {
                    continue;
                }
            }
            // "/" ve "," zayıf bağlaçtır ("ANKARA/SİNCAN" il/ilçe): yalnız iki uç da FARKLI yer olarak çözülüyorsa rota sayılır.
            if (in_array($connector, ['/', ','], true)) {
                $a = TurkishLocations::resolve($pickup, false);
                $b = TurkishLocations::resolve($delivery, false);
                if ($a === null || $b === null || ($a['province_code'] === $b['province_code'] && (($a['district'] ?? null) === null || ($b['district'] ?? null) === null))) {
                    continue; // "Tekkeköy / Samsun", "ANKARA/SİNCAN": ilçe + kendi ili, rota değil
                }
                // "İzmir / KEMALPAŞA": ilçe tek başına başka ilde de varsa iki uç farklı görünür; birlikte okununca il+ilçe ise rota değil
                // (yalnız tek sözcüklü uçlar: "Mersin Tarsus, Kayseri" gerçek rotadır)
                foreach (! str_contains($pickup, ' ') && ! str_contains($delivery, ' ') ? [$pickup.' '.$delivery, $delivery.' '.$pickup] : [] as $joined) {
                    $j = TurkishLocations::resolveCatalog($joined);
                    if ($j !== null && ($j['district'] ?? null) !== null && TurkishCities::fromText($joined, fuzzy: false) !== null) {
                        continue 2;
                    }
                }
            }
            // Rol sözcüğü tarafı belirler: "İzmir teslim - Bursa yükleme", "İzmir teslim, Bursadan yüklenir" → Bursa → İzmir.
            // Hal ekli bağlaçta ("Ostimden Aliağaya palet yükümüz") yön zaten kesindir; "yükümüz" gibi adlar rol sayılmaz.
            if (! $dative) {
                $rawPickup = TurkishCities::lower($m[1]);
                $rawDelivery = TurkishCities::lower($m[4]);
                $pickupSaysDelivery = preg_match(self::ROLE_DELIVERY, $rawPickup) === 1 && preg_match(self::ROLE_PICKUP, $rawPickup) !== 1;
                $deliverySaysPickup = preg_match(self::ROLE_PICKUP, $rawDelivery) === 1 && preg_match(self::ROLE_DELIVERY, $rawDelivery) !== 1;
                if ($pickupSaysDelivery || $deliverySaysPickup) {
                    [$pickup, $delivery] = [$delivery, $pickup];
                }
            }
            $out[] = ['pickup' => $pickup, 'delivery' => $delivery];
        }

        return $out;
    }

    /**
     * Metindeki yer adları (il ya da ilçe; "İstanbul Arnavutköy" gibi il+ilçe bir yer) yazım sırasıyla.
     * Tek başına ilçe adı yalnız yakın eşleme olmadan ve 4+ harfse sayılır (sohbet sözcükleriyle karışmasın).
     *
     * @return list<array{label:string, province_code:int, province:string, district:?string, text:string}>
     */
    public static function placesIn(string $text, int $limit = PHP_INT_MAX): array
    {
        $text = TurkishLocations::stripDayPhrases(TextPrep::prepare($text)); // "PAZAR GÜNÜ" Rize Pazar değildir
        $text = preg_replace("/[’'‘`]/u", '', $text) ?? $text;
        $text = self::unglueProvinceDistrict($text); // "Mersin-Tarsus" tek yer
        $words = array_values(array_filter(preg_split('/[\s,\/;:()+>|]+|(?<=\p{L})-(?=\p{L})/u', $text) ?: [], fn ($w) => $w !== ''));
        $found = []; // sözcük sırası → yer; "strong": il adı yazılmış (il ya da il+ilçe) ya da ilçe + kendi ili; tek başına ilçe/semt zayıftır
        $fuzzyFound = []; // yazım hatalı il adı: yalnız kesin yer ikiden azsa katılır ("Hatası yok Konya Bursa" → Hatay olmaz)
        $skipUntil = -1;
        $cleanWord = fn (string $x): string => preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $x) ?? $x; // "AFYON‼", "*Bursa*", "(Söke)"
        foreach ($words as $i => $word) {
            if ($i <= $skipUntil) {
                continue;
            }
            $w = $cleanWord($word);
            if (mb_strlen($w) < 3 || preg_match('/\d/u', $w) || in_array(TurkishCities::lower($w), self::PLACE_NOISE, true)) {
                continue;
            }
            $next = isset($words[$i + 1]) ? $cleanWord($words[$i + 1]) : null;
            $nextLower = $next !== null ? TurkishCities::lower($next) : null;
            // Firma adı ("Kartal Nakliyat", "Demirci Lojistik") ya da kişi ("Can Bey"): yer değil
            if ($nextLower !== null && (in_array($nextLower, self::COMPANY_WORDS, true) || in_array($nextLower, self::PERSON_TITLES, true))) {
                continue;
            }
            // Önce birebir il, sonra birebir ilçe; yazım hatalı il adı yalnız 6+ harfte, ilçe olarak da çözülmüyorsa ve metinde
            // iki kesin yer yoksa yakın eşlenir ("BALIKKESIR", "ANAKRA"; ama "SİLOPİ" Sinop değil Şırnak Silopi'dir)
            $province = TurkishCities::fromText($w, fuzzy: false);
            $strong = $province !== null;
            if ($province === null && mb_strlen($w) >= 6 && (TurkishLocations::resolve($w, false)['district'] ?? null) === null && ForeignPlaces::match($w) === null
                && ($fuzzyProvince = TurkishCities::fromText($w, fuzzy: true)) !== null) {
                $fr = TurkishLocations::resolve($fuzzyProvince);
                if ($fr !== null) {
                    $fuzzyFound[$i] = ['label' => TurkishLocations::label($fr), 'province_code' => (int) $fr['province_code'], 'province' => $fr['province'], 'district' => null, 'text' => $w, 'strong' => false, 'case' => null];
                }

                continue;
            }
            $resolved = null;
            if ($province !== null) {
                $resolved = TurkishLocations::resolve($province);
                // İl adından sonra ilçe: "İstanbul Arnavutköy", "Ankara Kazan", "Kars Göle"
                if ($next !== null && mb_strlen($next) >= 3 && ! preg_match('/\d/u', $next) && TurkishCities::fromText($next, fuzzy: false) === null) {
                    $withDistrict = TurkishLocations::resolve($province.' '.$next, false);
                    if ($withDistrict !== null && ($withDistrict['district'] ?? null) !== null) {
                        $resolved = $withDistrict;
                        $skipUntil = $i + 1;
                    }
                }
            } elseif (mb_strlen($w) >= 4 || in_array(TurkishCities::ascii($w), self::SHORT_DISTRICTS, true)) {
                // Yük sözcüğüyle aynı ilçe adı ("Kiraz yükü", "Torbalı çimento", "Bor madeni"): tek başına yer değil.
                // Hemen ardından yere bağlanan fiil geliyorsa yerdir: "TORBALI YÜKLER" = Torbalı'dan yükleme (Osman, 2026-10-05),
                // "Kemer iner". Yüke de bağlanan biçimler sayılmaz: "Kiraz yükleme var", "Kiraz yüklenecek", "Kiraz boşaltılır" yüktür;
                // "Torbalı çimento yükler" de yük (araya yük sözcüğü girdi).
                $roleFollows = $nextLower !== null && preg_match(self::PLACE_ROLE_VERBS, $nextLower) === 1;
                if ((in_array(TurkishCities::ascii($w), self::GOODS_LIKE_DISTRICTS, true) && ! $roleFollows) || ($nextLower !== null && in_array($nextLower, self::GOODS_FOLLOWERS, true))) {
                    continue;
                }
                $r = TurkishLocations::resolve($w, false);
                if (($r === null || ($r['district'] ?? null) === null) && $next !== null && mb_strlen($next) >= 3 && ! preg_match('/\d/u', $next)) {
                    // İki sözcüklü ilçe/semt adı ("Çoban Bey", "Mustafa Kemalpaşa", "Sultan Beyli")
                    $two = TurkishLocations::resolve($w.' '.$next, false);
                    // Eşleşen ilçe adı ilk sözcükle başlamalı ("Çoban Bey" → Çobanbey, "Mustafa Kemalpaşa"); "Boşaltma Arsin" gibi
                    // ikinci sözcüğün tek başına ilçe olduğu durumlar ilk sözcüğe bağlanmaz.
                    if ($two !== null && ($two['district'] ?? null) !== null && TurkishCities::fromText($w, fuzzy: false) === null
                        && (str_starts_with(TurkishCities::ascii($two['district']), TurkishCities::ascii($w)) || TurkishLocations::resolve($next, false) === null)) {
                        $r = $two;
                        $skipUntil = $i + 1;
                    }
                }
                if ($r !== null && ($r['district'] ?? null) !== null) {
                    $resolved = $r;
                    // "Tekkeköy / Samsun", "Gölbaşı Ankara": ilçeden sonra kendi ili yazılmışsa il ayrı yer sayılmaz; yer kesinleşir
                    if ($next !== null && TurkishCities::fromText($next, fuzzy: false) === $r['province']) {
                        $skipUntil = max($skipUntil, $i + 1);
                        $strong = true;
                    } elseif ($next !== null && ($nextProvince = TurkishCities::fromText($next, fuzzy: false)) !== null && ($rr = TurkishLocations::resolve($w.' '.$next, false)) !== null && ($rr['district'] ?? null) !== null && $rr['province'] === $nextProvince) {
                        $resolved = $rr; // eş adlı ilçe + ili: "Kemalpaşa İzmir" → İzmir Kemalpaşa
                        $skipUntil = max($skipUntil, $i + 1);
                        $strong = true;
                    }
                } elseif (($f = ForeignPlaces::match($w)) !== null) {
                    // Yurt dışı yer (Erbil, Zaho, Bazargan…): il kodu 0, etiket "Erbil (Irak)"
                    $found[$i] = ['label' => $f['label'], 'province_code' => 0, 'province' => $f['label'], 'district' => null, 'text' => $w, 'strong' => true, 'case' => null];
                    if (count($found) >= $limit) {
                        break;
                    }

                    continue;
                }
            }
            if ($resolved === null) {
                continue;
            }
            $found[$i] = ['label' => TurkishLocations::label($resolved), 'province_code' => (int) $resolved['province_code'], 'province' => $resolved['province'], 'district' => $resolved['district'] ?? null, 'text' => $w,
                'strong' => $strong, 'case' => self::caseOf($skipUntil === $i + 1 ? $next : $w, $resolved)];
            if (count($found) >= $limit) {
                break;
            }
        }
        if (count($found) < 2 && $fuzzyFound !== []) {
            $found += $fuzzyFound;
            ksort($found);
        }

        return array_slice(array_values($found), 0, $limit);
    }

    /**
     * "ANKARA-SİNCAN", "İzmir/Kemalpaşa", "KEMALPAŞA-İZMİR": tire/eğik çizgiyle bitişik il+ilçe (ya da ilçe+il) tek yerdir;
     * bağlaç eşlemesi rota sanmasın diye "Ankara Sincan" biçimine çevrilir. İki il ("Bursa-İstanbul") dokunulmaz.
     */
    public static function unglueProvinceDistrict(string $text): string
    {
        return preg_replace_callback('/(?<!\p{L})(\p{L}{3,})[\-\/](\p{L}{3,})(?!\p{L})/u', function (array $m): string {
            foreach ([[$m[1], $m[2]], [$m[2], $m[1]]] as [$province, $district]) {
                $name = TurkishCities::fromText($province, fuzzy: false);
                if ($name === null || TurkishCities::fromText($district, fuzzy: false) !== null) {
                    continue;
                }
                $r = TurkishLocations::resolveCatalog($province.' '.$district);
                if ($r !== null && ($r['district'] ?? null) !== null && $r['province'] === $name) {
                    return $province.' '.$district;
                }
            }

            return $m[0];
        }, $text) ?? $text;
    }

    /** Sözcükteki hal eki: "Bursadan" → from, "İzmire" / "Aliağaya" → to; ek yoksa ya da sözcük yerin kendisiyse null. */
    private static function caseOf(string $word, array $resolved): ?string
    {
        $a = TurkishCities::ascii($word);
        $base = TurkishCities::ascii((string) ($resolved['district'] ?? $resolved['province'] ?? ''));
        if ($base === '' || $a === $base || preg_match('/[^a-z]/', $a) === 1 || ! str_starts_with($a, substr($base, 0, max(3, strlen($base) - 1)))) {
            return null;
        }
        if (preg_match('/(dan|den|tan|ten)$/', $a) === 1) {
            return 'from';
        }
        if (preg_match('/(da|de|ta|te)$/', $a) === 1) {
            return null; // bulunma eki ("Bursada yükleme var"): yön söylemez
        }
        if (preg_match('/(ya|ye|a|e)$/', $a) === 1) {
            return 'to';
        }

        return null;
    }

    /** Bağlaçlı rotada tarafın rolünü söyleyen sözcükler (yalnız fiil/ad biçimleri; "yükümüz" gibi adlar sayılmaz). */
    public const ROLE_PICKUP = '/(?<!\p{L})(?:yükleme|yukleme|yüklemeli|yuklemeli|yükler|yukler|yüklenir|yuklenir|yüklenecek|yuklenecek|çıkış|cikis|çıkışlı|cikisli|kalkış|kalkis)(?!\p{L})/u';

    public const ROLE_DELIVERY = '/(?<!\p{L})(?:teslim|teslimat|boşaltma|bosaltma|boşaltır|bosaltir|boşaltılır|bosaltilir|iner|inecek|indirme|indirmeli|varış|varis)(?!\p{L})/u';

    /** Yer adından sonra geldiğinde o adın firma adı olduğunu gösteren sözcükler ("Kartal Nakliyat", "Demirci Lojistik"). */
    public const COMPANY_WORDS = ['nakliyat', 'nakliyat.', 'nak', 'nak.', 'lojistik', 'lojistik.', 'loj', 'loj.', 'logistics', 'ltd', 'ltd.', 'şti', 'sti', 'şti.', 'tic', 'tic.', 'san', 'san.',
        'a.ş', 'a.ş.', 'uluslararası', 'uluslararasi', 'taşımacılık', 'tasimacilik', 'trans', 'transport', 'group', 'grup', 'holding', 'petrol', 'çimento', 'cimento', 'tekstil', 'gıda', 'gida'];

    /** Yer adından sonra geldiğinde o adın kişi adı olduğunu gösteren sözcükler ("Can Bey", "Sinan Abi"). */
    public const PERSON_TITLES = ['bey', 'abi', 'ağabey', 'hanım', 'hanim', 'usta', 'hoca', 'kardeş', 'kardes', 'amca', 'dayı', 'dayi'];

    /** Yalnız bir yere bağlanan rol fiilleri ("Torbalı yükler", "Gebze çıkışlı", "Kemer iner"); yüke de bağlananlar (yükleme, yüklenecek, boşaltma) yok. */
    public const PLACE_ROLE_VERBS = '/^(?:yükler|yukler|yüklemeli|yuklemeli|çıkış|cikis|çıkışlı|cikisli|kalkış|kalkis|kalkışlı|kalkisli|iner|inecek|indirmeli|boşaltır|bosaltir|varış|varis)$/u';

    /** Yük sözcüğüyle aynı ilçe adları ("Kiraz yükü", "Torbalı çimento", "Maden yükü", "Mısır silajı"): tek başına yer sayılmaz. */
    private const GOODS_LIKE_DISTRICTS = ['kiraz', 'kavak', 'maden', 'madeni', 'torbali', 'misir', 'hamur', 'bahce', 'ciftlik', 'kemer', 'kumru', 'yumurtalik', 'findik', 'cay', 'kozan', 'tut'];

    /** Yer adından sonra geldiğinde önceki sözcüğün yük olduğunu gösteren sözcükler ("Bor madeni", "Kavak tomruk"). */
    private const GOODS_FOLLOWERS = ['yükü', 'yuku', 'yükleri', 'yukleri', 'madeni', 'tomruk', 'tomruğu', 'çimento', 'cimento', 'silajı', 'silaji', 'gübresi', 'gubresi', 'mobilyası', 'mobilyasi', 'tozu', 'unu', 'suyu', 'yağı', 'yagi'];

    /** Üç harfli ama ilanlarda sık geçen gerçek ilçe adları (tek başına yer sayılır). */
    public const SHORT_DISTRICTS = ['can', 'bor', 'mut', 'kas', 'ula', 'cat', 'has', 'kale'];

    /** İlçe adıyla çakışan ama ilanlarda başka anlamda geçen sözcükler: tek başına yer sayılmaz. */
    public const PLACE_NOISE = ['karsi', 'karşı', 'musa', 'vana', 'panel', 'arac', 'araç', 'araclar', 'araçlar', 'sur', 'tut', 'ulas', 'ulaş', 'ova', 'merkez', 'yeni', 'dere', 'iner', 'kaya', 'bey', 'tas', 'taş', 'demir', 'gol', 'göl', 'ada', 'kum', 'sar', 'sal', 'salı', 'sali', 'cide', 'hani', 'nazar', 'yol', 'yolu', 'tir', 'tır', 'ton', 'usd', 'hemen', 'bugun', 'bugün', 'yarin', 'yarın', 'firma', 'nokta', 'depo', 'liman', 'sanayi', 'termik', 'santral', 'dosya', 'ekli', 'kira', 'bir',
        // gün adları ("Perşembe yükleme"; "Samsun Çarşamba" / "Rize Pazar" il ile yazılınca çözülür), ay ("15 Aralık"), gündelik sözcükler ve yönler
        'pazartesi', 'çarşamba', 'carsamba', 'perşembe', 'persembe', 'cuma', 'cumartesi', 'pazar', 'aralık', 'aralik', 'olur', 'orta', 'güney', 'guney', 'kuzey', 'doğu', 'dogu', 'batı', 'bati',
        'akdeniz', 'marmara', 'ege', 'karadeniz', 'termal', 'selim', 'evren', 'ulus', 'susuz', 'korkut', 'küre', 'kure', 'köşk', 'kosk', 'hal', 'hali', 'yeşil', 'yesil', 'güzel', 'guzel'];

    /**
     * Satır rollerinden rota: "X yükler / yüklemeli / çıkış / Xden" satırı kalkış, "Y iner / indirmeli / boşaltır / teslim /
     * varış" satırı varış. Yalnız kalkış bulunduysa sonraki satırdaki ilk yer varış, yalnız varış bulunduysa önceki satırdaki
     * ilk yer kalkıştır.
     *
     * @return array{0:string,1:string}|null [kalkış etiketi, varış etiketi]
     */
    public static function routeFromLines(string $text): ?array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", TextPrep::prepare($text))), fn ($l) => $l !== ''));
        $pickup = null;
        $pickupAt = null;
        $delivery = null;
        $deliveryAt = null;
        foreach ($lines as $i => $line) {
            $places = self::placesIn($line, 2);
            if ($places === []) {
                continue;
            }
            // Büyük harfli Türkçe ("YÜKLEMELİ", "İNER") /i ile eşleşmez (İ ↔ i katlanmaz): küçük harfe indirilmiş satırda aranır
            $lower = TurkishCities::lower($line);
            $isPickup = preg_match(self::PICKUP_VERBS, $lower) === 1 || (preg_match('/^\p{L}+(?:dan|den|tan|ten)\b/iu', $lower) === 1 && self::hasTrueAblative($lower));
            $isDelivery = preg_match(self::DELIVERY_VERBS, $lower) === 1;
            if ($isPickup && $isDelivery && count($places) >= 2 && $pickup === null && $delivery === null) {
                // "BOLU YÜKLER ANTALYA BOŞALTIR": her fiilden önceki en yakın yer adı o role aittir
                preg_match(self::PICKUP_VERBS, $lower, $pm, PREG_OFFSET_CAPTURE);
                preg_match(self::DELIVERY_VERBS, $lower, $dm, PREG_OFFSET_CAPTURE);
                $before = function (int $offset) use ($places, $lower): ?string {
                    $best = null;
                    foreach ($places as $pl) {
                        $pos = mb_strpos($lower, TurkishCities::lower($pl['text']));
                        if ($pos !== false && strlen(mb_substr($lower, 0, $pos)) <= $offset) {
                            $best = $pl['label'];
                        }
                    }

                    return $best;
                };
                // "Teslim: İzmir Yükleme: Bursa", "Yükleme Bursa teslim İzmir": fiil satır başında ya da ":" ile yazılmışsa yer ondan SONRA gelir
                $after = function (int $offset, int $length) use ($places, $lower): ?string {
                    foreach ($places as $pl) {
                        $pos = mb_strpos($lower, TurkishCities::lower($pl['text']));
                        if ($pos !== false && strlen(mb_substr($lower, 0, $pos)) >= $offset + $length) {
                            return $pl['label'];
                        }
                    }

                    return null;
                };
                $leads = fn (array $match): bool => ($match[0][1] ?? 0) === 0 || preg_match('/^\s*:/u', substr($lower, (int) $match[0][1] + strlen($match[0][0]))) === 1;
                $afterMode = $leads($pm) || $leads($dm); // "Teslim İzmir yükleme Bursa": satır fiille başlıyorsa yerler fiillerden sonra gelir
                $pk = ($afterMode ? $after((int) $pm[0][1], strlen($pm[0][0])) : null) ?? $before((int) ($pm[0][1] ?? 0));
                $dl = ($afterMode ? $after((int) $dm[0][1], strlen($dm[0][0])) : null) ?? $before((int) ($dm[0][1] ?? 0));
                if ($pk !== null && $dl !== null && $pk !== $dl) {
                    return [$pk, $dl];
                }
            }
            if ($isPickup && ! $isDelivery && $pickup === null) {
                $pickup = $places[0]['label'];
                $pickupAt = $i;
                // "Çorlu yükler- Muğla Menteşe": ikinci yer varış; "GEBZE+TUZLA YÜKLER": ikinci yer alternatif kalkış, varış değil
                if (isset($places[1]) && $delivery === null && ! self::samePlace($places[0], $places[1]) && count(SeriesAd::alternativePickups($line, $places)) < 2) {
                    $delivery = $places[1]['label'];
                    $deliveryAt = $i;
                }
            } elseif ($isDelivery && $delivery === null) {
                $delivery = $places[0]['label'];
                $deliveryAt = $i;
            }
        }
        if ($pickup === null && $delivery === null) {
            return null;
        }
        if ($pickup !== null && $delivery === null) {
            foreach ($lines as $i => $line) {
                if ($i <= $pickupAt) {
                    continue;
                }
                foreach (self::placesIn($line, 3) as $pl) {
                    if ($pl['label'] !== $pickup) {
                        return [$pickup, $pl['label']];
                    }
                }
            }

            return null;
        }
        if ($pickup === null && $delivery !== null) {
            for ($i = $deliveryAt - 1; $i >= 0; $i--) {
                foreach (self::placesIn($lines[$i], 3) as $pl) {
                    if ($pl['label'] !== $delivery) {
                        return [$pl['label'], $delivery];
                    }
                }
            }

            return null;
        }

        return [$pickup, $delivery]; // aynı yer ("ankara lojistik üssü yükler / … iner"): şehir içi taşıma
    }

    public const PICKUP_VERBS = '/(?<!\p{L})(?:yükler|yukler|yüklemeli|yuklemeli|yükleme|yukleme|yüklemeler|yuklemeler|yüklemeleri|yuklemeleri|yüklemelerimiz|yuklemelerimiz|yüklemesi|yuklemesi|yüklenir|yuklenir|yüklenecek|yuklenecek|yükleniyor|yukleniyor|yüklenir|yüklemem|yuklemem|yükümüz|yukumuz|çıkış|cikis|çıkışlı|cikisli|kalkış|kalkis|yükleme noktası)(?!\p{L})/iu';

    public const DELIVERY_VERBS = '/(?<!\p{L})(?:iner|inecek|indirmeli|indirme|indirir|indirilecek|indirilir|indirecek|iniyor|boşaltır|bosaltir|boşaltılır|bosaltilir|boşaltılacak|bosaltilacak|boşaltacak|bosaltacak|boşaltılıyor|bosaltiliyor|boşaltma|bosaltma|tahliye|teslim|varış|varis|tampon bölge|teslimat)(?!\p{L})/iu';

    /** "-dan/-den/-tan/-ten" ile biten ama ayrılma eki olmayan sözcükler: sektör ve gündelik sözcükler; il/ilçe adının kendisi de (Elbistan, Buldan) katalogdan denetlenir. */
    public const FALSE_ABLATIVE = ['tenten', 'toptan', 'kaptan', 'bostan', 'vatan', 'destan', 'zaten', 'meydan', 'fidan', 'kurban', 'sultan', 'siten', 'posten', 'listen', 'kesten', 'yastan', 'nisan', 'dukkan', 'orman', 'liman', 'yan', 'perakenden', 'hemen', 'nereden', 'beden', 'neden', 'giden', 'eden', 'gelen', 'alan', 'olan', 'kalan', 'bulunan'];

    /**
     * Satırdaki "-dan/-den/-tan/-ten" ile biten sözcük gerçekten kalkış eki mi? "GEBZE YÜKLER / ANKARA TENTEN" satırında "tenten" kasa
     * sözcüğüdür, "K.MARAŞ ELBİSTAN" ilçe adıdır; eski sürüm ikisini de kalkış başlığı sayıp seri ilanı bozuyordu.
     */
    public static function hasTrueAblative(string $lower): bool
    {
        if (! preg_match_all('/(?<!\p{L})(\p{L}{3,}(?:dan|den|tan|ten))(?!\p{L})/u', $lower, $m)) {
            return false;
        }
        foreach ($m[1] as $word) {
            $ascii = TurkishCities::ascii($word);
            if (in_array($ascii, self::FALSE_ABLATIVE, true) || TurkishLocations::isCatalogName($word)) {
                continue;
            }

            return true;
        }

        return false;
    }

    private static function samePlace(array $a, array $b): bool
    {
        return $a['label'] === $b['label'];
    }

    /**
     * Metindeki ilk çözülebilen rota: [kalkış, varış] ("İl İlçe" etiketleri). Bağlaç kalıbı yoksa satır rolleri,
     * o da yoksa metindeki ilk iki farklı yer alınır. Bulunamazsa null.
     *
     * @return array{0:string,1:string}|null
     */
    /** Yalnız bağlaçlı rota ("X - Y", "Xden Yye"); satır sırası ya da yer listesi kullanılmaz. */
    public static function connectorPair(string $text): ?array
    {
        $prov = self::placeResolver();
        $label = fn (string $v) => str_replace('|', ' ', $v);
        foreach (self::connectorMatches($text) as $m) {
            $a = $prov($m['pickup']);
            $b = $prov($m['delivery']);
            if ($a !== null && $b !== null && $a !== $b) {
                return [$label($a), $label($b)];
            }
        }

        return null;
    }

    /** Yer metnini "İl|İlçe" ya da yurt dışı etiketine çözen kapanış. */
    private static function placeResolver(): \Closure
    {
        return function (?string $v): ?string {
            if ($v === null) {
                return null;
            }
            $r = TurkishLocations::resolve($v, false);
            if ($r === null && mb_strlen($v) >= 6 && ! str_contains(trim($v), ' ') && ($p = TurkishCities::fromText($v, fuzzy: true)) !== null) {
                $r = TurkishLocations::resolve($p, false);
            }
            // Bağlaçlı rotanın tek sözcüklü ucu yazım hatalı ilçe olabilir ("Malkardan Adana" → Tekirdağ Malkara); araç/yük sözcükleri hariç
            if ($r === null && mb_strlen($v) >= 6 && ! str_contains(trim($v), ' ') && VehicleClassifier::analyze($v)['type'] === null) {
                $r = TurkishLocations::fuzzyDistrict($v);
            }
            if ($r !== null) {
                return $r['province'].(($r['district'] ?? null) ? '|'.$r['district'] : '');
            }

            return ForeignPlaces::match($v)['label'] ?? null;
        };
    }

    public static function routePair(string $text): ?array
    {
        $prov = function (?string $v): ?string {
            if ($v === null) {
                return null;
            }
            $r = TurkishLocations::resolve($v, false);
            if ($r === null && mb_strlen($v) >= 6 && ! str_contains(trim($v), ' ') && ($p = TurkishCities::fromText($v, fuzzy: true)) !== null) {
                $r = TurkishLocations::resolve($p, false);
            }
            if ($r === null && mb_strlen($v) >= 6 && ! str_contains(trim($v), ' ') && VehicleClassifier::analyze($v)['type'] === null) {
                $r = TurkishLocations::fuzzyDistrict($v); // "Malkardan Adana" → Tekirdağ Malkara (tek harf hatası)
            }
            if ($r !== null) {
                return $r['province'].(($r['district'] ?? null) ? '|'.$r['district'] : '');
            }

            return ForeignPlaces::match($v)['label'] ?? null;
        };
        $label = fn (string $v) => str_replace('|', ' ', $v);
        foreach (self::connectorMatches($text) as $m) {
            $a = $prov($m['pickup']);
            $b = $prov($m['delivery']);
            if ($a !== null && $b !== null && $a !== $b) {
                return [$label($a), $label($b)];
            }
        }
        if (($lines = self::routeFromLines($text)) !== null) {
            return [$label((string) ($prov($lines[0]) ?? $lines[0])), $label((string) ($prov($lines[1]) ?? $lines[1]))];
        }
        $places = self::placesIn($text, 3);
        foreach ($places as $i => $p) {
            foreach (array_slice($places, $i + 1) as $q) {
                if ($q['label'] !== $p['label']) {
                    return [$p['label'], $q['label']];
                }
            }
        }

        return null;
    }

    /** Yer adından "acil", "yük", "var" gibi dolgu sözcüklerini atar; il adını ekinden arındırır. */
    private static function tidyLocation(?string $value, bool $stripDative = false): ?string
    {
        $value = self::cleanText($value, 120);
        if ($value === null) {
            return null;
        }
        $stop = ['acil', 'yük', 'yuk', 'var', 'lazım', 'lazim', 'palet', 'ton', 'tır', 'tir', 'kamyon', 'kamyonet', 'tenteli', 'komple', 'parsiyel',
            'araç', 'arac', 'araclar', 'araçlar', 'arayan', 'arayanlar', 'için', 'icin', 'ile', 've', 'mal', 'ürün', 'urun', 'çıkış', 'cikis', 'varış', 'varis',
            'yükleme', 'yukleme', 'boşaltma', 'bosaltma', 'hazır', 'hazir', 'gidecek', 'gelecek', 'olan', 'yarın', 'yarin', 'bugün', 'bugun',
            'sabah', 'akşam', 'aksam', 'yükü', 'yuku', 'nakliye', 'dorse', 'frigo', 'kasa',
            'yükler', 'yukler', 'yüklemeli', 'yuklemeli', 'yüklemeler', 'yuklemeler', 'yüklenir', 'yuklenir', 'yüklemem', 'yuklemem', 'yükümüz', 'yukumuz',
            'iner', 'inecek', 'indirmeli', 'indirme', 'boşaltır', 'bosaltir', 'teslim', 'teslimat', 'tampon', 'bölge', 'bolge',
            'yüklenecek', 'yuklenecek', 'yükleniyor', 'yukleniyor', 'boşaltılacak', 'bosaltilacak', 'boşaltılır', 'bosaltilir', 'boşaltacak', 'bosaltacak',
            'boşaltılıyor', 'bosaltiliyor', 'indirilecek', 'indirilir', 'indirecek', 'iniyor', 'tahliye',
            'kapalı', 'kapali', 'açık', 'acik', 'tente', 'tenten', 'firgo', 'firigo', 'damper', 'damperli', 'damperlı', 'dökme', 'dokme',
            'hemen', 'bugünkü', 'yarınki', 'pazartesi', 'salı', 'sali', 'çarşamba', 'carsamba', 'perşembe', 'persembe', 'cuma', 'cumartesi', 'pazar',
            'günü', 'gunu', 'saat', 'kadar', 'km', 'usd', 'tl', 'kdv', 'peşin', 'pesin', 'nokta', 'yer', 'civarı', 'civari', 'depo', 'depodan', 'depoma',
            'osb', 'sanayi', 'termik', 'santral', 'liman', 'limanı', 'limani', 'fabrika', 'merkez', 'basar', 'tonaj', 'tonajlı', 'tonajli', 'uzun', 'kısa', 'kisa',
            'adet', 'parça', 'parca', 'koli', 'hafif', 'ağır', 'agir', 'yüksek', 'yuksek', 'yan', 'tekstil', 'dorseli', 'tırlar', 'tirlar', 'boş', 'bos',
            'yerde', 'yerinde', 'yerin', 'ödeme', 'odeme', 'sevkiyat', 'sevkiyatları', 'sevkiyatlari', 'sevkiyatlarımız', 'fatura', 'faturalı', 'faturali', 'kira', 'dolgun', 'günlük', 'gunluk', 'kotalı', 'kotali',
            'kalkış', 'kalkis', 'üzeri', 'uzeri', 'üzerinden', 'uzerinden', 'gidiş', 'gidis', 'dönüş', 'donus', 'çıkışlı', 'cikisli'];
        $value = str_replace(['(', ')', '+', '/'], ' ', $value);
        $words = array_values(array_filter(preg_split('/\s+/u', $value) ?: [], fn ($w) => $w !== ''));
        $isStop = fn (string $w) => in_array(TurkishCities::lower($w), $stop, true) || preg_match('/^\d/u', $w) === 1;
        while ($words !== [] && $isStop($words[0])) {
            array_shift($words);
        }
        // "Samsun Çarşamba", "Rize Pazar": gün adıyla aynı olan ilçe, önündeki ille birlikte çözülüyorsa atılmaz.
        $dayDistrict = fn (array $ws): bool => count($ws) >= 2 && in_array(TurkishCities::lower($ws[array_key_last($ws)]), ['çarşamba', 'carsamba', 'perşembe', 'persembe', 'pazar'], true)
            && (TurkishLocations::resolve($ws[array_key_last($ws) - 1].' '.$ws[array_key_last($ws)], false)['district'] ?? null) !== null;
        while ($words !== [] && $isStop($words[array_key_last($words)]) && ! $dayDistrict($words)) {
            array_pop($words);
        }
        if ($words === []) {
            return null;
        }
        // "İzmire Aliağaya" → son sözcükteki yönelme eki (-a/-e/-ya/-ye) atılır; il adları ayrıca normalize edilir.
        $last = array_key_last($words);
        if ($stripDative && TurkishCities::fromText($words[$last]) === null) {
            $w = $words[$last];
            $stripped = null;
            if (preg_match('/(ya|ye)$/iu', $w) && mb_strlen($w) > 5) {
                $stripped = mb_substr($w, 0, -2);
            } elseif (preg_match('/[^aeıioöuüAEIİOÖUÜ](a|e)$/iu', $w) && mb_strlen($w) > 4) {
                $stripped = mb_substr($w, 0, -1);
            }
            // Ek atılmış hali çözülüyorsa o ("Aliağaya" → Aliağa); asıl sözcük zaten bir yerse dokunulmaz ("Cizre" → "Cizr" olmaz).
            if ($stripped !== null && (TurkishLocations::resolve($stripped, false) !== null || TurkishLocations::resolve($w, false) === null)) {
                $words[$last] = $stripped;
            }
        }

        return TurkishCities::normalizeLocation(implode(' ', $words));
    }

    private function normalizePhone(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $digits = Phone::normalizeContact((string) $value);

        return $digits !== null && self::isAdPhone($digits) ? $digits : null;
    }

    private static function cleanText(mixed $value, int $limit): ?string
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

    private function recordUsage(string $provider, bool $success, bool $quota, array $tokens = [], ?int $cooldownSeconds = null): void
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
                $usage->update(['quota_exhausted' => true, 'quota_resets_at' => now()->addSeconds($cooldownSeconds ?? 300)]);
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

    /**
     * Modelin JSON'unu hoşgörüyle çözer: kod çiti, önündeki/arkasındaki açıklama metni, sondaki virgül ve
     * kesilmiş yanıt (kapanmamış ayraçlar) onarılır. Çözülemezse null.
     */
    public static function decodeLenient(string $text): ?array
    {
        $text = trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($text)) ?? $text);
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }
        $text = substr($text, $start);
        $end = strrpos($text, '}');
        $candidate = $end !== false ? substr($text, 0, $end + 1) : $text;
        $decoded = json_decode($candidate, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        // Sondaki virgül ve kapanmamış dizi/nesne: kesik yanıtı kapatarak dene.
        $fixed = preg_replace('/,\s*([}\]])/u', '$1', $candidate) ?? $candidate;
        $decoded = json_decode($fixed, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $fixed = preg_replace('/,\s*"[^"]*$/u', '', $text) ?? $text; // yarım kalan alan
        $fixed = rtrim($fixed, ", \n\t");
        $stack = [];
        $inString = false;
        for ($i = 0, $n = strlen($fixed); $i < $n; $i++) {
            $ch = $fixed[$i];
            if ($ch === '"' && ($i === 0 || $fixed[$i - 1] !== '\\')) {
                $inString = ! $inString;
            } elseif (! $inString && ($ch === '{' || $ch === '[')) {
                $stack[] = $ch === '{' ? '}' : ']';
            } elseif (! $inString && ($ch === '}' || $ch === ']')) {
                array_pop($stack);
            }
        }
        if ($inString) {
            $fixed .= '"';
        }
        $fixed .= implode('', array_reverse($stack));
        $fixed = preg_replace('/,\s*([}\]])/u', '$1', $fixed) ?? $fixed;
        $decoded = json_decode($fixed, true);

        return is_array($decoded) ? $decoded : null;
    }
}
