<?php

namespace Tests\Feature\Intake;

use App\Models\IntakeEvent;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Services\AiParserService;
use App\Services\LoadIntakeService;
use App\Support\Phone;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Osman'ın 2026-10-05 kararları: (1) sabit hat / 0850 numaralı ilanlar alınır, (2) aynı yük başka numarayla paylaşılmışsa
 * iki ilan da yayınlanır ve "benzer ilan" rozeti taşır, (4) "TORBALI YÜKLER" başlığında Torbalı kalkış yeridir.
 * Uydurma numara ve grup adı; gerçek mesaj yok.
 */
class PendingDecisionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        Settings::set('ai_parse_mode', 'off');
    }

    private function source(): Scraper
    {
        return Scraper::create(['name' => 'Deneme Grubu', 'type' => 'notification', 'source_identifier' => 'notif:deneme-grubu', 'is_active' => true]);
    }

    private function intake(string $message, string $id): array
    {
        return app(LoadIntakeService::class)->intake(['group_name' => 'Deneme Grubu', 'raw_message' => $message, 'message_id' => $id, 'source_jid' => 'notif:deneme-grubu']);
    }

    // --- (1) Sabit hat ve 0850 ---------------------------------------------------------------------------------------

    public function test_landline_and_corporate_numbers_are_recognised_as_ad_phones(): void
    {
        $this->assertSame(['8502223344'], AiParserService::phonesIn('İzmir Torbalı - Ankara 20 ton tenteli 0850 222 33 44'));
        $this->assertSame(['8502223344'], AiParserService::phonesIn('İzmir - Ankara +90 850 222 33 44'));
        $this->assertSame(['3123456789'], AiParserService::phonesIn('Ankara Sincan - Konya 12 ton (0312) 345 67 89'));
        $this->assertSame(['2123456789'], AiParserService::phonesIn('İstanbul - Bursa 0212 345 67 89'));
        $this->assertSame(['4441234'], AiParserService::phonesIn('İstanbul - Bursa çağrı merkezi 444 1 234'));
        // Sabit hat ile cep bir arada: ikisi de alınır, ilk yazılan ana numaradır.
        $this->assertSame(['3123456789', '5321112233'], AiParserService::phonesIn('Ankara İzmir 0312 345 67 89 ve 0532 111 22 33'));
        // Tarih, saat, fiyat ve IBAN numara değildir.
        $this->assertSame([], AiParserService::phonesIn('Yükleme 05.10.2026 saat 14:30 Ankara İzmir'));
        $this->assertSame([], AiParserService::phonesIn('Yükleme 02.10.2026 14:30 tenteli tır'));
        $this->assertSame([], AiParserService::phonesIn('Ankara İzmir 12.444 1 234 TL'));
        $this->assertSame(['5321112233'], AiParserService::phonesIn('TR53 2000 0001 2345 6789 0123 45 iban 0532 111 22 33'));
        // Sabit hat yalnız başında 0 ya da +90 ile: çıplak "212 345 67 89" tonaj/fiyat dizisi olabilir.
        $this->assertSame([], AiParserService::phonesIn('Ankara İzmir 212 345 67 89'));
    }

    public function test_ad_with_only_a_corporate_number_is_stored_and_shown_with_line_type(): void
    {
        $this->source();
        $r = $this->intake('İzmir Torbalı yükleme Ankara teslim 20 ton tenteli tır 0850 222 33 44', 'm1');
        $this->assertSame('created', $r['status'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $load = ScrapedLoad::first();
        $this->assertSame('8502223344', $load->plainPhone());
        $this->assertSame(['İzmir Torbalı', 'Ankara'], [$load->pickup_location, $load->delivery_location]);
        $this->assertSame('0850 222 33 44', $load->formatted_phone);
        $this->assertSame('0850 *** ** 44', $load->masked_phone);
        $this->assertSame('Kurumsal hat (0850)', Phone::kindLabel('8502223344'));
        $this->assertTrue(Phone::supportsWhatsapp('8502223344')); // WhatsApp Business 0850 kabul eder
        $this->assertFalse(Phone::supportsWhatsapp('2123456789'));
        $this->assertSame('tel:+902123456789', Phone::telHref('2123456789'));
        $this->assertSame('tel:4441234', Phone::telHref('4441234'));
        $this->assertSame('444 1 234', Phone::format('4441234'));
        // Canlı akışta numara maskeli.
        $this->assertSame('Ara 0212… / 444… / 0532…', IntakeEvent::maskPhones('Ara 0212 345 67 89 / 444 1 234 / 0532 111 22 33'));
    }

    public function test_landline_ads_can_be_switched_off_from_the_panel(): void
    {
        Settings::set('scraper_landline_phones', '0');
        $this->source();
        $this->assertSame([], AiParserService::phonesIn('Ankara - İzmir 24 ton 0850 222 33 44'));
        $r = $this->intake('Ankara - İzmir 24 ton tenteli 0850 222 33 44', 'm1');
        $this->assertSame(['filtered', 'phone_missing'], [$r['status'], $r['reason'] ?? null]);
        // Cep her zaman alınır.
        $this->assertSame(['5321112233'], AiParserService::phonesIn('Ankara - İzmir 0532 111 22 33'));
    }

    public function test_account_phone_rule_still_accepts_only_mobile_numbers(): void
    {
        $this->assertSame('5321112233', Phone::normalize('0532 111 22 33'));
        $this->assertNull(Phone::normalize('0850 222 33 44'));
        $this->assertNull(Phone::normalize('0312 345 67 89'));
        $this->assertSame('8502223344', Phone::normalizeContact('0850 222 33 44'));
        $this->assertSame('3123456789', Phone::normalizeContact('+90 312 345 67 89'));
        $this->assertSame('4441234', Phone::normalizeContact('444 1 234'));
        $this->assertNull(Phone::normalizeContact('1234567890')); // 1 ile başlayan Türkiye numarası değil
        $this->assertNull(Phone::normalizeContact('05.10.2026'));
    }

    // --- (2) Aynı yük başka numarayla ------------------------------------------------------------------------------------

    public function test_same_load_shared_under_another_number_is_published_separately_with_similar_badge(): void
    {
        $this->source();
        $a = $this->intake('Ankara Sincan - İzmir Aliağa 24 ton palet tenteli tır 0532 111 22 33', 'm1');
        $b = $this->intake('Ankara Sincan - İzmir Aliağa 24 ton palet tenteli tır 0533 444 55 66', 'm2');
        $this->assertSame(['created', 'created'], [$a['status'], $b['status']]);
        $first = ScrapedLoad::find($a['scraped_load_id']);
        $second = ScrapedLoad::find($b['scraped_load_id']);
        $this->assertSame([$first->id], $second->meta('similar_to'));
        $this->assertSame([$second->id], $first->fresh()->meta('similar_with'));
        $this->assertTrue($first->fresh()->hasSimilar());
        $this->assertTrue($second->hasSimilar());
        $this->assertSame([$first->id], $second->similarIds());

        // Kart iki ilanda da rozeti gösterir.
        $html = (string) $this->blade('<x-external-load-card :item="$item" />', ['item' => $second]);
        $this->assertStringContainsString('Benzer ilan · farklı numara', $html);
    }

    public function test_structured_match_marks_similar_only_when_route_goods_and_a_hard_field_agree(): void
    {
        $this->source();
        $this->intake('Konya çıkışlı Bursa teslim 26 ton torbalı çimento damperli 0532 111 22 33', 'm1');
        // Farklı sözcüklerle aynı yük: aynı il çifti, aynı yük, aynı tonaj → benzer.
        $b = $this->intake('Konya Bursa 26 ton çimento torbalı yüklenecek damperli araç lazım 0505 111 22 33', 'm2');
        $this->assertSame('created', $b['status']);
        $this->assertCount(1, ScrapedLoad::find($b['scraped_load_id'])->similarIds());
        // Aynı il çifti ama başka yük ve tonaj: benzer değil.
        $c = $this->intake('Konya - Bursa 12 ton mobilya kapalı kamyon 0506 111 22 33', 'm3');
        $this->assertSame('created', $c['status']);
        $this->assertSame([], ScrapedLoad::find($c['scraped_load_id'])->similarIds());
        // İlçeler çelişiyor: benzer değil (Ereğli → Bursa ile Akşehir → Bursa ayrı yükler).
        $d = $this->intake('Konya Ereğli - Bursa 26 ton torbalı çimento damperli 0507 111 22 33', 'm4');
        $e = $this->intake('Konya Akşehir - Bursa 26 ton torbalı çimento damperli 0508 111 22 33', 'm5');
        $this->assertSame([], array_intersect(ScrapedLoad::find($e['scraped_load_id'])->similarIds(), [$d['scraped_load_id']]));
    }

    public function test_same_number_same_route_is_still_a_duplicate_not_a_similar_ad(): void
    {
        $this->source();
        $a = $this->intake('Ankara - İzmir 24 ton palet tenteli tır 0532 111 22 33', 'm1');
        $b = $this->intake('Ankara - İzmir 24 ton palet tenteli tır lazım acil 0532 111 22 33', 'm2');
        $this->assertSame(['created', 'duplicate'], [$a['status'], $b['status']]);
        $this->assertFalse(ScrapedLoad::find($a['scraped_load_id'])->hasSimilar());
    }

    // --- (4) "TORBALI YÜKLER" ----------------------------------------------------------------------------------------------

    public function test_goods_like_district_followed_by_a_loading_verb_is_the_pickup(): void
    {
        $this->source();
        $r = $this->intake("TORBALI YÜKLER\nANKARA 2 ARAÇ TENTELİ\nKONYA 1 ARAÇ\n0532 111 22 33", 'm1');
        $this->assertSame('created', $r['status'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $loads = ScrapedLoad::query()->orderBy('id')->get();
        $this->assertSame([['İzmir Torbalı', 'Ankara'], ['İzmir Torbalı', 'Konya']], $loads->map(fn ($l) => [$l->pickup_location, $l->delivery_location])->all());
        $this->assertSame(2, $loads[0]->vehicle_count);

        $p = app(AiParserService::class);
        foreach (['Torbalı yükler Ankara iner tenteli 0532 111 22 33', 'Torbalı çıkışlı Ankara teslim 0532 111 22 33', 'Kemer yüklemeli Ankara iner 0532 111 22 33'] as $msg) {
            $parsed = $p->parseCheap($msg);
            $this->assertSame('Ankara', $parsed['delivery_location'] ?? null, $msg);
            $this->assertNotNull($parsed['pickup_location'] ?? null, $msg);
        }
        $this->assertSame('İzmir Torbalı', $p->parseCheap('Torbalı yükler Ankara iner 0532 111 22 33')['pickup_location']);
        // Araya yük sözcüğü girince eskisi gibi yük: "Konya torbalı çimento Bursa" Konya → Bursa.
        $this->assertSame(['Konya', 'Bursa'], array_values(array_intersect_key($p->parseCheap('Konya torbalı çimento yükler Bursa iner 0532 111 22 33'), ['pickup_location' => 1, 'delivery_location' => 1])));
        $this->assertSame(['İzmir Ödemiş', 'Ankara'], array_values(array_intersect_key($p->parseCheap("Kiraz yükü İzmir Ödemiş'ten Ankara'ya 0532 111 22 33"), ['pickup_location' => 1, 'delivery_location' => 1])));
        // Yüke de bağlanan biçimler yer yapmaz: "Kiraz yükleme var", "Kiraz yüklenecek" yüktür.
        $this->assertSame([], AiParserService::placesIn('Kiraz yükleme var'));
        $this->assertSame([], AiParserService::placesIn('Kiraz yüklenecek Ankara iner')[0]['district'] === 'Kiraz' ? ['hata'] : []);
    }
}
