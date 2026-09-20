<?php

namespace Tests\Feature\Services;

use App\Http\Middleware\FirewallMiddleware;
use App\Models\AiProviderUsage;
use App\Models\IntakeEvent;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Models\User;
use App\Services\AiParserService;
use App\Services\ScrapedLoadService;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ExternalLoadsModuleTest extends TestCase
{
    use RefreshDatabase;

    private const AD = "Ahmet Usta: Ankara'dan İzmir'e 24 ton palet tenteli 0532 123 45 67";

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        config()->set('services.scraper.token', '');
        $this->withoutMiddleware(FirewallMiddleware::class);
    }

    private function source(bool $active = true): Scraper
    {
        return Scraper::create(['name' => 'Grup A', 'type' => 'notification', 'source_identifier' => 'notif:grup-a', 'is_active' => $active]);
    }

    private function candidate(Scraper $source, array $overrides = []): ScrapedLoad
    {
        return ScrapedLoad::create(array_merge([
            'scraper_id' => $source->id,
            'content_hash' => hash('sha256', uniqid('', true)),
            'raw_message' => 'Ankara İzmir 24 ton 0532 123 45 67',
            'encrypted_sender_phone' => Crypt::encryptString('5321234567'),
            'pickup_location' => 'Ankara', 'pickup_province_code' => '06',
            'delivery_location' => 'İzmir', 'delivery_province_code' => '35',
            'weight' => 24000, 'price' => null,
            'status' => 'parsed_success', 'parsed_by_llm' => 'regex_verified', 'visibility' => 'private',
            'duplicate_count' => 1, 'seen_sources' => ['Grup A'],
        ], $overrides));
    }

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create(['current_role' => 'admin']);
        $user->syncRoles(['super_admin']);

        return $user->fresh();
    }

    public function test_token_comes_from_panel_and_is_generated_when_missing(): void
    {
        $token = ScrapedLoadService::apiToken();
        $this->assertSame(48, strlen($token));
        $this->assertSame($token, Settings::string('scraper_api_token'), 'Üretilen anahtar panelde saklanmalı');
        $this->assertSame($token, ScrapedLoadService::apiToken());

        $body = json_decode(ScrapedLoadService::phoneRequestBody(), true);
        $this->assertSame($token, $body['token']);
        $this->assertSame('{notification}', $body['text']);

        $new = ScrapedLoadService::regenerateApiToken();
        $this->assertNotSame($token, $new);
        $this->assertSame($new, ScrapedLoadService::apiToken());
    }

    public function test_every_phone_request_is_recorded_in_live_feed(): void
    {
        Http::fake();
        $token = ScrapedLoadService::apiToken();
        $payload = ['title' => 'Grup A', 'text' => self::AD];

        $this->postJson('/api/v1/webhook/notification', $payload + ['token' => 'yanlis'])->assertStatus(401);
        $this->assertSame('unauthorized', IntakeEvent::latest('id')->first()->status);

        $this->postJson('/api/v1/webhook/notification', $payload + ['token' => $token])->assertOk()->assertJsonPath('status', 'source_pending');
        $this->assertSame('source_pending', IntakeEvent::latest('id')->first()->status);

        Scraper::where('source_identifier', 'notif:grup-a')->update(['is_active' => true]);
        $this->postJson('/api/v1/webhook/notification', ['title' => 'Grup A', 'text' => 'Ahmet: selam arkadaşlar nasılsınız', 'token' => $token])->assertOk();
        $filtered = IntakeEvent::latest('id')->first();
        $this->assertSame('filtered', $filtered->status);
        $this->assertSame('phone_missing', $filtered->reason);

        $this->postJson('/api/v1/webhook/notification', $payload + ['token' => $token])->assertOk()->assertJsonPath('status', 'created');
        $created = IntakeEvent::latest('id')->first();
        $this->assertSame('created', $created->status);
        $this->assertSame(ScrapedLoad::first()->id, $created->scraped_load_id);
        $this->assertSame('Grup A', $created->source_name);

        $this->postJson('/api/v1/webhook/notification', ['title' => 'WhatsApp', 'text' => '12 mesaj 3 sohbet', 'token' => $token])->assertOk();
        $this->assertSame('skipped', IntakeEvent::latest('id')->first()->status);
        $this->assertSame(5, IntakeEvent::count());
    }

    public function test_scheduler_heartbeat_reports_age(): void
    {
        $this->assertNull(ScrapedLoadService::schedulerAgeSeconds());
        Cache::put('scheduler.heartbeat', now()->subSeconds(90)->timestamp, now()->addDay());
        $this->assertEqualsWithDelta(90, ScrapedLoadService::schedulerAgeSeconds(), 2);
    }

    public function test_rejected_candidates_are_purged_after_retention(): void
    {
        $source = $this->source();
        $old = $this->candidate($source, ['status' => 'rejected']);
        ScrapedLoad::whereKey($old->id)->update(['updated_at' => now()->subDays(10)]);
        $fresh = $this->candidate($source, ['status' => 'rejected']);
        $queue = $this->candidate($source);

        Settings::set('scraper_rejected_retention_days', '7');
        $this->assertSame(1, app(ScrapedLoadService::class)->purgeRejected());
        $this->assertNull(ScrapedLoad::withTrashed()->find($old->id));
        $this->assertNotNull(ScrapedLoad::find($fresh->id));
        $this->assertNotNull(ScrapedLoad::find($queue->id));

        $this->assertSame(1, app(ScrapedLoadService::class)->purgeRejected(0), 'Sıfır gün: tüm reddedilenler silinir');
        $this->assertSame(1, ScrapedLoad::count());
    }

    public function test_claude_structured_output_enriches_a_candidate(): void
    {
        Settings::set('ai_parse_mode', 'fill_gaps');
        Settings::set('ai_provider', 'claude');
        Settings::set('ai_claude_model', 'claude-opus-5');
        Settings::set('ai_claude_key', 'sk-test');
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => json_encode([
                    'post_type' => 'load', 'confidence' => 0.92, 'sender_phone' => '0532 123 45 67',
                    'pickup' => ['province' => 'Ankara', 'district' => 'Ostim'], 'delivery' => ['province' => 'İzmir', 'district' => 'Aliağa'],
                    'goods' => 'palet', 'goods_category' => null, 'vehicle_type' => 'kirkayak', 'vehicle_flexible' => false,
                    'weight_kg' => 24000, 'price_try' => 45000, 'urgent' => true, 'pickup_date_text' => 'yarın', 'multiple_loads' => false, 'notes' => null,
                ])]],
                'usage' => ['input_tokens' => 800, 'output_tokens' => 90],
            ]),
        ]);

        $parser = app(AiParserService::class);
        $this->assertTrue($parser->isConfigured());
        $this->assertSame('claude', $parser->provider());

        $source = $this->source();
        $load = $this->candidate($source, ['raw_message' => 'Ostim çıkış Aliağa varış palet yarın acil 45.000 tl 0532 123 45 67', 'weight' => null, 'vehicle_type' => null, 'ai_status' => 'pending']);
        $this->assertTrue(app(ScrapedLoadService::class)->reparseWithAi($load, $parser, true));

        $load->refresh();
        $this->assertSame('done', $load->ai_status);
        $this->assertSame(24000, (int) $load->weight);
        $this->assertSame(45000, (int) $load->price);
        $this->assertSame('kirkayak', $load->vehicle_type);
        $this->assertSame(6, (int) $load->pickup_province_code);
        $this->assertSame(35, (int) $load->delivery_province_code);
        $this->assertEqualsWithDelta(0.92, (float) $load->parse_confidence, 0.001);
        $this->assertSame('claude', $load->parse_metadata['ai']['provider']);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $request->hasHeader('x-api-key', 'sk-test')
                && $request->hasHeader('anthropic-beta', 'server-side-fallback-2026-07-01')
                && $body['model'] === 'claude-opus-5'
                && $body['fallbacks'] === 'default'
                && $body['output_config']['effort'] === 'low'
                && $body['output_config']['format']['type'] === 'json_schema'
                && ($body['system'][0]['cache_control']['type'] ?? null) === 'ephemeral';
        });
    }

    public function test_ai_quota_error_leaves_candidate_pending_and_missing_key_marks_failed(): void
    {
        $source = $this->source();
        $load = $this->candidate($source, ['ai_status' => 'pending']);

        $this->assertFalse(app(ScrapedLoadService::class)->reparseWithAi($load));
        $this->assertSame('failed', $load->fresh()->ai_status);

        Settings::set('ai_claude_key', 'sk-test');
        Settings::set('ai_provider', 'claude');
        Settings::set('ai_claude_model', 'claude-opus-5'); // sabit model: liste çağrısı sıralı sahte yanıtı tüketmesin
        Http::fakeSequence('api.anthropic.com/*')
            ->push(['error' => ['message' => 'rate limit']], 429)
            ->push(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => json_encode(['post_type' => 'load', 'confidence' => 0.5, 'sender_phone' => null, 'pickup' => null, 'delivery' => null, 'goods' => null, 'goods_category' => null, 'vehicle_type' => 'kirkayak', 'vehicle_flexible' => false, 'weight_kg' => null, 'price_try' => null, 'urgent' => false, 'pickup_date_text' => null, 'multiple_loads' => false, 'notes' => null])]]]);
        $load->forceFill(['ai_status' => 'pending'])->save();
        $this->assertFalse(app(ScrapedLoadService::class)->reparseWithAi($load, null, true));
        $this->assertSame('pending', $load->fresh()->ai_status, 'Kota hatası: sonra yeniden denenir');

        // Kuyruk komutu bekleyenleri tekrar dener.
        $this->assertSame(1, app(ScrapedLoadService::class)->aiEnrichPending());
        $this->assertSame('done', $load->fresh()->ai_status);
        $this->assertSame('kirkayak', $load->fresh()->vehicle_type);
    }

    public function test_admin_can_bulk_reject_restore_and_delete(): void
    {
        $admin = $this->admin();
        $source = $this->source();
        $a = $this->candidate($source);
        $b = $this->candidate($source);
        $c = $this->candidate($source);

        $this->actingAs($admin)->get('/adminsystem/scrapers')->assertOk()->assertSee('Dış Kaynak İlanları');

        $component = Volt::test('admin.scrapers-center')
            ->set('selected', [(string) $a->id, (string) $b->id])
            ->call('bulk', 'reject');
        $this->assertSame('rejected', $a->fresh()->status);
        $this->assertSame('rejected', $b->fresh()->status);
        $this->assertSame('parsed_success', $c->fresh()->status);
        $this->assertSame([], $component->get('selected'));

        $component->set('activeTab', 'rejected')->assertSee('#'.$a->id)
            ->set('selected', [(string) $a->id])->call('bulk', 'restore');
        $this->assertSame('parsed_success', $a->fresh()->status);

        $component->call('delete', $b->id);
        $this->assertNull(ScrapedLoad::withTrashed()->find($b->id));

        $this->candidate($source, ['status' => 'rejected']);
        $component->call('purgeRejected');
        $this->assertSame(0, ScrapedLoad::where('status', 'rejected')->count());
        $this->assertSame(2, ScrapedLoad::count());

        $component->set('activeTab', 'sources')->assertSee('Grup A')->call('regenerateToken');
        $this->assertSame(48, strlen(Settings::string('scraper_api_token')));
    }

    public function test_non_admin_cannot_use_bulk_actions(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $viewer = User::factory()->create(['current_role' => 'admin']);
        $viewer->syncRoles(['kyc_validator']);
        $source = $this->source();
        $a = $this->candidate($source);

        $this->actingAs($viewer->fresh())->get('/adminsystem/scrapers')->assertForbidden();
        $this->assertNotNull(ScrapedLoad::find($a->id));
    }

    public function test_free_provider_chain_falls_over_on_quota_and_skips_exhausted_provider_next_time(): void
    {
        Settings::set('ai_parse_mode', 'always');
        Settings::set('ai_groq_key', 'gsk-test');
        Settings::set('ai_gemini_key', 'AIza-test');
        Settings::set('ai_groq_model', 'llama-3.3-70b-versatile');
        Settings::set('ai_gemini_model', 'gemini-2.5-flash-lite');
        Settings::set('ai_provider', 'groq'); // öncelik Groq, sonra varsayılan sıra (Gemini…)
        $ok = ['post_type' => 'load', 'confidence' => 0.9, 'sender_phone' => '0532 123 45 67', 'pickup' => ['province' => 'Ankara', 'district' => null], 'delivery' => ['province' => 'İzmir', 'district' => null], 'goods' => 'palet', 'goods_category' => null, 'vehicle_type' => 'tir', 'vehicle_flexible' => false, 'weight_kg' => 24000, 'price_try' => null, 'urgent' => false, 'pickup_date_text' => null, 'multiple_loads' => false, 'notes' => null];
        Http::fake([
            'api.groq.com/*' => Http::response(['error' => ['message' => 'Rate limit reached']], 429),
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($ok)]]]]], 'usageMetadata' => ['promptTokenCount' => 500, 'candidatesTokenCount' => 80]]),
        ]);

        $parser = app(AiParserService::class);
        $this->assertSame(['groq', 'gemini'], $parser->chain());
        $result = $parser->enrich('Ankara İzmir 24 ton palet 0532 123 45 67');
        $this->assertSame('done', $result['status']);
        $this->assertSame('gemini', $result['data']['provider']);
        Http::assertSentCount(2);
        $this->assertSame(['groq'], $parser->exhaustedToday());

        // İkinci ilan: kotası dolan Groq atlanır, doğrudan Gemini çağrılır.
        $parser->enrich('Bursa Antalya 8 ton mobilya 0544 222 33 44');
        Http::assertSentCount(3);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'generativelanguage.googleapis.com') && str_contains($r->url(), 'gemini-2.5-flash-lite'));

        // OpenAI uyumlu uç JSON kipiyle çağrılır.
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.groq.com/openai/v1/chat/completions')
            && $r->hasHeader('Authorization', 'Bearer gsk-test')
            && $r->data()['response_format']['type'] === 'json_object'
            && $r->data()['model'] === 'llama-3.3-70b-versatile');
    }

    public function test_rule_and_ai_province_conflict_blocks_auto_approval(): void
    {
        $parser = app(AiParserService::class);
        $rule = ['success' => true, 'sender_phone' => '5321234567', 'pickup_location' => 'Ankara', 'delivery_location' => 'İzmir', 'vehicle_type' => null, 'vehicle_type_source' => null, 'weight' => null, 'price' => null, 'goods_type' => null, 'parsed_by_llm' => 'regex_verified'];
        $ai = ['provider' => 'gemini', 'model' => 'x', 'is_load' => true, 'confidence' => 0.9, 'sender_phone' => '5321234567', 'pickup_location' => 'Adana', 'delivery_location' => 'İzmir', 'vehicle_type' => 'tir', 'weight' => 24000, 'price' => null, 'goods_type' => 'palet'];
        $merged = $parser->merge($rule, $ai);
        $this->assertSame('Ankara', $merged['pickup_location'], 'Kuralın çözdüğü il korunur');
        $this->assertSame(['rule' => 'Ankara', 'ai' => 'Adana'], $merged['ai_conflict']['pickup_location']);
        $this->assertArrayNotHasKey('delivery_location', $merged['ai_conflict']);

        $source = $this->source();
        Settings::set('scraper_auto_approve', '1');
        $clean = $this->candidate($source);
        $conflicted = $this->candidate($source, ['parse_metadata' => ['ai_conflict' => $merged['ai_conflict']]]);
        $lowConfidence = $this->candidate($source, ['parse_confidence' => 0.3]);
        $service = app(ScrapedLoadService::class);
        $this->assertNull($service->autoApprovalBlocker($clean));
        $this->assertStringContainsString('farklı il', (string) $service->autoApprovalBlocker($conflicted));
        $this->assertStringContainsString('güveni düşük', (string) $service->autoApprovalBlocker($lowConfidence));
    }

    public function test_setup_page_and_macro_download_carry_the_current_token(): void
    {
        Storage::fake('local');
        $this->get('/kurulum/telefon/'.str_repeat('a', 24))->assertNotFound();
        $url = ScrapedLoadService::setupUrl();
        $this->get($url)->assertOk()->assertSee('Telefon kurulumu')->assertSee('Hazır makro dosyası henüz yüklenmemiş')->assertSee(ScrapedLoadService::apiToken());
        $this->get($url.'/NavlunIQ.macro')->assertNotFound();

        $oldToken = 'eskianahtar1234567890';
        $export = json_encode(['m_name' => 'NavlunIQ', 'm_actionList' => [['m_classType' => 'HttpRequestAction', 'm_urlToOpen' => 'http://eski.example/api/v1/webhook/notification', 'm_body' => '{"title":"{not_title}","text":"{notification}","token":"'.$oldToken.'"}']]]);
        $info = ScrapedLoadService::storeMacroTemplate($export);
        $this->assertSame($oldToken, $info['token']);

        $download = $this->get($url.'/NavlunIQ.macro')->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="NavlunIQ.macro"');
        $content = $download->getContent();
        $this->assertStringNotContainsString($oldToken, $content);
        $this->assertStringContainsString(ScrapedLoadService::apiToken(), $content);
        $decoded = json_decode($content, true);
        $this->assertNotNull($decoded, 'Yamalanan dosya geçerli JSON kalmalı');
        $this->assertSame(url('/api/v1/webhook/notification'), $decoded['m_actionList'][0]['m_urlToOpen'], 'Adres (kaçışlı JSON içinde de) güncel siteye çevrilir');
        $this->assertStringNotContainsString('eski.example', $content);
        $this->get($url)->assertOk()->assertSee('NavlunIQ.macro dosyasını indir');

        // Anahtar yenilenince indirilen dosya yeni anahtarı taşır; bağlantı yenilenince eski kod ölür.
        $new = ScrapedLoadService::regenerateApiToken();
        $this->assertStringContainsString($new, $this->get($url.'/NavlunIQ.macro')->getContent());
        ScrapedLoadService::regenerateSetupCode();
        $this->get($url)->assertNotFound();

        $this->expectException(\InvalidArgumentException::class);
        ScrapedLoadService::storeMacroTemplate('{"m_name":"başka makro"}');
    }

    public function test_admin_uploads_macro_template_from_panel(): void
    {
        $this->actingAs($this->admin());
        Storage::fake('local');
        $file = UploadedFile::fake()->createWithContent('NavlunIQ.macro', json_encode(['m_actionList' => [['m_body' => '{"token":"abcdefgh12345678"}', 'm_url' => 'https://navluniq.com/api/v1/webhook/notification']]]));
        Volt::test('admin.scrapers-center')->set('activeTab', 'sources')->set('macroFile', $file)->call('uploadMacro')->assertHasNoErrors()
            ->assertSee('NavlunIQ.macro indir');
        $this->assertTrue(ScrapedLoadService::hasMacroTemplate());
        $this->assertSame('abcdefgh12345678', Settings::string('macrodroid_template_token'));

        Volt::test('admin.scrapers-center')->set('activeTab', 'sources')->set('macroFile', UploadedFile::fake()->createWithContent('x.macro', 'bu json değil'))->call('uploadMacro')->assertHasErrors('macroFile');
    }

    public function test_provider_test_button_explains_errors_and_rate_limit_cooldown_is_short(): void
    {
        $this->assertSame(300, AiParserService::cooldownSeconds('Rate limit reached'));
        $this->assertSame(10, AiParserService::cooldownSeconds('Rate limit reached for model. Please try again in 3.5s.'));
        $this->assertSame(2 * 3600 + 10 * 60 + 5, AiParserService::cooldownSeconds('Limit 1000, Used 1000, Requested 1. Please try again in 2h10m0s.'));
        $this->assertSame(35, AiParserService::cooldownSeconds('{"retryDelay": "30s"}'));

        Settings::set('ai_gemini_key', 'AIza-test');
        Settings::set('ai_groq_key', 'gsk-test');
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => ['code' => 403, 'message' => 'Requests to this API generativelanguage.googleapis.com method are blocked.', 'status' => 'PERMISSION_DENIED', 'details' => [['reason' => 'API_KEY_SERVICE_BLOCKED']]]], 403),
            'api.groq.com/*' => Http::response(['error' => ['message' => 'Rate limit reached for model llama-3.3-70b-versatile. Please try again in 12.5s.']], 429),
        ]);
        $parser = app(AiParserService::class);

        $gemini = $parser->testProvider('gemini');
        $this->assertFalse($gemini['ok']);
        $this->assertStringContainsString('Haritalar için kısıtlanmış olabilir', $gemini['message']);

        $groq = $parser->testProvider('groq');
        $this->assertFalse($groq['ok']);
        $this->assertStringContainsString('Hız/kota sınırı', $groq['message']);
        $this->assertSame(['gemini', 'groq'], array_keys($parser->lastErrors()));

        // Kuyruktan çözümleme: 403 kalıcı, 429 kısa süreli → aday bekler; Groq 18 sn sonra yeniden denenebilir, gün boyu kilitlenmez.
        $result = $parser->enrich('Ankara İzmir 24 ton palet 0532 123 45 67');
        $this->assertSame('pending', $result['status']);
        $usage = AiProviderUsage::where('provider', 'groq')->first();
        $this->assertTrue($usage->quota_exhausted);
        $this->assertEqualsWithDelta(18, now()->diffInSeconds($usage->quota_resets_at), 2);
        $this->assertSame(['groq'], $parser->exhaustedToday());
        $this->travel(20)->seconds();
        $this->assertSame([], $parser->exhaustedToday());

        $this->assertSame('Anahtar girilmemiş.', $parser->testProvider('mistral')['message']);
    }

    public function test_models_are_listed_live_and_auto_selected_with_404_self_repair(): void
    {
        // Tercih kalıpları: kararlı flash-lite en yeni sürüm; uygun olmayanlar (tts, embedding, preview) elenir.
        $this->assertSame('gemini-3.5-flash-lite', AiParserService::pickModel('gemini', ['gemini-2.5-flash-lite', 'gemini-3.5-flash-lite-preview', 'gemini-3.5-flash-lite', 'gemini-3.5-flash', 'gemini-2.5-flash-tts', 'gemini-embedding-001']));
        $this->assertSame('gemini-3.5-flash', AiParserService::pickModel('gemini', ['gemini-3.5-flash', 'gemini-3.5-pro'], exclude: null));
        $this->assertSame('llama-3.3-70b-versatile', AiParserService::pickModel('groq', ['whisper-large-v3', 'llama-guard-4-12b', 'openai/gpt-oss-120b', 'llama-3.3-70b-versatile']));
        $this->assertSame('openai/gpt-oss-120b', AiParserService::pickModel('groq', ['whisper-large-v3', 'openai/gpt-oss-120b', 'llama-3.3-70b-versatile'], exclude: 'llama-3.3-70b-versatile'));
        $this->assertSame('meta-llama/llama-3.3-70b-instruct:free', AiParserService::pickModel('openrouter', ['openai/gpt-4o', 'meta-llama/llama-3.3-70b-instruct:free', 'qwen/qwen3-32b:free']));
        $this->assertNull(AiParserService::pickModel('gemini', ['gemini-embedding-001']));

        Settings::set('ai_provider', 'gemini');
        Settings::set('ai_gemini_key', 'AIza-test');
        Settings::set('ai_gemini_model', 'gemini-2.5-flash-lite'); // sunucudaki eski ayar
        $ok = ['post_type' => 'load', 'confidence' => 0.9, 'sender_phone' => '0532 123 45 67', 'pickup' => ['province' => 'Ankara', 'district' => 'Ostim'], 'delivery' => ['province' => 'İzmir', 'district' => null], 'goods' => 'palet', 'goods_category' => null, 'vehicle_type' => 'tir', 'vehicle_flexible' => false, 'weight_kg' => 24000, 'price_try' => null, 'urgent' => false, 'pickup_date_text' => null, 'multiple_loads' => false, 'notes' => null];
        Http::fake([
            'generativelanguage.googleapis.com/v1beta/models?*' => Http::response(['models' => [
                ['name' => 'models/gemini-3.5-flash-lite', 'supportedGenerationMethods' => ['generateContent']],
                ['name' => 'models/gemini-3.5-flash', 'supportedGenerationMethods' => ['generateContent']],
                ['name' => 'models/gemini-embedding-001', 'supportedGenerationMethods' => ['embedContent']],
            ]]),
            'generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-lite:generateContent' => Http::response(['error' => ['code' => 404, 'message' => 'This model models/gemini-2.5-flash-lite is no longer available to new users. Please update your code to use models/gemini-3.5-flash-lite', 'status' => 'NOT_FOUND']], 404),
            'generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($ok)]]]]]]),
        ]);

        $parser = app(AiParserService::class);
        $this->assertSame(['gemini-3.5-flash', 'gemini-3.5-flash-lite'], $parser->listModels('gemini'));

        $test = $parser->testProvider('gemini');
        $this->assertTrue($test['ok'], $test['message']);
        $this->assertStringContainsString('gemini-3.5-flash-lite', $test['message']);
        $this->assertStringContainsString('otomatik seçilen güncel model', $test['message']);
        $this->assertSame('', Settings::string('ai_gemini_model'), 'Kalkan model ayarı "Otomatik"e çevrilir');
        $this->assertSame('gemini-3.5-flash-lite', $parser->model('gemini'));

        // Sonraki çağrılar doğrudan güncel modele gider; kuyruk çözümlemesi de çalışır.
        $result = $parser->enrich('Ankara İzmir 24 ton palet 0532 123 45 67');
        $this->assertSame('done', $result['status']);
        $this->assertSame('gemini-3.5-flash-lite', $result['data']['model']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'gemini-3.5-flash-lite:generateContent'));

        $this->assertArrayHasKey('gemini-3.5-flash-lite', $parser->modelOptions('gemini'));
    }

    public function test_ping_link_and_broken_json_repair_and_form_params(): void
    {
        Http::fake();
        $token = ScrapedLoadService::apiToken();

        $this->get('/api/v1/webhook/notification/ping?token=yanlis')->assertStatus(401);
        $this->assertSame('unauthorized', IntakeEvent::latest('id')->first()->status);
        $this->get(ScrapedLoadService::pingUrl())->assertOk()->assertJsonPath('ok', true);
        $this->assertSame('ping', IntakeEvent::latest('id')->first()->status);
        $this->assertStringContainsString('telefon sunucuya ulaştı', IntakeEvent::latest('id')->first()->statusLabel());

        Scraper::create(['name' => 'Grup A', 'type' => 'notification', 'source_identifier' => 'notif:grup-a', 'is_active' => true]);

        // Mesajda tırnak ve satır sonu: MacroDroid'in ürettiği JSON bozuk gelir, alanlar yine okunur.
        $broken = '{"title":"Grup A","text":"Ahmet: "ACİL" Ankara\'dan İzmir\'e 24 ton palet
tenteli tır 0532 123 45 67","ticker":"","app":"WhatsApp","token":"'.$token.'"}';
        $this->assertNull(json_decode($broken), 'Örnek gövde gerçekten bozuk JSON olmalı');
        $response = $this->call('POST', '/api/v1/webhook/notification', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $broken);
        $response->assertOk()->assertJsonPath('status', 'created');
        $this->assertSame('created', IntakeEvent::latest('id')->first()->status);
        $this->assertSame(1, ScrapedLoad::count());

        // Form alanları (önerilen kurulum) doğrudan çalışır.
        $params = ScrapedLoadService::phoneRequestParams();
        $this->assertSame($token, $params['token']);
        $this->post('/api/v1/webhook/notification', ['title' => 'Grup A', 'text' => 'Mehmet: Bursa Antalya 8 ton mobilya kamyon 0544 222 33 44', 'app' => 'WhatsApp', 'token' => $token], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('status', 'created');
        $this->assertSame(2, ScrapedLoad::count());

        // Form alanlı makro dışa aktarımında anahtar 48 karakterlik dizgeden bulunur.
        Storage::fake('local');
        $export = json_encode(['m_actionList' => [['m_urlToOpen' => 'https://navluniq.com/api/v1/webhook/notification', 'm_params' => [['m_name' => 'token', 'm_value' => $token]]]]]);
        $this->assertSame($token, ScrapedLoadService::storeMacroTemplate($export)['token']);
    }
}
