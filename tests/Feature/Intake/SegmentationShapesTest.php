<?php

namespace Tests\Feature\Intake;

use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Services\AiParserService;
use App\Services\LoadIntakeService;
use App\Services\LoadStandardizer;
use App\Services\ScrapedLoadService;
use App\Support\SeriesAd;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 2026-10-04 denetimi, 4. paket: ayırma ve yanlış şehir. Virgüllü varış listesi rota sanılıyor, "İSTANBULDAN:" başlık
 * sayılmıyor, gidiş-dönüş tek ilan oluyor, seri etiketine araç sözcüğü giriyor, 15 ilan sınırı sessizce kesiyor,
 * aynı rotanın fiyatı değişen yeni paylaşımı "tekrar" diye yutuluyor, yük sahibi dili "boş araç" diye eleniyordu. Uydurma numaralar.
 */
class SegmentationShapesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::fake();
        Settings::set('ai_parse_mode', 'off');
    }

    private function source(): Scraper
    {
        return Scraper::create(['name' => 'Grup A', 'type' => 'notification', 'source_identifier' => 'notif:grup-a', 'is_active' => true]);
    }

    private function intake(string $text, string $id, ?string $phone = null): array
    {
        return app(LoadIntakeService::class)->intake(['group_name' => 'Grup A', 'raw_message' => $text, 'message_id' => $id, 'source_jid' => 'notif:grup-a', 'sender_phone' => $phone]);
    }

    public function test_header_with_comma_list_fans_out_one_ad_per_destination(): void
    {
        $this->source();
        $r = $this->intake('İSTANBUL ÇIKIŞLI: Ankara, İzmir, Bursa 13.60 tenteli 0532 111 22 33', 'c1');
        $this->assertSame('created', $r['status']);
        $this->assertCount(3, $r['created_ids']);
        $loads = ScrapedLoad::orderBy('id')->get();
        $this->assertSame(['İstanbul|Ankara', 'İstanbul|İzmir', 'İstanbul|Bursa'], $loads->map(fn ($l) => $l->pickup_location.'|'.$l->delivery_location)->all());
        $this->assertSame([3, 3, 3], $loads->map(fn ($l) => $l->meta('series')['count'])->all());
        $this->assertSame(['tir', 'tir', 'tir'], $loads->pluck('vehicle_type')->all());

        // Virgül düz bir satırda iki yer arasında rota bağlacı kalır; fiilli/çok yerli satırda liste ayracıdır.
        $this->assertSame([['pickup' => 'Mersin Tarsus', 'delivery' => 'Kayseri']], AiParserService::connectorMatches('Mersin Tarsus, Kayseri 20 ton narenciye'));
        $this->assertSame([], AiParserService::connectorMatches('İSTANBUL ÇIKIŞLI: Ankara, İzmir, Bursa 13.60 tenteli'));
        $this->assertSame([], AiParserService::connectorMatches('Hadımköy yükleme, Ankara, Konya boşaltma'));

        $parsed = SeriesAd::parseCommaListLine('İstanbul Hadımköy yükleme, Ankara, Konya, Kayseri boşaltma 3 araç tenteli');
        $this->assertSame(['İstanbul Arnavutköy', ['Ankara', 'Konya', 'Kayseri'], false], [$parsed['pickup'], $parsed['destinations'], $parsed['header']]);
        $this->assertNull(SeriesAd::parseCommaListLine('Ankara - İzmir 24 ton palet'));
    }

    public function test_comma_delivery_list_is_stops_without_count_and_fan_out_with_count(): void
    {
        $std = app(LoadStandardizer::class);
        $parser = app(AiParserService::class);
        $single = 'İstanbul Hadımköy yükleme, Ankara, Konya boşaltma tenteli 0532 111 22 33';
        $this->assertCount(1, LoadIntakeService::splitSegments($single));
        $out = $std->standardize($single, $parser->parseCheap($single));
        $this->assertSame(['İstanbul Arnavutköy', 'Ankara', ['Ankara', 'Konya']], [$out['pickup_location'], $out['delivery_location'], $out['delivery_stops']]);

        $multi = LoadIntakeService::splitSegments('İstanbul Hadımköy yükleme, Ankara, Konya, Kayseri boşaltma 3 araç tenteli 0532 111 22 33');
        $this->assertCount(3, $multi);
        $this->assertSame(['Ankara', 'Konya', 'Kayseri'], array_column(array_column($multi, 'series'), 'delivery'));
        $this->assertStringNotContainsString('3 araç', $multi[0]['text'], 'her ilan tek araç: adet sözcüğü taşınmaz');
    }

    public function test_dative_header_with_trailing_colon_and_round_trip_line(): void
    {
        $segments = LoadIntakeService::splitSegments("İSTANBULDAN:\nAnkara tenteli\nKonya kapalı\n0532 111 22 33");
        $this->assertCount(2, $segments);
        $this->assertSame([['İstanbul', 'Ankara'], ['İstanbul', 'Konya']], array_map(fn ($s) => [$s['series']['pickup'], $s['series']['delivery']], $segments));

        $trip = LoadIntakeService::splitSegments('Ankara-İstanbul / İstanbul-Ankara gidiş dönüş 24 ton tenteli 0532 111 22 33');
        $this->assertCount(2, $trip);
        $parser = app(AiParserService::class);
        $this->assertSame([['Ankara', 'İstanbul'], ['İstanbul', 'Ankara']], array_map(fn ($s) => [$parser->parseCheap($s['text'])['pickup_location'], $parser->parseCheap($s['text'])['delivery_location']], $trip));
        $this->assertNull(SeriesAd::roundTrip('Ankara - İstanbul 24 ton tenteli 0532 111 22 33'));
        $this->assertNull(SeriesAd::roundTrip("Ankara - İstanbul 24 ton\nBursa - İzmir 10 ton\n0532 111 22 33"), 'farklı iki rota gidiş-dönüş değildir');
    }

    public function test_series_destination_label_drops_vehicle_words_and_long_lists_report_truncation(): void
    {
        $segments = LoadIntakeService::splitSegments("GEBZE YÜKLER\nANKARA TIR\nKONYA KAPALI TIR\nADANA 2 ARAÇ\n0532 111 22 33");
        $this->assertSame(['Ankara', 'Konya', 'Adana'], array_column(array_column($segments, 'series'), 'delivery'));
        $this->assertSame(LoadIntakeService::routeKey('5321112233', 'Kocaeli Gebze', 'Ankara', true), LoadIntakeService::routeKey('5321112233', 'Kocaeli Gebze', $segments[0]['series']['delivery'], true));

        // 45 rotalı liste: 40 açılır, 5'i sınırda kalır ve sonuç bunu söyler.
        $lines = [];
        $provinces = ['Adana', 'Ankara', 'Antalya', 'Aydın', 'Balıkesir', 'Bolu', 'Bursa', 'Çorum', 'Denizli', 'Düzce', 'Edirne', 'Erzurum', 'Eskişehir', 'Gaziantep', 'Hatay',
            'Isparta', 'Mersin', 'İzmir', 'Kayseri', 'Kırklareli', 'Kocaeli', 'Konya', 'Kütahya', 'Malatya', 'Manisa', 'Muğla', 'Nevşehir', 'Niğde', 'Ordu', 'Rize',
            'Sakarya', 'Samsun', 'Sivas', 'Tekirdağ', 'Tokat', 'Trabzon', 'Uşak', 'Van', 'Yozgat', 'Zonguldak', 'Aksaray', 'Karaman', 'Kırıkkale', 'Bartın', 'Yalova'];
        foreach ($provinces as $p) {
            $lines[] = "İstanbul - {$p} 24 ton tenteli";
        }
        $segments = LoadIntakeService::splitSegments(implode("\n", $lines)."\n0532 111 22 33");
        $this->assertCount(LoadIntakeService::MAX_ADS_PER_MESSAGE, $segments);
        $this->assertSame(5, LoadIntakeService::$lastTruncated);
        $this->source();
        $r = $this->intake(implode("\n", $lines)."\n0532 111 22 33", 'big');
        $this->assertSame(5, $r['truncated_ads']);
        $this->assertStringContainsString('5 ilan daha vardı', $r['message']);
    }

    public function test_shipper_phrases_pass_and_carrier_phrases_are_filtered(): void
    {
        foreach ([
            'Ankara - İzmir 24 ton palet, boş araç arıyorum yük var 0532 111 22 33',
            'Bursa - Antalya 20 ton mobilya, boşta tır var mı 0532 111 22 33',
            'Konya - Adana 12 ton un, müsait araç arıyoruz 0532 111 22 33',
            'Samsun - Ordu boş araç lazım 15 ton 0532 111 22 33',
        ] as $shipper) {
            $this->assertFalse(LoadIntakeService::isNotLoadPattern($shipper), $shipper);
            $this->assertTrue(LoadIntakeService::looksLikeLoad($shipper), $shipper);
        }
        foreach ([
            'Kamyonum boş Ankara İzmir arası yük olan arasın 0532 111 22 33',
            'Tırım boş İstanbul çıkışlı her yere 0532 111 22 33',
            'Aracım yük bekliyor Konya Bursa 13.60 tenteli 0532 111 22 33',
            'Müsait araç Gaziantep Mersin 0532 111 22 33',
            'Boşum Antalya tarafında 0532 111 22 33',
            'Dorsem boşta Adana 0532 111 22 33',
        ] as $carrier) {
            $this->assertTrue(LoadIntakeService::isNotLoadPattern($carrier), $carrier);
        }
    }

    public function test_changed_price_or_weight_on_the_same_route_opens_a_new_candidate_that_supersedes_the_old(): void
    {
        $source = $this->source();
        $first = $this->intake('Ankara - İzmir 24 ton palet tenteli 45.000 tl 0532 111 22 33', 'r1');
        $this->assertSame('created', $first['status']);
        $old = ScrapedLoad::first();

        // Aynı rota, aynı fiyat, farklı sözcükler: tekrar.
        $this->assertSame('duplicate', $this->intake('Ankara Ostim - İzmir Aliağa palet 24 ton tenteli 45 bin 0532 111 22 33', 'r2')['status']);
        $this->assertSame(1, ScrapedLoad::count());

        // Fiyat değişti: yeni aday açılır, eski (henüz yayınlanmamış) aday arşive gider.
        $second = $this->intake('Ankara - İzmir 24 ton palet tenteli 52.000 tl 0532 111 22 33', 'r3');
        $this->assertSame('created', $second['status']);
        $new = ScrapedLoad::find($second['scraped_load_id']);
        $this->assertSame($old->id, $new->meta('supersedes'));
        $this->assertNotNull(ScrapedLoad::withTrashed()->find($old->id)->deleted_at);
        $this->assertSame($new->id, ScrapedLoad::withTrashed()->find($old->id)->meta('superseded_by'));

        // Yayındaki ilanın tonajı değişti: yeni aday, onay anında eskisinin yerine geçer (şoförler arada ilanı görmeye devam eder).
        $service = app(ScrapedLoadService::class);
        $service->approve($new, null, true);
        $third = $this->intake('Ankara - İzmir 12 ton palet tenteli 52.000 tl 0532 111 22 33', 'r4');
        $this->assertSame('created', $third['status']);
        $this->assertSame('public', $new->fresh()->visibility, 'yayındaki ilan yeni aday yayınlanana kadar kalır');
        $candidate = ScrapedLoad::find($third['scraped_load_id']);
        Settings::set('scraper_auto_approve', '1');
        Settings::set('scraper_auto_approve_require_ai', '0');
        $this->assertNull($service->autoApprovalBlocker($candidate));
        $service->approve($candidate, null, true);
        $this->assertSame('public', $candidate->fresh()->visibility);
        $this->assertNotNull(ScrapedLoad::withTrashed()->find($new->id)->deleted_at, 'eski yayın arşivlendi');

        // Kendiliğinden reddedilmiş kayıt rota tekrarı penceresinde tutmaz; yönetici reddi tutar.
        $autoRejected = ScrapedLoad::create(['scraper_id' => $source->id, 'content_hash' => hash('sha256', 'x'), 'raw_message' => 'Bursa - Konya 10 ton 0532 111 22 33',
            'encrypted_sender_phone' => Crypt::encryptString('5321112233'), 'route_key' => LoadIntakeService::routeKey('5321112233', 'Bursa', 'Konya'),
            'pickup_location' => 'Bursa', 'pickup_province_code' => 16, 'delivery_location' => 'Konya', 'delivery_province_code' => 42, 'status' => 'rejected', 'visibility' => 'private',
            'parse_metadata' => ['auto_rejected' => ['reason' => 'puan']]]);
        $this->assertSame('created', $this->intake('Bursa - Konya 10 ton palet 0532 111 22 33', 'r5')['status']);
        ScrapedLoad::create(['scraper_id' => $source->id, 'content_hash' => hash('sha256', 'y'), 'raw_message' => 'Adana - Mersin 10 ton 0532 111 22 33',
            'encrypted_sender_phone' => Crypt::encryptString('5321112233'), 'route_key' => LoadIntakeService::routeKey('5321112233', 'Adana', 'Mersin'),
            'pickup_location' => 'Adana', 'pickup_province_code' => 1, 'delivery_location' => 'Mersin', 'delivery_province_code' => 33, 'status' => 'rejected', 'visibility' => 'private']);
        $this->assertSame('duplicate', $this->intake('Adana - Mersin 10 ton palet 0532 111 22 33', 'r6')['status']);
    }

    public function test_approve_restandardize_recomputes_route_key_when_locations_change(): void
    {
        $source = $this->source();
        // Eski kayıt: "Ostim" o zaman çözülememiş, rota anahtarı ham sözcükle yazılmış; bugün katalog Ankara Yenimahalle der.
        $load = ScrapedLoad::create(['scraper_id' => $source->id, 'content_hash' => hash('sha256', 'rk'), 'raw_message' => 'Ostim - İzmir 24 ton tenteli 0532 111 22 33',
            'encrypted_sender_phone' => Crypt::encryptString('5321112233'), 'route_key' => '5321112233|ostim|izmir',
            'pickup_location' => 'Ostim', 'pickup_province_code' => null, 'delivery_location' => 'İzmir', 'delivery_province_code' => 35, 'status' => 'parsed_partial', 'visibility' => 'private']);
        app(LoadStandardizer::class)->restandardize($load);
        $load->refresh();
        $this->assertSame(['Ankara Yenimahalle', 6, 'parsed_success'], [$load->pickup_location, (int) $load->pickup_province_code, $load->status]);
        $this->assertSame(LoadIntakeService::routeKey('5321112233', 'Ankara Yenimahalle', 'İzmir'), $load->route_key);
        $this->assertSame('5321112233|ankara|izmir', $load->route_key);
    }
}
