<?php

namespace Tests\Feature\Services;

use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Models\User;
use App\Services\AiParserService;
use App\Services\LoadIntakeService;
use App\Services\LoadStandardizer;
use App\Services\ScrapedLoadService;
use App\Support\Settings;
use App\Support\TurkishLocations;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
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
        $this->assertSame('panelvan', $std['vehicle_type']); // yükten çıkarım: panelvan ve üzeri
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

    public function test_any_vehicle_phrase_leaves_type_empty_and_is_not_a_blocker(): void
    {
        $s = app(LoadStandardizer::class);
        $std = $s->standardize('Bergama yükleme Kınık boşaltma 12 ton palet araç fark etmez 0532 123 45 67', ['pickup_location' => 'Bergama', 'delivery_location' => 'Kınık']);
        $this->assertTrue($std['vehicle_any']);
        $this->assertNull($std['vehicle_type'], 'Her araca açık ilanda tip boş kalır; yükten çıkarım yapılmaz');
        $this->assertNotContains('vehicle_unresolved', $std['warnings']);

        // Açık araç adı varsa "fark etmez" onu ezmez; kasa için söylenen "tente frigo fark etmez" araç kalıbı değildir
        $std = $s->standardize('Tenteli tır Bursa İzmir 24 ton her türlü araç olur', ['pickup_location' => 'Bursa', 'delivery_location' => 'İzmir']);
        $this->assertSame('tir', $std['vehicle_type']);
        $this->assertFalse($std['vehicle_any']);
        $std = $s->standardize('Bursa İzmir 3 palet tente frigo fark etmez', ['pickup_location' => 'Bursa', 'delivery_location' => 'İzmir']);
        $this->assertFalse($std['vehicle_any']);

        // Yönetici "Fark etmez (her araç)" seçince: araç tipi boş, otomatik onay "araç tipi yok" demez, kartta etiket var
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['current_role' => 'admin']);
        $admin->syncRoles(['super_admin']);
        Scraper::create(['name' => 'Grup', 'type' => 'notification', 'source_identifier' => 'notif:grup', 'is_active' => true]);
        $load = ScrapedLoad::create(['scraper_id' => 1, 'content_hash' => 'any1', 'raw_message' => 'Bergama Kınık 12 ton 0532 123 45 67', 'sender_phone' => '05321234567', 'pickup_location' => 'İzmir / Bergama', 'pickup_province_code' => 35, 'delivery_location' => 'İzmir / Kınık', 'delivery_province_code' => 35, 'status' => 'parsed_success', 'visibility' => 'private', 'weight' => 12000, 'retention_expires_at' => now()->addDays(30)]);
        Settings::set('scraper_auto_approve', '1');
        Settings::set('scraper_auto_approve_require_vehicle', '1');
        $this->assertSame('araç tipi yok', app(ScrapedLoadService::class)->autoApprovalBlocker($load));
        $this->actingAs($admin->fresh());
        Volt::test('admin.scrapers-center')->call('startEdit', $load->id)->assertSet('edit.vehicle_type', '')
            ->set('edit.vehicle_type', 'any')->call('saveEdit')->assertHasNoErrors()->assertSee('Araç fark etmez');
        $load->refresh();
        $this->assertTrue($load->vehicle_any);
        $this->assertNull($load->vehicle_type);
        $this->assertSame('admin', $load->vehicle_type_source);
        $this->assertSame('Araç fark etmez', $load->vehicleLabel());
        $this->assertNotSame('araç tipi yok', app(ScrapedLoadService::class)->autoApprovalBlocker($load));
        Volt::test('admin.scrapers-center')->call('startEdit', $load->id)->assertSet('edit.vehicle_type', 'any');
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
        $this->assertSame('panelvan', $load->vehicle_type);
        $this->assertSame('goods', $load->vehicle_type_source);
        $this->assertSame('Panelvan ve üzeri', $load->vehicleLabel());
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
        // Yönetici alanları korunur; yalnız yeni eklenen kasa/yük biçimi boşsa doldurulur (bir kez)
        $this->assertTrue(app(LoadStandardizer::class)->restandardize($load));
        $this->assertSame('tir', $load->fresh()->vehicle_type);
        $this->assertSame('Tekstil', $load->fresh()->goods_type);
        $this->assertSame(['tenteli', 'kapali'], $load->fresh()->body_types); // tekstil: yükten çıkarım
        $load->forceFill(['goods_type' => 'MERMER BLOK ‼️'])->save(); // yönetici ne yazarsa yazsın kayıt cümle biçiminde
        $this->assertSame('Mermer blok', $load->fresh()->goods_type);
        $this->assertFalse(app(LoadStandardizer::class)->restandardize($load->fresh()));

        $this->assertSame('Bursa → İzmir', $load->fresh()->routeLabel());
        $this->assertSame('10 ton', $load->fresh()->weightLabel());
        $this->assertSame('TIR', $load->fresh()->vehicleLabel());
    }

    public function test_per_ton_prices_are_detected_and_labelled_separately(): void
    {
        $parser = app(AiParserService::class);
        $bulk = $parser->parseCheap('Yarın yükleme sarıgöl bölgesinden dinar kısa damper dorse tırlar Dökme üzüm 1000+kdv 05321765518');
        $this->assertSame([1000.0, 'per_ton'], [$bulk['price'], $bulk['price_unit']]);
        $explicit = $parser->parseCheap('Ankara - İzmir 24 ton hububat ton başı 1.250 tl tenteli 0532 123 45 67');
        $this->assertSame([1250.0, 'per_ton'], [$explicit['price'], $explicit['price_unit']]);
        $basar = $parser->parseCheap('Mersin - Konya kömür 950+basar damperli 0532 123 45 67');
        $this->assertSame([950.0, 'per_ton'], [$basar['price'], $basar['price_unit']]);
        $total = $parser->parseCheap('Bursa - Konya 10 palet tekstil 28+kdv tenteli 0532 123 45 67');
        $this->assertSame([28000.0, 'total'], [$total['price'], $total['price_unit']]);
        $plain = $parser->parseCheap('Bursa - Konya 24 ton üzüm 45.000 tl tenteli 0532 123 45 67');
        $this->assertSame([45000.0, 'total'], [$plain['price'], $plain['price_unit']]);

        $std = app(LoadStandardizer::class)->standardize('Mersin - Konya kömür 950+basar damperli 0532 123 45 67', $basar);
        $this->assertSame(['per_ton', 'TRY'], [$std['price_unit'], $std['currency']]);

        config()->set('services.scraper.token', 't');
        Scraper::create(['name' => 'Grup', 'type' => 'notification', 'source_identifier' => 'notif:grup', 'is_active' => true]);
        $r = app(LoadIntakeService::class)->intake(['group_name' => 'Grup', 'source_jid' => 'notif:grup', 'source_type' => 'notification',
            'raw_message' => 'Mersin - Konya kömür 950+basar damperli 0532 123 45 67', 'sender_phone' => null, 'message_id' => 'pt1']);
        $this->assertSame('created', $r['status']);
        $load = ScrapedLoad::first();
        $this->assertSame('950 ₺/ton', $load->priceLabel());
        $this->assertTrue($load->isPerTon());

        $load->forceFill(['price' => 1200.5, 'currency' => 'USD', 'price_unit' => 'total'])->save();
        $this->assertSame('1.200,50 $', $load->fresh()->priceLabel());
        $load->forceFill(['price' => null])->save();
        $this->assertNull($load->fresh()->priceLabel());
    }

    public function test_body_types_load_kind_and_backfill_of_existing_records(): void
    {
        $std = app(LoadStandardizer::class);
        $r = $std->standardize('Denizli İzmir dökme yük fakat damper ile 13.60 açık sal dorse uygundur 26 ton 0532 111 11 11', []);
        $this->assertSame(['acik', 'damperli', 'uzun_dorse'], $r['body_types']);
        $this->assertSame('keyword', $r['body_type_source']);
        $this->assertSame('komple', $r['load_kind']);

        // Emoji yük + "dökme" → damper; "kasalı" → kapalı/tenteli/frigo; yapay zeka yalnız kural boşken
        $this->assertSame(['damperli'], $std->standardize('Buldan/Antalya Dökme 🍇🍇🍇 vardır 0532 111 11 11', [])['body_types']);
        $this->assertSame(['tenteli', 'kapali', 'frigo'], $std->standardize('Buldan/Antalya 🍇🍇🍇 kasalı vardır 0532 111 11 11', [])['body_types']);
        $this->assertSame(['damperli'], $std->standardize('Denizli İzmir 🦴🦴 kemik yükümüz vardır 0532 111 11 11', [])['body_types']);
        // Yük türü ve yer adları tek biçimde: büyük harf yığını cümle/sözcük biçimine iner (Türkçe İ/ı doğru)
        $r = $std->standardize('ANKARA İZMİR 24 TON 0532 111 11 11', array_merge(app(AiParserService::class)->parseCheap('ANKARA İZMİR 24 TON 0532 111 11 11'), ['goods_type' => 'İZOLASYON MALZEMESİ']));
        $this->assertSame('İzolasyon malzemesi', $r['goods_type']);
        $this->assertSame(['Ankara', 'İzmir'], [$r['pickup_location'], $r['delivery_location']]);
        $r = $std->standardize('Ankara İzmir 24 ton 0532 111 11 11', ['body_types' => ['frigo'], 'load_kind' => 'parca']);
        $this->assertSame(['frigo'], $r['body_types']);
        $this->assertSame('ai', $r['body_type_source']);
        $this->assertSame('komple', $r['load_kind']); // 24 ton: kural komple der, yapay zeka bunu değiştiremez
        $r = $std->standardize('Ankara İzmir tenteli 24 ton 0532 111 11 11', ['body_types' => ['frigo']]);
        $this->assertSame(['tenteli'], $r['body_types']); // açık kasa sözcüğü yapay zekaya üstün

        // Yayındaki eski kayıt: kasa/biçim boş → classify komutu doldurur; yönetici düzenlemiş kayıtta yalnız boş alanlar
        Scraper::create(['name' => 'Grup', 'type' => 'notification', 'source_identifier' => 'notif:grup', 'is_active' => true]);
        $old = ScrapedLoad::create([
            'scraper_id' => 1, 'content_hash' => 'h1', 'raw_message' => 'Ankara İzmir 13.60 tenteli 24 ton 0532 111 11 11', 'sender_phone' => null,
            'pickup_location' => 'Ankara', 'pickup_province_code' => 6, 'delivery_location' => 'İzmir', 'delivery_province_code' => 35,
            'vehicle_type' => 'tir', 'vehicle_type_source' => 'keyword', 'weight' => 24000, 'status' => 'parsed_success', 'visibility' => 'public',
            'parse_metadata' => ['admin_edited' => true, 'urgent' => false], 'retention_expires_at' => now()->addDays(30),
        ]);
        $this->artisan('scraped-loads:classify')->expectsOutputToContain('güncellenen: 1');
        $old->refresh();
        $this->assertSame(['tenteli', 'uzun_dorse'], $old->body_types);
        $this->assertSame('komple', $old->load_kind);
        $this->assertSame('13.60 · Tenteli', $old->bodyLabel());
        $this->assertSame('TIR · 13.60 · Tenteli · Komple yük', $old->vehicleSummary());
        $this->assertTrue($old->meta('admin_edited'));
        $this->artisan('scraped-loads:classify')->expectsOutputToContain('güncellenen: 0');
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
