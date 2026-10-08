<?php

namespace Tests\Feature\Intake;

use App\Jobs\ProcessNotificationMessage;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Services\AiParserService;
use App\Services\LoadIntakeService;
use App\Services\ScrapedLoadService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 2026-10-04 denetimi, 3. paket: yapay zeka kullanımı ve gecikme. "Her ilanda" kipi telefonu olan her mesajı yapay zekaya
 * yolluyor, yapay zeka ulaşılamazken kuralın yarım çözdüğü parça kayboluyor, ölü sağlayıcı her mesajda 45 sn tutuyor,
 * tam JSON şeması her çağrıda gidiyor, aynı metin tekrar tekrar soruluyordu. Uydurma numaralar.
 */
class AiUsageTest extends TestCase
{
    use RefreshDatabase;

    private const AD = ['post_type' => 'load', 'confidence' => 0.9, 'phones' => ['5321112233'], 'excerpt' => null, 'pickup' => ['province' => 'Ankara', 'district' => null], 'delivery' => ['province' => 'İzmir', 'district' => null], 'goods' => 'palet', 'goods_category' => null, 'vehicle_type' => 'tir', 'vehicle_flexible' => false, 'weight_kg' => 24000, 'price_try' => null, 'urgent' => false, 'pickup_date_text' => null, 'notes' => null];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        Settings::set('ai_parse_mode', 'always');
        Settings::set('ai_groq_key', 'gsk-test');
        Settings::set('ai_groq_model', 'llama-test');
        Settings::set('ai_provider', 'groq');
        Scraper::create(['name' => 'Grup A', 'type' => 'notification', 'source_identifier' => 'notif:grup-a', 'is_active' => true]);
    }

    private function okResponse(array $ad = self::AD): array
    {
        return ['choices' => [['message' => ['content' => json_encode(['post_type' => 'load', 'confidence' => 0.9, 'notes' => null, 'ads' => [$ad]])]]], 'usage' => ['prompt_tokens' => 400, 'completion_tokens' => 90]];
    }

    private function intake(string $text, string $id): array
    {
        return app(LoadIntakeService::class)->intake(['group_name' => 'Grup A', 'raw_message' => $text, 'message_id' => $id, 'source_jid' => 'notif:grup-a']);
    }

    public function test_always_mode_skips_ai_for_strong_rule_parses_and_for_chatter(): void
    {
        Http::fake(['api.groq.com/*' => Http::response($this->okResponse())]);

        // Kesin kural: bağlaçlı rota, iki il katalogda, açık araç adı → yapay zeka çağrılmaz.
        $r = $this->intake('Ankara - İzmir 24 ton palet tır lazım 0532 111 22 33', 's1');
        $this->assertSame('created', $r['status']);
        Http::assertNothingSent();
        $this->assertSame(['skipped', 'tir', 'keyword'], [ScrapedLoad::first()->ai_status, ScrapedLoad::first()->vehicle_type, ScrapedLoad::first()->vehicle_type_source]);

        // Sohbet (telefonlu ama lojistik işareti yok): "Her ilanda" kipinde bile yapay zekaya gitmez.
        $r = $this->intake('Akşam toplantıya gelen var mı 0532 111 22 44', 's2');
        $this->assertSame(['filtered', 'no_logistics_signal'], [$r['status'], $r['reason']]);
        Http::assertNothingSent();

        // Zayıf kural (araç yazmıyor): yapay zekaya gider.
        $r = $this->intake('Bursa - Konya 12 ton palet 0532 111 22 55', 's3');
        $this->assertSame('created', $r['status']);
        Http::assertSentCount(1);
        $this->assertTrue(LoadIntakeService::ruleStrong(app(AiParserService::class)->parseCheap('Ankara - İzmir tenteli tır 0532 111 22 33'), 'Ankara - İzmir tenteli tır 0532 111 22 33'));
        $this->assertFalse(LoadIntakeService::ruleStrong(app(AiParserService::class)->parseCheap("Varış: İzmir\nÇıkış: Ankara\ntır 0532 111 22 33"), "Varış: İzmir\nÇıkış: Ankara\ntır 0532 111 22 33"), 'etiketli yazımda yön kesin değil');
    }

    public function test_half_resolved_route_is_kept_as_partial_row_when_ai_is_unavailable(): void
    {
        // İlk çağrı 503 (sağlayıcı yanıt vermiyor), sonraki çağrı tam çözüm.
        Http::fakeSequence('api.groq.com/*')
            ->push(['error' => ['message' => 'upstream']], 503)
            ->push($this->okResponse(array_merge(self::AD, ['pickup' => ['province' => 'Ankara', 'district' => 'Ostim'], 'delivery' => ['province' => 'İzmir', 'district' => 'Aliağa']])));

        // Kural yalnız kalkışı çözer ("Bilinmeyenköy" katalogda yok); yapay zeka cevap vermedi → eksik kayıt açılır, elenmez.
        $r = $this->intake('Ankara Ostimden Bilinmeyenköye 24 ton palet 0532 111 22 33', 'p1');
        $this->assertSame('created', $r['status']);
        $load = ScrapedLoad::first();
        $this->assertSame(['parsed_partial', 'pending', 6, null], [$load->status, $load->ai_status, (int) $load->pickup_province_code, $load->delivery_province_code]);
        $this->assertSame('5321112233', $load->plainPhone());
        $this->assertSame('rota eksik', app(ScrapedLoadService::class)->autoApprovalBlocker($load));

        // Kuyruk komutu yapay zeka gelince tamamlar.
        Settings::set('ai_parse_mode', 'fill_gaps');
        $this->assertSame(1, app(ScrapedLoadService::class)->aiEnrichPending());
        $load->refresh();
        $this->assertSame(['parsed_success', 'done', 35], [$load->status, $load->ai_status, (int) $load->delivery_province_code]);
    }

    public function test_circuit_breaker_wall_budget_and_result_cache(): void
    {
        $parser = app(AiParserService::class);
        Settings::set('ai_gemini_key', 'AIza-test');
        Settings::set('ai_gemini_model', 'gemini-test');
        Http::fake([
            'api.groq.com/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(['post_type' => 'load', 'confidence' => 0.9, 'notes' => null, 'ads' => [self::AD]])]]]]]]),
        ]);

        // Üç ardışık zaman aşımı: Groq 10 dakika devre dışı; sonraki çağrı doğrudan Gemini'ye gider.
        // (Sahte istemci fırlatılan bağlantı hatasını kayda almaz; sayılanlar Gemini çağrılarıdır.)
        foreach (['a', 'b', 'c'] as $i) {
            $r = $parser->enrich("Ankara İzmir {$i} palet 0532 111 22 33");
            $this->assertSame('gemini', $r['data']['provider']);
        }
        Http::assertSentCount(3);
        $this->assertNotNull($parser->coolingDownUntil('groq'));
        $this->assertSame('cooldown', $parser->providerStatus()['groq']['state']);
        $this->assertSame('ok', $parser->providerStatus()['gemini']['state']);
        $parser->enrich('Ankara İzmir d palet 0532 111 22 33');
        Http::assertSentCount(4, 'Groq devre dışı: yalnız Gemini çağrıldı');
        $this->travel(11)->minutes();
        $this->assertNull($parser->coolingDownUntil('groq'));

        // Sonuç önbelleği: aynı metin (noktalama/emoji farkı dahil) yapay zekaya bir daha sorulmaz.
        $again = $parser->enrich('Ankara İzmir d palet 0532 111 22 33 🚛');
        $this->assertTrue($again['cached'] ?? false);
        Http::assertSentCount(4);
        $this->assertSame(AiParserService::resultCacheKey('Ankara İzmir d palet 0532 111 22 33'), AiParserService::resultCacheKey('ANKARA, İZMİR d palet 0532 111 22 33!'));

        // Şema istemi kısa metin (tam JSON şeması değil).
        Http::assertSent(fn ($req) => str_contains($req->url(), 'generativelanguage.googleapis.com') && ! str_contains(json_encode($req->data(), JSON_UNESCAPED_UNICODE), 'additionalProperties') && str_contains(json_encode($req->data(), JSON_UNESCAPED_UNICODE), 'load|vehicle_available|other'));
        $this->assertStringNotContainsString('additionalProperties', AiParserService::compactSchema());
        $this->assertLessThan(1500, mb_strlen(AiParserService::compactSchema()));
        $this->assertGreaterThan(1500, mb_strlen(json_encode(AiParserService::outputSchema(), JSON_UNESCAPED_UNICODE)));
    }

    public function test_per_message_budget_tries_at_most_two_providers_and_marks_pending(): void
    {
        Settings::set('ai_gemini_key', 'AIza-test');
        Settings::set('ai_gemini_model', 'gemini-test');
        Settings::set('ai_cerebras_key', 'csk-test');
        Settings::set('ai_cerebras_model', 'llama-3.3-70b');
        $parser = app(AiParserService::class);
        // Gizli sağlayıcı (Cerebras) anahtarı kalmış olsa da zincire girmez; yalnız AI_EXTRA_PROVIDERS ile açılır
        $this->assertSame(['groq', 'gemini'], $parser->chain());
        config(['services.ai.extra_providers' => ['cerebras']]);
        $this->assertSame(['groq', 'gemini', 'cerebras'], $parser->chain());
        Http::fake([
            'api.groq.com/*' => Http::response(['error' => ['message' => 'bad gateway']], 502),
            'generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'unavailable']], 503),
            'api.cerebras.ai/*' => Http::response($this->okResponse()),
        ]);

        $r = $parser->enrich('Ankara İzmir palet 0532 111 22 33');
        $this->assertSame('pending', $r['status']);
        Http::assertSentCount(2, 'üçüncü sağlayıcı bu mesajda denenmez; kuyruk tamamlar');

        // Yönetici düğmesi (manual) bütçeyle sınırlı değil: üçüncü sağlayıcıya ulaşır.
        $r = $parser->enrich('Ankara İzmir palet 0532 111 22 33', [], true);
        $this->assertSame(['done', 'cerebras'], [$r['status'], $r['data']['provider']]);
    }

    public function test_failed_job_releases_the_seen_key_and_retries_once(): void
    {
        $job = new ProcessNotificationMessage('Grup A', 'whatsapp', ['text' => 'Ankara İzmir 0532 111 22 33', 'phone' => null], 'Grup A', '10.0.0.1');
        $this->assertSame([2, 30, 120], [$job->tries, $job->backoff, $job->timeout]);
        $key = 'intake:seen:'.hash('sha256', LoadIntakeService::normalizeText('Ankara İzmir 0532 111 22 33'));
        Cache::put($key, 1, 600);
        $job->failed(new \RuntimeException('zaman aşımı'));
        $this->assertNull(Cache::get($key));
    }
}
