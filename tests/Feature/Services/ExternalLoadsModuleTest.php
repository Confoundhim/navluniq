<?php

namespace Tests\Feature\Services;

use App\Http\Middleware\FirewallMiddleware;
use App\Models\IntakeEvent;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Models\User;
use App\Services\AiParserService;
use App\Services\ScrapedLoadService;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
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
}
