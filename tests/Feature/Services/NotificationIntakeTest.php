<?php

namespace Tests\Feature\Services;

use App\Http\Middleware\FirewallMiddleware;
use App\Jobs\ProcessNotificationMessage;
use App\Jobs\QueueHeartbeat;
use App\Models\IntakeEvent;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Services\LoadIntakeService;
use App\Services\NotificationIntakeParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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
        $this->withoutMiddleware(FirewallMiddleware::class);
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
            'text' => 'Ankara: İzmir 24 ton palet 0532 123 45 67',
        ]);
        $this->assertNull($titled['skipped']);
        $this->assertSame('Test', $titled['group']);
        $this->assertCount(1, $titled['messages']);
        $this->assertSame('Osman Yılmaz', $titled['messages'][0]['sender']);
        $this->assertSame('Ankara: İzmir 24 ton palet 0532 123 45 67', $titled['messages'][0]['text']);
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

    public function test_facebook_group_notifications_are_parsed_and_deduplicated_with_whatsapp(): void
    {
        // Başlık "Facebook", metin "Ad, Grup grubunda paylaştı: gönderi"
        $fb = NotificationIntakeParser::parse(['app' => 'Facebook', 'title' => 'Facebook', 'text' => "Ahmet Yılmaz, Nakliye Yük İlanları grubunda paylaştı: Ankara'dan İzmir'e 24 ton palet 0532 123 45 67"]);
        $this->assertNull($fb['skipped']);
        $this->assertSame(['facebook', 'Nakliye Yük İlanları', 'Ahmet Yılmaz'], [$fb['platform'], $fb['group'], $fb['messages'][0]['sender']]);
        $this->assertSame("Ankara'dan İzmir'e 24 ton palet 0532 123 45 67", $fb['messages'][0]['text']);
        $this->assertSame('fb:nakliye-yuk-ilanlari', NotificationIntakeParser::sourceIdentifier($fb['group'], 'facebook'));

        // Başlık grup adı, metin "Ad: gönderi"; text_big daha uzunsa o alınır; kısaltma işareti atılır
        $titled = NotificationIntakeParser::parse(['app' => 'Facebook', 'title' => 'Tır Yük İlanları', 'text' => 'Mehmet Kaya: Bursa Antalya 20 ton…', 'text_big' => 'Mehmet Kaya: Bursa Antalya 20 ton tenteli 0533 111 22 33 …']);
        $this->assertSame(['Tır Yük İlanları', 'Mehmet Kaya', 'Bursa Antalya 20 ton tenteli 0533 111 22 33'], [$titled['group'], $titled['messages'][0]['sender'], $titled['messages'][0]['text']]);

        // "Ad Grup grubunda paylaştı" (virgülsüz): grup adı başlıktan
        $noComma = NotificationIntakeParser::parse(['app' => 'Facebook Lite', 'title' => 'Nakliyeciler', 'text' => 'Ali Veli Nakliyeciler grubunda yeni bir gönderi paylaştı: «Konya İstanbul 12 ton 0544 000 11 22»']);
        $this->assertSame(['Nakliyeciler', 'Konya İstanbul 12 ton 0544 000 11 22'], [$noComma['group'], $noComma['messages'][0]['text']]);
        $this->assertSame('Ali Veli Nakliyeciler', NotificationIntakeParser::parse(['app' => 'Facebook', 'title' => 'Facebook', 'text' => 'Ali Veli Nakliyeciler grubunda paylaştı: Konya İstanbul 12 ton 0544 000 11 22'])['group']);

        // İlan olmayan bildirimler ve gövdesiz bildirim atlanır
        $this->assertSame('facebook_not_post', NotificationIntakeParser::parse(['app' => 'Facebook', 'title' => 'Facebook', 'text' => 'Ayşe gönderinize yorum yaptı: harika'])['skipped']);
        $this->assertSame('facebook_not_post', NotificationIntakeParser::parse(['app' => 'Facebook', 'title' => 'Facebook', 'text' => 'Mehmet size arkadaşlık isteği gönderdi'])['skipped']);
        $this->assertSame('facebook_no_body', NotificationIntakeParser::parse(['app' => 'Facebook', 'title' => 'Facebook', 'text' => 'Ahmet Yılmaz, Nakliye grubunda paylaştı'])['skipped']);
        $this->assertSame('facebook_no_group', NotificationIntakeParser::parse(['app' => 'Facebook', 'title' => 'Facebook', 'text' => 'Bugün 3 yeni bildiriminiz var'])['skipped']);

        // Uçtan uca: aynı ilan önce WhatsApp'tan, sonra Facebook'tan gelir → tek kayıt, iki kaynak
        $wa = ['title' => 'Ankara Nakliye Grubu', 'text' => "Ahmet Usta: Ankara'dan İzmir'e 24 ton palet 0532 123 45 67", 'token' => 'phone-secret'];
        $this->postJson('/api/v1/webhook/notification', $wa)->assertOk();
        Scraper::where('source_identifier', 'notif:ankara-nakliye-grubu')->update(['is_active' => true]);
        $this->postJson('/api/v1/webhook/notification', $wa)->assertOk()->assertJsonPath('status', 'created');

        $fbPayload = ['app' => 'Facebook', 'title' => 'Facebook', 'text' => "Ahmet Yılmaz, Nakliye Yük İlanları grubunda paylaştı: Ankara'dan İzmir'e 24 ton palet 0532 123 45 67", 'token' => 'phone-secret'];
        $this->postJson('/api/v1/webhook/notification', $fbPayload)->assertOk()->assertJsonPath('status', 'duplicate');
        $this->assertSame(1, ScrapedLoad::count());
        $load = ScrapedLoad::first();
        $this->assertSame(2, (int) $load->duplicate_count);
        $this->assertSame(['Ankara Nakliye Grubu', 'Nakliye Yük İlanları'], $load->seen_sources);

        // Yeni bir Facebook ilanı: kaynak "facebook" türüyle pasif açılır; aktif edilince işlenir
        $new = ['app' => 'Facebook', 'title' => 'Facebook', 'text' => 'Ahmet Yılmaz, Nakliye Yük İlanları grubunda paylaştı: Bursa Antalya 20 ton tenteli 0533 111 22 33', 'token' => 'phone-secret'];
        $this->postJson('/api/v1/webhook/notification', $new)->assertOk()->assertJsonPath('status', 'source_pending');
        $source = Scraper::where('source_identifier', 'fb:nakliye-yuk-ilanlari')->first();
        $this->assertSame(['facebook', 'Nakliye Yük İlanları', false], [$source->type, $source->name, $source->is_active]);
        $source->update(['is_active' => true]);
        $this->postJson('/api/v1/webhook/notification', $new)->assertOk()->assertJsonPath('status', 'created');
        $this->assertSame(2, ScrapedLoad::count());
        $this->assertSame('Antalya', ScrapedLoad::latest('id')->first()->delivery_location);
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

    public function test_messages_go_to_the_queue_only_while_the_worker_heartbeat_is_fresh(): void
    {
        Scraper::create(['name' => 'Ankara Nakliye Grubu', 'type' => 'notification', 'source_identifier' => 'notif:ankara-nakliye-grubu', 'is_active' => true]);
        $payload = ['title' => 'Ankara Nakliye Grubu', 'text' => "Ahmet Usta: Ankara'dan İzmir'e 24 ton palet 0532 123 45 67", 'token' => 'phone-secret'];

        // Nabız yok: mesaj istek içinde işlenir, telefon sonucu hemen görür.
        Queue::fake();
        $this->assertFalse(QueueHeartbeat::alive());
        $this->postJson('/api/v1/webhook/notification', $payload)->assertOk()->assertJsonPath('status', 'created');
        Queue::assertNothingPushed();
        $this->assertSame(1, ScrapedLoad::count());
        $this->assertSame('created', IntakeEvent::latest('id')->first()->status);

        // İşçi nabız veriyor: mesaj kuyruğa bırakılır, istek "queued" ile döner; kayıt işçi çalışınca oluşur.
        (new QueueHeartbeat)->handle();
        $this->assertTrue(QueueHeartbeat::alive());
        $second = ['title' => 'Ankara Nakliye Grubu', 'text' => "Veli Usta: Bursa'dan Adana'ya 12 ton kiremit 0533 987 65 43", 'token' => 'phone-secret'];
        $this->postJson('/api/v1/webhook/notification', $second)->assertOk()->assertJsonPath('status', 'queued')->assertJsonPath('processed', 1);
        $this->assertSame(1, ScrapedLoad::count());
        Queue::assertPushed(ProcessNotificationMessage::class, function (ProcessNotificationMessage $job): bool {
            $job->handle(app(LoadIntakeService::class));

            return $job->group === 'Ankara Nakliye Grubu' && $job->platform === 'whatsapp';
        });
        $this->assertSame(2, ScrapedLoad::count());
        $this->assertSame('Adana', ScrapedLoad::latest('id')->first()->delivery_location);
        $event = IntakeEvent::latest('id')->first();
        $this->assertSame(['created', 'Ankara Nakliye Grubu'], [$event->status, $event->source_name]);

        // Nabız eskiyince (işçi durdu) yeniden istek içinde işlenir.
        $this->travel(4)->minutes();
        $this->assertFalse(QueueHeartbeat::alive());
        $third = ['title' => 'Ankara Nakliye Grubu', 'text' => "Can Usta: Konya'dan Samsun'a 20 ton un 0534 111 22 33", 'token' => 'phone-secret'];
        $this->postJson('/api/v1/webhook/notification', $third)->assertOk()->assertJsonPath('status', 'created');
        $this->assertSame(3, ScrapedLoad::count());
        Http::assertNothingSent();
    }

    public function test_failed_queued_message_is_written_to_the_live_feed(): void
    {
        $job = new ProcessNotificationMessage('Grup X', 'whatsapp', ['text' => 'Ankara İzmir 0532 123 45 67', 'phone' => null], 'Grup X', '10.0.0.1');
        $job->failed(new \RuntimeException('bağlantı koptu'));
        $event = IntakeEvent::latest('id')->first();
        $this->assertSame(['failed', 'Grup X', '10.0.0.1'], [$event->status, $event->source_name, $event->ip]);
        $this->assertStringContainsString('bağlantı koptu', $event->reason);
    }
}
