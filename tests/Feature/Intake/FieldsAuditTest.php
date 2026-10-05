<?php

namespace Tests\Feature\Intake;

use App\Models\DriverProfile;
use App\Models\DriverVehicle;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Models\User;
use App\Services\AiParserService;
use App\Services\LoadFilterService;
use App\Services\LoadStandardizer;
use App\Support\Lexicon;
use App\Support\Settings;
use App\Support\TurkishLocations;
use App\Support\VehicleClassifier;
use App\Support\VehicleTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** 2026-10-05 ilan hattı denetimi (alan çıkarımı, yapay zeka katmanı, şoför eşleşmesi): her test bir bulgunun kapandığını doğrular. */
class FieldsAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Lexicon::flush();
        Http::preventStrayRequests();
        Settings::set('ai_parse_mode', 'off');
    }

    private function std(string $text): array
    {
        $parsed = app(AiParserService::class)->parseCheap($text);

        return app(LoadStandardizer::class)->standardize($text, $parsed) + ['_parsed' => $parsed];
    }

    public function test_short_province_names_do_not_swallow_everyday_words(): void
    {
        $p = app(AiParserService::class)->parseCheap('Gebze 24 ton palet karşı ödemeli Konya 0532 000 00 03');
        $this->assertSame(['Kocaeli Gebze', 'Konya'], [$p['pickup_location'], $p['delivery_location']]);
        $this->assertSame([], array_column(AiParserService::placesIn('Musa 0532 000 00 03'), 'label'));
        $this->assertSame([], array_column(AiParserService::placesIn('1 adet vana 0532 000 00 03'), 'label'));
        $p = app(AiParserService::class)->parseCheap('Gebze Bursa 1 ton koli panel van 0532 000 00 03');
        $this->assertSame(['Kocaeli Gebze', 'Bursa'], [$p['pickup_location'], $p['delivery_location']]);
        $this->assertSame('Van', TurkishLocations::resolve('Vandan')['province']);
    }

    public function test_generic_kamyon_without_tonnage_is_a_soft_guess_visible_to_six_wheel_drivers(): void
    {
        $user = User::create(['first_name' => 'Altı', 'last_name' => 'Teker', 'email' => 'alti@example.test', 'phone' => '5320000001', 'password' => bcrypt('GucluParola12345'), 'current_role' => 'driver']);
        $driver = DriverProfile::create(['user_id' => $user->id, 'kyc_status' => 'approved']);
        DriverVehicle::create(['driver_profile_id' => $driver->id, 'plate' => '06ALT123', 'vehicle_type' => '6_teker_kamyon', 'is_active' => true]);
        $source = Scraper::create(['name' => 'G', 'type' => 'notification', 'source_identifier' => 'notif:g', 'is_active' => true]);
        $std = $this->std('Ankara - İzmir koli kamyon lazım 0532 000 00 04');
        $this->assertSame(['6_teker_kamyon', 'hint'], [$std['vehicle_type'], $std['vehicle_type_source']]);
        $load = ScrapedLoad::create(['scraper_id' => $source->id, 'content_hash' => hash('sha256', 'x'), 'raw_message' => 'Ankara - İzmir koli kamyon lazım 0532 000 00 04',
            'pickup_location' => 'Ankara', 'pickup_province_code' => 6, 'delivery_location' => 'İzmir', 'delivery_province_code' => 35,
            'status' => 'parsed_success', 'visibility' => 'public', 'vehicle_type' => $std['vehicle_type'], 'vehicle_type_source' => $std['vehicle_type_source']]);
        $ids = app(LoadFilterService::class)->applyToScraped(ScrapedLoad::query(), LoadFilterService::normalize([]), $driver)->pluck('id')->all();
        $this->assertContains($load->id, $ids);
        $withTon = $this->std('Ankara - İzmir 10 ton koli kamyon 0532 000 00 04');
        $this->assertSame(['8_teker_kamyon', 'keyword'], [$withTon['vehicle_type'], $withTon['vehicle_type_source']]);
    }

    public function test_body_word_with_truck_tonnage_is_a_truck_guess_not_exact_tir(): void
    {
        $a = $this->std('Ankara - İzmir 10 ton tenteli yük 0532 000 00 03');
        $this->assertSame(['8_teker_kamyon', 'hint', ['tenteli']], [$a['vehicle_type'], $a['vehicle_type_source'], $a['body_types']]);
        $b = $this->std('Ankara - İzmir 8 ton frigo yük 0532 000 00 03');
        $this->assertSame(['6_teker_kamyon', 'hint'], [$b['vehicle_type'], $b['vehicle_type_source']]);
        $c = $this->std('Ankara - İzmir 24 ton tenteli 0532 000 00 03');
        $this->assertSame(['tir', 'keyword'], [$c['vehicle_type'], $c['vehicle_type_source']]);
        $this->assertSame(['tir', 'keyword'], [$this->std('Ankara - İzmir tenteli yük 0532 000 00 03')['vehicle_type'], $this->std('Ankara - İzmir tenteli yük 0532 000 00 03')['vehicle_type_source']]);
    }

    public function test_phone_digits_do_not_leak_into_vehicle_body_or_count(): void
    {
        $std = $this->std('Ankara Sincan - İzmir Bornova 10 ton mobilya kamyon lazım 0532 000 13 60');
        $this->assertNotSame('tir', $std['vehicle_type']);
        $this->assertNotContains('uzun_dorse', (array) $std['body_types']);
        $this->assertNotSame('10_teker_kamyon', $this->std('Ankara - Konya 2 ton koli 0532 860 00 03')['vehicle_type']);
        $this->assertNull($this->std("Ankara - Konya 20 ton saman\n0532 000 00 02\nTIR LAZIM")['vehicle_count']);
    }

    public function test_volume_only_text_yields_a_valid_vehicle_key(): void
    {
        $this->assertTrue(VehicleTypes::isValid(VehicleClassifier::analyze('10 metreküp yük var, araç lazım')['type']));
        $this->assertTrue(VehicleTypes::isValid($this->std('Ankara - İzmir 10 metreküp yük var 0532 000 00 03')['vehicle_type']));
    }

    public function test_acik_adres_and_yedek_parca_are_not_body_or_load_kind(): void
    {
        $std = $this->std('Kocaeli Gebze - Ankara 20 palet gıda, açık adres whatsapptan 0532 000 00 03');
        $this->assertNotContains('acik', (array) $std['body_types']);
        $this->assertContains('acik', (array) $this->std('Ankara - İzmir 20 ton açık kasa 0532 000 00 03')['body_types']);
        $this->assertSame('komple', $this->std('Bursa - Ankara 24 ton oto yedek parça komple tır 0532 000 00 03')['load_kind']);
    }

    public function test_place_names_are_not_goods(): void
    {
        $this->assertSame('Tekstil', $this->std('İstanbul Zeytinburnu - Ankara 24 ton tekstil tır 0532 000 00 03')['goods_type']);
        $this->assertNull($this->std('Adana Yumurtalık - Konya 26 ton tır 0532 000 00 03')['goods_type']);
        $this->assertSame('Mobilya / ev eşyası', $this->std('Ankara Elmadağ - Bursa 24 ton mobilya tır 0532 000 00 03')['goods_type']);
        $this->assertNull($this->std('Sakarya Pamukova - Ankara 15 ton 10 teker 0532 000 00 03')['goods_type']);
    }

    public function test_per_ton_price_without_tl_and_weight_caps(): void
    {
        $a = $this->std('Mersin - Konya dökme tuz 26 ton tonu 950 damperli 0532 000 00 03');
        $b = $this->std('Mersin - Konya dökme tuz 26 ton 950/ton damperli 0532 000 00 03');
        $this->assertSame([[950.0, 'per_ton'], [950.0, 'per_ton']], [[$a['price'], $a['price_unit']], [$b['price'], $b['price_unit']]]);
        $this->assertNull($this->std('Ankara - İzmir 24.500 ton saman tır 0532 000 00 03')['weight']);
        $this->assertSame(24000, LoadStandardizer::plausibleWeight(24));
        $this->assertSame(24000, LoadStandardizer::plausibleWeight(24000));
        $this->assertNull(LoadStandardizer::plausibleWeight(24500000));
    }

    public function test_iskenderun_keeps_district_and_coordinates(): void
    {
        $r = TurkishLocations::resolve('İskenderun');
        $this->assertSame(['Hatay', 'İskenderun'], [$r['province'], $r['district']]);
        $this->assertEqualsWithDelta(36.59, $r['lat'], 0.15);
        $this->assertSame('Hatay', TurkishLocations::resolve('Antakya')['province']);
    }

    public function test_driver_confirmed_vehicle_survives_restandardize(): void
    {
        $source = Scraper::create(['name' => 'G', 'type' => 'notification', 'source_identifier' => 'notif:g', 'is_active' => true]);
        $base = ['scraper_id' => $source->id, 'pickup_location' => 'Ankara', 'pickup_province_code' => 6, 'delivery_location' => 'İzmir', 'delivery_province_code' => 35,
            'status' => 'parsed_success', 'visibility' => 'public', 'is_incomplete' => false, 'completed_by' => 'driver', 'vehicle_type_source' => 'driver'];
        $typed = ScrapedLoad::create($base + ['content_hash' => hash('sha256', 'a'), 'raw_message' => 'Ankara - İzmir 12 ton tenteli yük 0532 000 00 03', 'vehicle_type' => '10_teker_kamyon']);
        $any = ScrapedLoad::create($base + ['content_hash' => hash('sha256', 'b'), 'raw_message' => 'Ankara - İzmir 12 ton saman yükü 0532 000 00 03', 'vehicle_type' => null, 'vehicle_any' => true]);
        app(LoadStandardizer::class)->restandardize($typed);
        app(LoadStandardizer::class)->restandardize($any);
        $this->assertSame(['10_teker_kamyon', 'driver'], [$typed->fresh()->vehicle_type, $typed->fresh()->vehicle_type_source]);
        $this->assertSame([null, true, 'driver'], [$any->fresh()->vehicle_type, $any->fresh()->vehicle_any, $any->fresh()->vehicle_type_source]);
    }

    private function aiOn(): void
    {
        Settings::set('ai_parse_mode', 'fill_gaps');
        Settings::set('ai_groq_key', 'gsk-test');
        Settings::set('ai_groq_model', 'llama-test');
        Settings::set('ai_provider', 'groq');
    }

    private function groq(array $payload): array
    {
        return ['choices' => [['message' => ['content' => json_encode($payload)]]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10]];
    }

    public function test_percent_scale_confidence_is_normalised(): void
    {
        $this->aiOn();
        Http::fake(['api.groq.com/*' => Http::response($this->groq(['post_type' => 'other', 'confidence' => 85, 'notes' => null, 'ads' => []]))]);
        $r = app(AiParserService::class)->enrich('Ankara Ostimden Bursaya 3 palet lazım 0532 000 00 03');
        $this->assertSame([false, 0.85], [$r['data']['is_load'], $r['data']['confidence']]);
        $this->assertNull(AiParserService::normalizeConfidence(250));
        $this->assertSame(0.9, AiParserService::normalizeConfidence('0.9'));
    }

    public function test_manual_reparse_bypasses_result_cache_and_key_carries_prompt_version(): void
    {
        $this->aiOn();
        Http::fake(['api.groq.com/*' => Http::response($this->groq(['post_type' => 'load', 'confidence' => 0.9, 'notes' => null, 'ads' => [['post_type' => 'load', 'confidence' => 0.9, 'phones' => ['5320000003'], 'pickup' => ['province' => 'Ankara', 'district' => null], 'delivery' => ['province' => 'Bursa', 'district' => null]]]]))]);
        $svc = app(AiParserService::class);
        $msg = 'Ankara Ostimden Bursaya 3 palet lazım 0532 000 00 03';
        $svc->enrich($msg);
        $this->assertArrayHasKey('cached', $svc->enrich($msg));
        $again = $svc->enrich($msg, [], manual: true);
        Http::assertSentCount(2);
        $this->assertArrayNotHasKey('cached', $again);
        $this->assertStringContainsString(AiParserService::PROMPT_VERSION, AiParserService::resultCacheKey($msg));
    }

    public function test_ai_flexible_vehicle_guess_is_not_exact_and_ai_weight_in_tons_is_scaled(): void
    {
        $this->aiOn();
        Http::fake(['api.groq.com/*' => Http::response($this->groq(['post_type' => 'load', 'confidence' => 0.9, 'notes' => null, 'ads' => [['post_type' => 'load', 'confidence' => 0.9, 'phones' => ['5320000003'], 'pickup' => ['province' => 'Ankara', 'district' => null], 'delivery' => ['province' => 'Bursa', 'district' => null], 'weight_kg' => 24, 'vehicle_type' => 'kamyonet', 'vehicle_flexible' => true]]]))]);
        $text = 'Ankaradan Bursaya yük var 0532 000 00 03';
        $svc = app(AiParserService::class);
        $parsed = $svc->parseCheap($text);
        $ai = $svc->enrich($text, $parsed);
        $merged = $svc->merge($parsed, AiParserService::pickAd($ai['data'], '5320000003'));
        $this->assertSame('ai_guess', $merged['vehicle_type_source']);
        $std = app(LoadStandardizer::class)->standardize($text, $merged);
        $this->assertSame([24000, 'kamyonet', 'ai_guess'], [$std['weight'], $std['vehicle_type'], $std['vehicle_type_source']]);
        $this->assertNotContains('ai_guess', LoadFilterService::EXACT_VEHICLE_SOURCES);
    }
}
