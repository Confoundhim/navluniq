<?php

namespace Tests\Feature\Services;

use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Services\AiParserService;
use App\Services\LoadIntakeService;
use App\Services\LoadStandardizer;
use App\Services\ScrapedLoadService;
use App\Support\TurkishLocations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LoadStandardizerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::fake();
    }

    public function test_typos_in_provinces_are_corrected_and_goods_vehicle_price_are_standardized(): void
    {
        $std = app(LoadStandardizer::class)->standardize(
            'Diyarbakr dan İstanbl Kartala 3 buzdolabı gidecek acil 45 bin 0532 123 45 67',
            ['pickup_location' => 'Diyarbakr', 'delivery_location' => 'İstanbl Kartala', 'goods_type' => null, 'weight' => null, 'price' => null]
        );

        $this->assertSame('Diyarbakır', $std['pickup_location']);
        $this->assertSame(21, $std['pickup_province_code']);
        $this->assertSame('İstanbul Kartal', $std['delivery_location']);
        $this->assertSame(34, $std['delivery_province_code']);
        $this->assertSame('Kartal', $std['delivery_district']);
        $this->assertSame('Beyaz eşya', $std['goods_type']);
        $this->assertSame('orta_panelvan', $std['vehicle_type']); // yükten çıkarım: panelvan ve üzeri
        $this->assertSame('goods', $std['vehicle_type_source']);
        $this->assertSame(45000.0, $std['price']);
        $this->assertTrue($std['metadata']['urgent']);
        $this->assertContains('vehicle_inferred', $std['warnings']);
        $this->assertContains('fragile', $std['metadata']['goods_traits']);
    }

    public function test_explicit_vehicle_beats_ai_and_unknown_place_is_flagged(): void
    {
        $s = app(LoadStandardizer::class);
        $std = $s->standardize('Tenteli tır lazım Ankara Bilinmeyenköy 24 ton', ['pickup_location' => 'Ankara', 'delivery_location' => 'Bilinmeyenköy', 'vehicle_type' => 'kamyonet']);
        $this->assertSame('tir', $std['vehicle_type']);
        $this->assertSame('keyword', $std['vehicle_type_source']);
        $this->assertContains('delivery_unresolved', $std['warnings']);
        $this->assertSame('Bilinmeyenköy', $std['delivery_location']);

        $std = $s->standardize('Koli var Bursa İzmir', ['pickup_location' => 'Bursa', 'delivery_location' => 'İzmir', 'vehicle_type' => 'kamyonet']);
        $this->assertSame('kamyonet', $std['vehicle_type']); // yapay zeka, zayıf çıkarıma üstün
        $this->assertSame('ai', $std['vehicle_type_source']);

        $this->assertSame(45000.0, $s->priceFromText(' 45bin tl '));
        $this->assertSame(45000.0, $s->priceFromText(' fiyat: 45.000 '));
        $this->assertSame(1250.5, $s->priceFromText(' 1.250,50 tl '));
        $this->assertNull($s->priceFromText(' 24 ton 0532 123 45 67 '));
    }

    public function test_intake_stores_standardized_fields_and_goods_based_vehicle(): void
    {
        config()->set('services.scraper.token', 't');
        Scraper::create(['name' => 'Grup', 'type' => 'notification', 'source_identifier' => 'notif:grup', 'is_active' => true]);

        $r = app(LoadIntakeService::class)->intake([
            'group_name' => 'Grup', 'source_jid' => 'notif:grup', 'source_type' => 'notification',
            'raw_message' => 'Diyarbakr dan Ankaraya 2 adet buzdolabı ve çamaşır makinesi gidecek 0532 123 45 67 yarın',
            'sender_phone' => null, 'message_id' => 'm1',
        ]);
        $this->assertSame('created', $r['status']);

        $load = ScrapedLoad::first();
        $this->assertSame('Diyarbakır', $load->pickup_location);
        $this->assertSame('Ankara', $load->delivery_location);
        $this->assertSame(21, $load->pickup_province_code);
        $this->assertSame('Beyaz eşya', $load->goods_type);
        $this->assertSame('orta_panelvan', $load->vehicle_type);
        $this->assertSame('goods', $load->vehicle_type_source);
        $this->assertSame('Orta Panelvan ve üzeri', $load->vehicleLabel());
        $this->assertSame('Yarın', $load->meta('pickup_note'));
        $this->assertSame('parsed_success', $load->status);
    }

    public function test_approval_restandardizes_old_records_and_requires_provinces(): void
    {
        $scraper = Scraper::create(['name' => 'Grup', 'type' => 'notification', 'source_identifier' => 'notif:grup', 'is_active' => true]);
        $old = ScrapedLoad::create([
            'scraper_id' => $scraper->id, 'raw_message' => 'Diyarbakr dan İzmire 24 ton palet tenteli lazım 0532 123 45 67',
            'pickup_location' => 'Diyarbakr', 'delivery_location' => 'İzmire', 'weight' => 24000,
            'status' => 'parsed_partial', 'visibility' => 'private', 'message_id' => 'x',
        ]);

        app(ScrapedLoadService::class)->approve($old, null);
        $old->refresh();
        $this->assertSame('public', $old->visibility);
        $this->assertSame('Diyarbakır', $old->pickup_location);
        $this->assertSame('İzmir', $old->delivery_location);
        $this->assertSame('tir', $old->vehicle_type);
        $this->assertSame('Paletli yük', $old->goods_type);
        $this->assertSame('24 ton', $old->weightLabel());

        $bad = ScrapedLoad::create([
            'scraper_id' => $scraper->id, 'raw_message' => 'Nereden nereye belli değil 0532 123 45 67',
            'pickup_location' => 'Buradan', 'delivery_location' => 'Oraya', 'status' => 'parsed_partial', 'visibility' => 'private', 'message_id' => 'y',
        ]);
        $this->expectException(\RuntimeException::class);
        app(ScrapedLoadService::class)->approve($bad, null);
    }

    public function test_admin_edits_are_preserved_on_restandardize_and_labels_are_standard(): void
    {
        $scraper = Scraper::create(['name' => 'Grup', 'type' => 'notification', 'source_identifier' => 'notif:grup', 'is_active' => true]);
        $load = ScrapedLoad::create([
            'scraper_id' => $scraper->id, 'raw_message' => 'Bursa İzmir 10 ton tekstil kamyon 0532 123 45 67 45 bin',
            'pickup_location' => 'Bursa', 'delivery_location' => 'İzmir', 'pickup_province_code' => 16, 'delivery_province_code' => 35,
            'vehicle_type' => 'tir', 'vehicle_type_source' => 'admin', 'goods_type' => 'Tekstil', 'weight' => 10000, 'price' => 45000,
            'status' => 'parsed_success', 'visibility' => 'private', 'message_id' => 'z', 'parse_metadata' => ['admin_edited' => true],
            'encrypted_sender_phone' => Crypt::encryptString('5321234567'),
        ]);
        $this->assertFalse(app(LoadStandardizer::class)->restandardize($load));
        $this->assertSame('tir', $load->fresh()->vehicle_type);

        $this->assertSame('Bursa → İzmir', $load->fresh()->routeLabel());
        $this->assertSame('10 ton', $load->fresh()->weightLabel());
        $this->assertSame('TIR', $load->fresh()->vehicleLabel());
    }

    public function test_short_province_names_resolve_to_canonical_province(): void
    {
        $this->assertSame(3, TurkishLocations::resolve('Afyon')['province_code']);
        $this->assertSame('Afyonkarahisar', TurkishLocations::resolve('Afyonkarahisar')['province']);
        $this->assertSame(46, TurkishLocations::resolve('Maraş')['province_code']);

        $parsed = app(AiParserService::class)->parseCheap("Denizli'den Afyon'a 3 adet kapalı tır ihtiyaç nakliyesi dolgundur cep numarası 0545 391 68 33");
        $this->assertSame(['Denizli', 'Afyonkarahisar', '5453916833'], [$parsed['pickup_location'], $parsed['delivery_location'], $parsed['sender_phone']]);
        $std = app(LoadStandardizer::class)->standardize('x', $parsed);
        $this->assertSame([20, 3, []], [$std['pickup_province_code'], $std['delivery_province_code'], (array) ($std['metadata']['warnings'] ?? [])]);
    }
}
