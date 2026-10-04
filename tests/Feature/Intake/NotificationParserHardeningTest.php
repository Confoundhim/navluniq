<?php

namespace Tests\Feature\Intake;

use App\Http\Middleware\FirewallMiddleware;
use App\Jobs\ProcessNotificationMessage;
use App\Models\IntakeEvent;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Services\LoadIntakeService;
use App\Services\NotificationIntakeParser;
use App\Services\ScrapedLoadService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 2026-10-04 denetimi, 1. paket: bildirim ayrıştırıcı gerçek ilanları özet/sohbet sanıp düşürüyordu (hacim kaybı) ve canlı
 * akışa gönderen adı / açık numara yazıyordu (KVKK). Uydurma numaralar ve adlar.
 */
class NotificationParserHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::fake();
        Settings::set('ai_parse_mode', 'off');
        config()->set('services.scraper.token', 'phone-secret');
        $this->withoutMiddleware(FirewallMiddleware::class);
    }

    public function test_system_words_inside_a_real_ad_do_not_make_it_a_summary_notification(): void
    {
        foreach ([
            'Ankara - İzmir 24 ton palet fiyat görüşmeli 0532 111 22 33',
            'Bursa çıkışlı Konya varışlı tenteli tır yük arıyoruz 0532 111 22 33',
            'Adana Mersin 10 ton koli, görüşmek için arayın 0532 111 22 33',
            'Samsun - Ordu kaçırılan fırsat değil, 12 ton fındık 0532 111 22 33',
        ] as $text) {
            $parsed = NotificationIntakeParser::parse(['title' => 'Nakliye Grubu: Ali Usta', 'text' => $text]);
            $this->assertNull($parsed['skipped'], $text);
            $this->assertSame($text, $parsed['messages'][0]['text']);
        }

        // Gerçek sistem bildirimleri yine atlanır: başlıkta ya da kısa metinde.
        $this->assertSame('summary_notification', NotificationIntakeParser::parse(['title' => 'WhatsApp', 'text' => 'Yedekleme tamamlandı'])['skipped']);
        $this->assertSame('summary_notification', NotificationIntakeParser::parse(['title' => 'Ali Usta', 'text' => 'Cevapsız arama'])['skipped']);
        $this->assertSame('summary_notification', NotificationIntakeParser::parse(['title' => 'Kaçırılan sesli arama', 'text' => 'Ali Usta'])['skipped']);
        $this->assertSame('summary_notification', NotificationIntakeParser::parse(['title' => 'Yedekleme', 'text' => 'Sohbet yedeklemesi sürüyor…'])['skipped']);
        $this->assertSame('summary_notification', NotificationIntakeParser::parse(['title' => 'WhatsApp', 'text' => '12 mesaj 3 sohbet'])['skipped']);
    }

    public function test_labeled_ad_is_one_message_and_label_lines_are_not_senders(): void
    {
        // Gönderen ilk satırda, sonraki satırlar "Etiket: değer": tek mesaj, etiketler devam satırı.
        $parsed = NotificationIntakeParser::parse(['title' => 'Nakliye Grubu', 'text' => "Mehmet: Kalkış: Ankara Ostim\nVarış: İzmir Aliağa\nYük: 24 ton palet\nAraç: tenteli tır\nİletişim: 0532 111 22 33"]);
        $this->assertNull($parsed['skipped']);
        $this->assertCount(1, $parsed['messages']);
        $this->assertSame('Mehmet', $parsed['messages'][0]['sender']);
        $this->assertSame("Kalkış: Ankara Ostim\nVarış: İzmir Aliağa\nYük: 24 ton palet\nAraç: tenteli tır\nİletişim: 0532 111 22 33", $parsed['messages'][0]['text']);

        // Gönderen hiç yok, ilk satır etiket: metnin tamamı tek mesaj.
        $noSender = NotificationIntakeParser::parse(['title' => 'Nakliye Grubu', 'text' => "Yükleme yeri: Kocaeli Gebze\nBoşaltma: Bursa\nTonaj: 12 ton\nNot: pazartesi\nTel: 0532 111 22 33"]);
        $this->assertCount(1, $noSender['messages']);
        $this->assertNull($noSender['messages'][0]['sender']);
        $this->assertStringStartsWith('Yükleme yeri: Kocaeli Gebze', $noSender['messages'][0]['text']);

        // Gruplanmış bildirim (iki gönderen, biri numarayla) hâlâ iki mesaj; "Ankara: İzmir…" satırı gönderen değil.
        $grouped = NotificationIntakeParser::parse(['title' => 'Nakliye Grubu', 'text' => "Ahmet Usta: Ankara: İzmir 24 ton palet 0532 111 22 33\n+90 544 222 33 44: Bursa-Antalya komple tır lazım\nAhmet Usta: fiyat 45 bin"]);
        $this->assertCount(3, $grouped['messages']);
        $this->assertSame(['Ahmet Usta', '+90 544 222 33 44', 'Ahmet Usta'], array_column($grouped['messages'], 'sender'));
        $this->assertSame('Ankara: İzmir 24 ton palet 0532 111 22 33', $grouped['messages'][0]['text']);

        $this->assertTrue(NotificationIntakeParser::looksLikeSenderPrefix('Ahmet Usta'));
        $this->assertTrue(NotificationIntakeParser::looksLikeSenderPrefix('Mehmet Y.'));
        foreach (['Kalkış', 'Yükleme yeri', 'Araç tipi', 'İletişim', 'Ankara', 'İzmir Aliağa', 'Fiyat', 'Not', 'Boş araç', '12 ton', 'Tel', 'Nereden'] as $label) {
            $this->assertFalse(NotificationIntakeParser::looksLikeSenderPrefix($label), $label);
        }
    }

    public function test_labeled_ad_through_the_webhook_creates_exactly_one_candidate(): void
    {
        Scraper::create(['name' => 'Nakliye Grubu', 'type' => 'notification', 'source_identifier' => 'notif:nakliye-grubu', 'is_active' => true]);
        $payload = ['title' => 'Nakliye Grubu', 'text' => "Mehmet: Kalkış: Ankara Ostim\nVarış: İzmir Aliağa\nYük: 24 ton palet\nAraç: tenteli tır\nİletişim: 0532 111 22 33"];

        $this->postJson('/api/v1/webhook/notification', $payload, ['X-Scraper-Token' => 'phone-secret'])->assertOk()->assertJsonPath('status', 'created');

        $this->assertSame(1, ScrapedLoad::count());
        $load = ScrapedLoad::first();
        $this->assertSame([6, 35], [(int) $load->pickup_province_code, (int) $load->delivery_province_code]);
        $this->assertSame('tir', $load->vehicle_type);
        $this->assertSame('5321112233', $load->plainPhone());
        // Canlı akış: gönderen adı yok, numara maskeli.
        $event = IntakeEvent::query()->where('status', 'created')->latest('id')->first();
        $this->assertSame('Nakliye Grubu', $event->title);
        $this->assertStringNotContainsString('Mehmet', (string) $event->title);
        $this->assertStringNotContainsString('0532 111 22 33', (string) $event->excerpt);
        $this->assertStringContainsString('0532…', (string) $event->excerpt);
    }

    public function test_sender_number_from_the_title_counts_as_the_ad_phone(): void
    {
        Scraper::create(['name' => 'Nakliye Grubu', 'type' => 'notification', 'source_identifier' => 'notif:nakliye-grubu', 'is_active' => true]);
        // Gövdede numara yok; WhatsApp göndereni numarasıyla başlıkta verdi.
        $payload = ['title' => 'Nakliye Grubu: +90 532 111 22 33', 'text' => 'Ankara Ostim - İzmir Aliağa 24 ton palet tenteli tır'];
        $this->postJson('/api/v1/webhook/notification', $payload, ['X-Scraper-Token' => 'phone-secret'])->assertOk()->assertJsonPath('status', 'created');
        $this->assertSame('5321112233', ScrapedLoad::first()->plainPhone());
        $this->assertTrue(LoadIntakeService::looksLikeLoad('Ankara - İzmir 24 ton palet', '5321112233'));
        $this->assertFalse(LoadIntakeService::looksLikeLoad('Ankara - İzmir 24 ton palet'));
        $this->assertSame('no_logistics_signal', LoadIntakeService::filterReason('selam', '5321112233'));
        $this->assertSame('phone_missing', LoadIntakeService::filterReason('selam'));
    }

    public function test_numeric_only_lines_survive_screen_noise_and_event_words_inside_a_post_do_not_skip_it(): void
    {
        $dump = "Yük Grubu•Katıl\nAli Veli•3s•Paylaşılanlar: Herkese açık grup\nAli Veli'nin gönderisi için diğer seçenekler\nAnkara - İzmir palet yükü\n24\nton tenteli 0532 111 22 33\nBeğen\nYorum yap";
        $r = NotificationIntakeParser::parse(['app' => 'Facebook', 'kind' => 'screen', 'title' => 'ekran', 'text' => $dump]);
        $this->assertNull($r['skipped']);
        $this->assertStringContainsString("\n24\n", $r['messages'][0]['text']);
        $this->assertStringNotContainsString('Ali Veli', $r['messages'][0]['text']);

        $ad = NotificationIntakeParser::parse(['app' => 'Facebook', 'title' => 'Yük Grubu', 'text' => 'Ankara Ostim - İzmir Aliağa 24 ton palet yükü, etkinlik malzemesi, hatırlatma: pazartesi yükleme 0532 111 22 33']);
        $this->assertNull($ad['skipped']);
        $this->assertSame('facebook_not_post', NotificationIntakeParser::parse(['app' => 'Facebook', 'title' => 'Facebook', 'text' => 'Etkinlik hatırlatması: Nakliyeciler buluşması yarın'])['skipped']);
        $this->assertSame('facebook_not_post', NotificationIntakeParser::parse(['app' => 'Facebook', 'title' => 'Doğum günü', 'text' => 'Bugün Ali Veli\'nin doğum günü'])['skipped']);
    }

    public function test_live_feed_never_stores_sender_names_phones_or_unauthorized_bodies(): void
    {
        $job = new ProcessNotificationMessage('Nakliye Grubu', 'whatsapp', ['text' => 'Ankara İzmir 0532 111 22 33', 'phone' => null, 'sender' => 'Ali Usta'], 'Nakliye Grubu (3 mesaj): Ali Usta', '10.0.0.1');
        $job->failed(new \RuntimeException('bağlantı koptu'));
        $event = IntakeEvent::latest('id')->first();
        $this->assertSame('Nakliye Grubu', $event->title);
        $this->assertSame('Ankara İzmir 0532…', $event->excerpt);
        $this->assertSame('0532… ve 0533…', IntakeEvent::maskPhones('0532 111 22 33 ve +90 533 444 55 66'));

        // Anahtarsız istek: başlık/gövde saklanmaz, yalnız gerekçe + boyut; aynı IP dakikada en çok 10 kayıt.
        for ($i = 0; $i < 14; $i++) {
            $this->postJson('/api/v1/webhook/notification', ['title' => 'Gizli Grup: Ali Usta', 'text' => 'Ankara İzmir 0532 111 22 33'])->assertStatus(401);
        }
        $unauthorized = IntakeEvent::query()->where('status', 'unauthorized')->get();
        $this->assertCount(10, $unauthorized);
        foreach ($unauthorized as $e) {
            $this->assertNull($e->title);
            $this->assertStringNotContainsString('Ali Usta', (string) $e->excerpt);
            $this->assertStringNotContainsString('0532', (string) $e->excerpt);
            $this->assertStringContainsString('Gelen gövde', (string) $e->excerpt);
            $this->assertSame('token_missing', $e->reason);
        }

        // Atlanan bildirimde de başlıktan yalnız grup adı kalır.
        $this->postJson('/api/v1/webhook/notification', ['title' => 'Gizli Grup: Ali Usta', 'text' => '12 mesaj 3 sohbet'], ['X-Scraper-Token' => 'phone-secret'])->assertOk();
        $this->assertSame('Gizli Grup', IntakeEvent::query()->where('status', 'skipped')->latest('id')->first()->title);
    }

    public function test_facebook_dump_cache_is_redacted_and_short_lived_and_events_purge_by_setting(): void
    {
        Scraper::create(['name' => 'Yük Grubu', 'type' => 'facebook', 'source_identifier' => 'fb:yuk-grubu', 'is_active' => true]);
        $dump = "Yük Grubu•Katıl\nAli Veli•3s•Paylaşılanlar: Herkese açık grup\nAli Veli'nin gönderisi için diğer seçenekler\nAli Veli profil resmi\nAnkara - İzmir 24 ton palet tenteli 0532 111 22 33\nBeğen";
        $this->call('POST', '/api/v1/webhook/notification', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SCRAPER_TOKEN' => 'phone-secret', 'HTTP_X_INTAKE_KIND' => 'screen'], $dump)->assertOk();
        $cached = (array) Cache::get('fb:last_dumps', []);
        $this->assertCount(1, $cached);
        $this->assertStringNotContainsString('Ali Veli', $cached[0]['text']);
        $this->assertStringContainsString("Yazar'ın gönderisi için diğer seçenekler", $cached[0]['text']);
        $this->assertStringContainsString('Yazar•3s•Paylaşılanlar', $cached[0]['text']);
        $this->assertStringContainsString('Ankara - İzmir 24 ton palet', $cached[0]['text']);
        $this->travel(7)->hours();
        $this->assertSame([], (array) Cache::get('fb:last_dumps', []));

        // Canlı akış kayıtları ayarlı gün sonra silinir (varsayılan 7).
        IntakeEvent::record('created', ['source_name' => 'x', 'excerpt' => 'eski', 'created_at' => now()->subDays(8)]);
        IntakeEvent::record('created', ['source_name' => 'x', 'excerpt' => 'yeni', 'created_at' => now()->subDays(3)]);
        $this->assertSame(7, Settings::int('intake_event_days'));
        $before = IntakeEvent::count();
        $this->assertSame(1, app(ScrapedLoadService::class)->purgeIntakeEvents());
        $this->assertSame($before - 1, IntakeEvent::count());
        Settings::set('intake_event_days', 2);
        $this->assertSame(1, app(ScrapedLoadService::class)->purgeIntakeEvents());
    }
}
