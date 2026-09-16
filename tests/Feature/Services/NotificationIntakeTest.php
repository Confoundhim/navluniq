<?php

namespace Tests\Feature\Services;

use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Services\NotificationIntakeParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotificationIntakeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::fake();
        config()->set('services.scraper.token', 'phone-secret');
        $this->withoutMiddleware(\App\Http\Middleware\FirewallMiddleware::class);
    }

    public function test_parser_splits_group_notification_lines_and_skips_summaries(): void
    {
        $parsed = NotificationIntakeParser::parse([
            'title' => 'Ankara Nakliye Grubu (2 mesaj)',
            'text' => "Ahmet Usta: Ankara'dan İzmir'e 24 ton palet 0532 123 45 67\n+90 544 222 33 44: Bursa-Antalya komple tır lazım\n45.000 TL",
        ]);
        $this->assertNull($parsed['skipped']);
        $this->assertSame('Ankara Nakliye Grubu', $parsed['group']);
        $this->assertCount(2, $parsed['messages']);
        $this->assertSame('5442223344', $parsed['messages'][1]['phone']);
        $this->assertStringContainsString("komple tır lazım\n45.000 TL", $parsed['messages'][1]['text']);

        $this->assertSame('summary_notification', NotificationIntakeParser::parse(['title' => 'WhatsApp', 'text' => '12 mesaj 3 sohbet'])['skipped']);
        $this->assertSame('no_group_sender_prefix', NotificationIntakeParser::parse(['title' => 'Mehmet', 'text' => 'selam nasılsın'])['skipped']);
        $this->assertSame('not_whatsapp', NotificationIntakeParser::parse(['title' => 'X', 'text' => 'Y: Z', 'app' => 'Instagram'])['skipped']);
        $this->assertSame('notif:ankara-nakliye-grubu', NotificationIntakeParser::sourceIdentifier('Ankara Nakliye Grubu'));
    }

    public function test_endpoint_requires_token_and_creates_pending_source_then_loads(): void
    {
        $payload = ['title' => 'Ankara Nakliye Grubu', 'text' => "Ahmet Usta: Ankara'dan İzmir'e 24 ton palet 0532 123 45 67"];

        $this->postJson('/api/v1/webhook/notification', $payload)->assertStatus(401);

        $this->postJson('/api/v1/webhook/notification', $payload + ['token' => 'phone-secret'])
            ->assertOk()->assertJsonPath('status', 'source_pending');
        $source = Scraper::where('source_identifier', 'notif:ankara-nakliye-grubu')->first();
        $this->assertNotNull($source);
        $this->assertSame('notification', $source->type);

        $source->update(['is_active' => true]);
        $this->postJson('/api/v1/webhook/notification', $payload, ['X-Scraper-Token' => 'phone-secret'])
            ->assertOk()->assertJsonPath('status', 'created');
        $this->assertSame(1, ScrapedLoad::count());
        $this->assertSame('İzmir', ScrapedLoad::first()->delivery_location);

        // Android aynı bildirimi güncelleyip tekrar verirse yeni kayıt oluşmaz.
        $this->postJson('/api/v1/webhook/notification', $payload, ['X-Scraper-Token' => 'phone-secret'])
            ->assertOk()->assertJsonPath('status', 'duplicate');
        $this->assertSame(1, ScrapedLoad::count());
        Http::assertNothingSent();
    }
}
