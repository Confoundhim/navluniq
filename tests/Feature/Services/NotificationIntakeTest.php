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

        // Yeni Android: gönderen ve mesaj sayısı başlıkta ("Grup (3 mesaj): Gönderen"), metin yalnız mesaj.
        $titled = NotificationIntakeParser::parse([
            'title' => 'Test (3 mesaj): Osman Yılmaz',
            'text' => "Ankara: İzmir 24 ton palet 0532 123 45 67",
        ]);
        $this->assertNull($titled['skipped']);
        $this->assertSame('Test', $titled['group']);
        $this->assertCount(1, $titled['messages']);
        $this->assertSame('Osman Yılmaz', $titled['messages'][0]['sender']);
        $this->assertSame("Ankara: İzmir 24 ton palet 0532 123 45 67", $titled['messages'][0]['text']);
        $this->assertSame(['Test', 'Osman Yılmaz'], NotificationIntakeParser::splitTitle('Test: Osman Yılmaz'));
        $this->assertSame(['Test', 'Osman Yılmaz'], NotificationIntakeParser::splitTitle('Osman Yılmaz @ Test'));
        $this->assertSame(['Ankara Nakliye', null], NotificationIntakeParser::splitTitle('Ankara Nakliye (5 mesaj)'));
        $this->assertSame(['Test', '+90 532 111 22 33'], NotificationIntakeParser::splitTitle('Test (2 mesaj): +90 532 111 22 33'));
        $this->assertSame('notif:test', NotificationIntakeParser::sourceIdentifier($titled['group']));

        // Yeni Android: metin yalnız mesaj, gönderen ticker'da ("Gönderen @ Grup: mesaj").
        $single = NotificationIntakeParser::parse([
            'title' => 'Test',
            'text' => "Ankara'dan İzmir'e 24 ton palet yük 0532 123 45 67",
            'ticker' => "Osman Yılmaz @ Test: Ankara'dan İzmir'e 24 ton palet yük 0532 123 45 67",
        ]);
        $this->assertNull($single['skipped']);
        $this->assertCount(1, $single['messages']);
        $this->assertSame('Osman Yılmaz', $single['messages'][0]['sender']);
        $this->assertSame("Ankara'dan İzmir'e 24 ton palet yük 0532 123 45 67", $single['messages'][0]['text']);

        // Ticker yok: gönderen bilinmez, metin yine tek mesajdır (sohbet mi ilan mı ayrımı ön filtrede).
        $noTicker = NotificationIntakeParser::parse(['title' => 'Mehmet', 'text' => 'selam nasılsın']);
        $this->assertNull($noTicker['skipped']);
        $this->assertNull($noTicker['messages'][0]['sender']);
        $this->assertSame('selam nasılsın', $noTicker['messages'][0]['text']);
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
