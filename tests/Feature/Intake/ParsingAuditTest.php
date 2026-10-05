<?php

namespace Tests\Feature\Intake;

use App\Http\Middleware\FirewallMiddleware;
use App\Jobs\ProcessNotificationMessage;
use App\Models\IntakeEvent;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Services\AiParserService;
use App\Services\LoadIntakeService;
use App\Services\LoadStandardizer;
use App\Services\NotificationIntakeParser;
use App\Support\Settings;
use App\Support\TextPrep;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** 2026-10-05 ilan hattı denetimi (mesajı okuma ve parçalama): her test bir bulgunun kapandığını doğrular. Uydurma numara ve adlar. */
class ParsingAuditTest extends TestCase
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

    private function source(string $name = 'Grup A', string $id = 'notif:grup-a'): Scraper
    {
        return Scraper::create(['name' => $name, 'type' => 'notification', 'source_identifier' => $id, 'is_active' => true]);
    }

    private function intake(string $text, string $id): array
    {
        return app(LoadIntakeService::class)->intake(['group_name' => 'Grup A', 'raw_message' => $text, 'message_id' => $id, 'source_jid' => 'notif:grup-a']);
    }

    public function test_group_names_containing_arama_or_call_are_not_system_notifications(): void
    {
        foreach (['Yük Arama Grubu: Ali Usta', 'Nakliyeci Görüşme Platformu: Ali Usta', 'Truck Call Center TR: Ali Usta', 'Araç Arama Yük Bulma', 'Yük Backup Grubu: Ali Usta'] as $title) {
            $r = NotificationIntakeParser::parse(['title' => $title, 'text' => 'Ankara - İzmir 24 ton tenteli tır 0532 000 00 01']);
            $this->assertNull($r['skipped'], $title);
        }
        $this->assertSame('summary_notification', NotificationIntakeParser::parse(['title' => 'Cevapsız arama', 'text' => 'Ali Usta'])['skipped']);
        $this->assertSame('summary_notification', NotificationIntakeParser::parse(['title' => 'Sesli arama', 'text' => 'Gelen arama'])['skipped']);
        $this->assertSame('summary_notification', NotificationIntakeParser::parse(['title' => 'WhatsApp', 'text' => 'Yedekleme tamamlandı'])['skipped']);
    }

    public function test_invisible_characters_and_invalid_utf8_do_not_drop_the_phone(): void
    {
        $this->source();
        $lrm = "Ankara - İzmir 24 ton tenteli tır\n\u{200E}0532\u{200E} 000 00 01";
        $zwsp = "Bursa - Konya 12 ton kapalı tır\n0533\u{200B}000\u{200B}00\u{200B}02";
        $this->assertSame('created', $this->intake($lrm, 'a')['status']);
        $this->assertSame('created', $this->intake($zwsp, 'b')['status']);
        $this->assertSame('created', $this->intake("Adana - Mersin 12 ton kapal\xC4 tır 0534 000 00 03", 'c')['status']);
        $this->assertSame(['5320000001'], AiParserService::phonesIn("\u{200E}0532\u{200E} 000 00 01"));
        $this->assertTrue(mb_check_encoding(TextPrep::stripInvisible("kapal\xC4 tır"), 'UTF-8'));
    }

    public function test_label_variants_do_not_split_a_grouped_message(): void
    {
        $text = "Ahmet Usta: Ankara - İzmir 24 ton tenteli tır\nYükleme günü: Pazartesi\nBoşaltma adresi: Aliağa OSB\nTel no: 0532 000 00 01";
        $r = NotificationIntakeParser::parse(['title' => 'Nakliye Grubu', 'text' => $text]);
        $this->assertCount(1, $r['messages']);
        $this->assertStringContainsString('0532 000 00 01', $r['messages'][0]['text']);
        foreach (['Yükleme günü', 'Tel no', 'Boşaltma adresi', 'Yükleme adresi', 'Yük bilgisi', 'Yükleme saati', 'İrtibat no', 'GSM no', 'Ödeme şekli', 'Boşaltma noktaları', 'Avrupa yakası', 'Anadolu yakası', 'Yük var', 'Teslim adresi'] as $label) {
            $this->assertFalse(NotificationIntakeParser::looksLikeSenderPrefix($label), $label);
        }
        $this->assertTrue(NotificationIntakeParser::looksLikeSenderPrefix('Ahmet Usta'));
        $this->assertTrue(NotificationIntakeParser::looksLikeSenderPrefix('Mehmet Y.'));
    }

    public function test_words_ending_in_tan_ten_are_not_pickup_headers(): void
    {
        $p = app(AiParserService::class);
        $routes = function (string $msg) use ($p): array {
            $out = [];
            foreach (LoadIntakeService::splitSegments($msg) as $seg) {
                $r = $p->parseCheap($seg['text']);
                $out[] = ($seg['series']['pickup'] ?? $r['pickup_location'] ?? '?').' → '.($seg['series']['delivery'] ?? $r['delivery_location'] ?? '?');
            }

            return $out;
        };
        $this->assertSame(['Kocaeli Gebze → Ankara', 'Kocaeli Gebze → Konya', 'Kocaeli Gebze → Adana'], $routes("GEBZE YÜKLER\nANKARA TENTEN\nKONYA TENTEN\nADANA KAPALI\n0532 000 00 01"));
        $this->assertSame(['Samsun → Kahramanmaraş Elbistan', 'Samsun → Adana', 'Samsun → Mersin'], $routes("SAMSUN YÜKLEMELERİ\nK.MARAŞ ELBİSTAN\nADANA\nMERSİN\n0532 000 00 01"));
        $this->assertSame(['Ankara → İzmir'], $routes("Ankara - İzmir 12 ton toptan gıda tenteli tır\n0532 000 00 01"));
        $this->assertTrue(AiParserService::hasTrueAblative('samsundan'));
        $this->assertFalse(AiParserService::hasTrueAblative('k.maraş elbistan'));
        $this->assertFalse(AiParserService::hasTrueAblative('ankara tenten'));
    }

    public function test_less_common_verb_forms_give_direction(): void
    {
        $p = app(AiParserService::class);
        foreach ([
            "İZMİR BOŞALTILACAK\nANKARA YÜKLENECEK\n24 ton tenteli tır\n0532 000 00 01" => ['Ankara', 'İzmir'],
            "Mersin boşaltılır\nAdana yüklenir\n10 ton 0532 000 00 01" => ['Adana', 'Mersin'],
            "Samsun tahliye\nOrdu yükleme\n10 ton 0532 000 00 01" => ['Ordu', 'Samsun'],
        ] as $msg => $want) {
            $r = $p->parseCheap($msg);
            $std = app(LoadStandardizer::class)->standardize($msg, $r);
            $this->assertSame($want, [$std['pickup_location'], $std['delivery_location']], $msg);
        }
    }

    public function test_facebook_posts_seen_while_source_pending_are_ingested_after_approval(): void
    {
        $dump = "Deneme Yük Grubu•Katıl\nAli Veli•3s•Paylaşılanlar: Herkese açık grup\nAli Veli'nin gönderisi için diğer seçenekler\nAnkara - İzmir palet yükü 24 ton tenteli tır 0532 000 00 01\nBeğen\nYorum yap";
        $payload = ['kind' => 'screen', 'app' => 'Facebook', 'title' => 'ekran', 'text' => $dump];
        $this->assertSame('source_pending', $this->postJson('/api/v1/webhook/notification', $payload, ['X-Scraper-Token' => 'phone-secret'])->assertOk()->json('status'));
        $this->assertSame('already_seen', $this->postJson('/api/v1/webhook/notification', $payload, ['X-Scraper-Token' => 'phone-secret'])->assertOk()->json('reason'), '10 dk içinde tekrar kuyruğa girmez');
        Scraper::query()->update(['is_active' => true]);
        $this->travel(11)->minutes();
        $this->assertSame('created', $this->postJson('/api/v1/webhook/notification', $payload, ['X-Scraper-Token' => 'phone-secret'])->assertOk()->json('status'));
    }

    public function test_infrastructure_error_propagates_so_the_queue_retries(): void
    {
        $this->source();
        $this->mock(LoadStandardizer::class, function ($m): void {
            $m->shouldReceive('standardize')->andThrow(new \PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'));
        });
        $job = new ProcessNotificationMessage('Grup A', 'whatsapp', ['text' => 'Ankara - İzmir 24 ton tenteli tır 0532 000 00 01', 'phone' => null, 'sender' => 'Ali'], 'Grup A', null);
        $this->expectException(\PDOException::class);
        $job->handle(app(LoadIntakeService::class));
    }

    public function test_same_phone_same_provinces_with_different_explicit_districts_are_two_ads(): void
    {
        $this->source();
        $this->assertSame('created', $this->intake('Ankara Polatlı - İstanbul Tuzla tenteli tır 0532 000 00 01', 'a')['status']);
        $this->assertSame('created', $this->intake('Ankara Sincan - İstanbul Esenyurt tenteli tır 0532 000 00 01', 'b')['status']);
        $this->assertSame('duplicate', $this->intake('Ankara Sincan - İstanbul Esenyurt tenteli tır acil 0532 000 00 01', 'c')['status']);
    }

    public function test_repost_that_adds_a_price_supersedes_the_old_candidate(): void
    {
        $this->source();
        $a = $this->intake('Ankara - İzmir 24 ton tenteli tır 0532 000 00 01', 'a');
        $b = $this->intake('Ankara - İzmir 24 ton tenteli tır 45.000 TL 0532 000 00 01', 'b');
        $this->assertSame(['created', 'created'], [$a['status'], $b['status']]);
        $new = ScrapedLoad::find($b['scraped_load_id']);
        $this->assertSame([45000.0, $a['scraped_load_id']], [(float) $new->price, (int) $new->meta('supersedes')]);
    }

    public function test_lone_digit_and_iban_are_not_phones(): void
    {
        $this->assertSame(['5320000001'], AiParserService::phonesIn("2 araç 5\n0532 000 00 01"));
        $this->assertSame(['5320000001'], AiParserService::phonesIn('kat 5 0532 000 00 01'));
        $this->assertSame([], AiParserService::phonesIn('Ödeme: TR53 2000 0001 2345 6789 0123 45'));
        $this->assertSame(['5320000001', '5330000002'], AiParserService::phonesIn('0 532 000 00 01 / 0 533 000 00 02'));
        $this->assertSame(['5320000001'], AiParserService::phonesIn('+90 (532) 000 00 01'));
    }

    public function test_ai_first_mode_keeps_one_live_feed_row_per_segment(): void
    {
        $this->source();
        $this->intake('Ankara - İzmir 24 ton tenteli tır 0532 000 00 01', 'a');
        Settings::set('ai_parse_mode', 'always');
        Settings::set('ai_groq_key', 'test-key');
        $ai = ['is_load' => true, 'confidence' => 0.95, 'ads' => [
            ['is_load' => true, 'post_type' => 'load', 'confidence' => 0.95, 'excerpt' => 'Bursa - Konya 10 ton', 'pickup' => ['province' => 'Bursa'], 'delivery' => ['province' => 'Konya'], 'phones' => ['05320000001'], 'weight_kg' => 10000],
            ['is_load' => true, 'post_type' => 'load', 'confidence' => 0.95, 'excerpt' => 'Adana - Mersin 5 ton', 'pickup' => ['province' => 'Adana'], 'delivery' => ['province' => 'Mersin'], 'phones' => ['05320000001'], 'weight_kg' => 5000],
        ]];
        Http::swap(new Factory(app(Dispatcher::class)));
        Http::fake([
            'api.groq.com/openai/v1/models' => Http::response(['data' => [['id' => 'llama-3.3-70b-versatile']]]),
            'api.groq.com/openai/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode($ai)]]], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]]),
        ]);
        $r = $this->intake("Ankara - İzmir 24 ton tenteli tır\n\nBursa - Konya 10 ton\n\nAdana - Mersin 5 ton\n\n0532 000 00 01", 'b');
        $this->assertSame(3, ScrapedLoad::count());
        $this->assertCount(3, $r['segments']);
        $this->assertSame('duplicate', $r['segments'][0]['status']);
    }

    public function test_not_whatsapp_skips_do_not_store_the_text(): void
    {
        $this->postJson('/api/v1/webhook/notification', ['app' => 'Mesajlar', 'title' => 'Banka', 'text' => 'Hesabınıza 1.250 TL geldi', 'token' => 'phone-secret'])->assertOk()->assertJsonPath('reason', 'not_whatsapp');
        $event = IntakeEvent::query()->where('status', 'skipped')->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertNull($event->excerpt);
    }
}
