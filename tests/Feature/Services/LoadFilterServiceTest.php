<?php

namespace Tests\Feature\Services;

use App\Models\CargoOwnerProfile;
use App\Models\DriverFilterPreset;
use App\Models\DriverProfile;
use App\Models\DriverVehicle;
use App\Models\Load;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Models\User;
use App\Services\LoadFilterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoadFilterServiceTest extends TestCase
{
    use RefreshDatabase;

    private DriverProfile $driver;

    private CargoOwnerProfile $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $driverUser = User::create(['first_name' => 'Şoför', 'last_name' => 'Test', 'email' => 'd@example.test', 'phone' => '5321111111', 'password' => bcrypt('GucluParola12345'), 'current_role' => 'driver']);
        $this->driver = DriverProfile::create(['user_id' => $driverUser->id, 'kyc_status' => 'approved']);
        DriverVehicle::create(['driver_profile_id' => $this->driver->id, 'plate' => '06KMY123', 'vehicle_type' => 'kamyonet', 'is_active' => true]);
        $ownerUser = User::create(['first_name' => 'Yük', 'last_name' => 'Sahibi', 'email' => 'o@example.test', 'phone' => '5322222222', 'password' => bcrypt('GucluParola12345'), 'current_role' => 'cargo_owner']);
        $this->owner = CargoOwnerProfile::create(['user_id' => $ownerUser->id, 'type' => 'individual']);
    }

    private function load(array $o): Load
    {
        return Load::create(array_merge([
            'cargo_owner_profile_id' => $this->owner->id, 'source_type' => 'internal', 'visibility' => 'public',
            'pickup_location' => 'Ankara Ostim', 'pickup_province_code' => 6, 'pickup_lat' => 39.97, 'pickup_lng' => 32.74,
            'delivery_location' => 'İzmir', 'delivery_province_code' => 35, 'delivery_lat' => 38.42, 'delivery_lng' => 27.14,
            'pickup_date' => now()->addDay(), 'vehicle_type' => 'kamyonet', 'goods_type' => 'palet', 'weight' => 2000, 'price' => 15000,
            'status' => Load::STATUS_ACTIVE, 'escrow_status' => Load::ESCROW_PENDING, 'published_at' => now(),
        ], $o));
    }

    public function test_vehicle_mode_mine_hides_loads_my_vehicle_cannot_carry(): void
    {
        $this->load(['vehicle_type' => 'kamyonet']);
        $this->load(['vehicle_type' => 'minivan']);
        $this->load(['vehicle_type' => 'tir']);

        $svc = app(LoadFilterService::class);
        $mine = $svc->applyToLoads(Load::query(), LoadFilterService::normalize([]), $this->driver)->pluck('vehicle_type')->all();
        $this->assertEqualsCanonicalizing(['kamyonet', 'minivan'], $mine);

        $any = $svc->applyToLoads(Load::query(), LoadFilterService::normalize(['vehicle_mode' => 'any']), $this->driver)->count();
        $this->assertSame(3, $any);

        $custom = $svc->applyToLoads(Load::query(), LoadFilterService::normalize(['vehicle_mode' => 'custom', 'vehicle_types' => ['tir', 'bogus']]), $this->driver)->pluck('vehicle_type')->all();
        $this->assertSame(['tir'], $custom);
    }

    public function test_province_price_weight_and_near_filters(): void
    {
        $ankara = $this->load([]);
        $istanbul = $this->load(['pickup_location' => 'İstanbul Tuzla', 'pickup_province_code' => 34, 'pickup_lat' => 40.82, 'pickup_lng' => 29.30, 'price' => 40000, 'weight' => 3000]);

        $svc = app(LoadFilterService::class);
        $f = fn (array $in) => $svc->applyToLoads(Load::query(), LoadFilterService::normalize($in), $this->driver)->pluck('id')->all();

        $this->assertSame([$istanbul->id], $f(['pickup_provinces' => [34]]));
        $this->assertSame([$ankara->id], $f(['delivery_provinces' => [35], 'min_weight' => 1000, 'max_weight' => 2500]));
        $this->assertSame([$istanbul->id], $f(['min_price' => 20000]));
        // Gebze merkezinden 50 km: Tuzla evet, Ankara hayır
        $this->assertSame([$istanbul->id], $f(['near_radius_km' => 50, 'near_lat' => 40.80, 'near_lng' => 29.43]));
        $this->assertSame([], $f(['near_radius_km' => 10, 'near_lat' => 41.30, 'near_lng' => 36.33]));
        $this->assertSame([$istanbul->id, $ankara->id], $f(['sort' => 'price_desc']));
    }

    public function test_scraped_loads_keep_unknown_vehicle_type_and_filter_by_keywords(): void
    {
        $source = Scraper::create(['name' => 'G', 'type' => 'notification', 'source_identifier' => 'notif:g', 'is_active' => true]);
        $mk = fn (array $o) => ScrapedLoad::create(array_merge([
            'scraper_id' => $source->id, 'content_hash' => hash('sha256', uniqid('', true)), 'raw_message' => 'Ankara İzmir palet yükü',
            'pickup_location' => 'Ankara', 'pickup_province_code' => 6, 'delivery_location' => 'İzmir', 'delivery_province_code' => 35,
            'status' => 'parsed_success', 'visibility' => 'public', 'vehicle_type' => null,
        ], $o));
        $unknown = $mk([]);
        $tir = $mk(['vehicle_type' => 'tir', 'raw_message' => 'Ankara İzmir dökme yük tır']);
        $frigo = $mk(['vehicle_type' => 'kamyonet', 'goods_type' => 'frigo gıda', 'raw_message' => 'soğuk zincir']);

        $svc = app(LoadFilterService::class);
        $ids = $svc->applyToScraped(ScrapedLoad::query(), LoadFilterService::normalize([]), $this->driver)->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$unknown->id, $frigo->id], $ids, 'Tipi bilinmeyen dış kaynak ilanı gizlenmemeli, tır elenmeli');

        $ids = $svc->applyToScraped(ScrapedLoad::query(), LoadFilterService::normalize(['vehicle_mode' => 'any', 'goods_keywords' => 'frigo, dökme']), $this->driver)->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$tir->id, $frigo->id], $ids);
    }

    public function test_body_type_and_load_kind_filters(): void
    {
        // Şoförün aracı: kamyonet, kapalı kasa
        DriverVehicle::query()->where('driver_profile_id', $this->driver->id)->update(['body_type' => 'kapali']);
        $none = $this->load([]);                                             // kasa belirtmemiş: her zaman görünür
        $kapali = $this->load(['body_types' => ['kapali', 'tenteli'], 'load_kind' => 'parca']);
        $frigo = $this->load(['body_types' => ['frigo'], 'load_kind' => 'komple']);
        $svc = app(LoadFilterService::class);

        // "Aracıma uygun": kapalı kasa frigo yükünü görmez
        $ids = $svc->applyToLoads(Load::query(), LoadFilterService::normalize([]), $this->driver)->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$none->id, $kapali->id], $ids);

        // Kasa filtresi (seçtiklerim): frigo → frigo + kasa belirtmeyen
        $ids = $svc->applyToLoads(Load::query(), LoadFilterService::normalize(['vehicle_mode' => 'any', 'body_types' => ['frigo', 'bogus']]), $this->driver)->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$none->id, $frigo->id], $ids);

        // Yük tipi: parça → parça + biçimi bilinmeyen
        $ids = $svc->applyToLoads(Load::query(), LoadFilterService::normalize(['vehicle_mode' => 'any', 'load_kind' => 'parca']), $this->driver)->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$none->id, $kapali->id], $ids);

        $n = LoadFilterService::normalize(['body_types' => ['damperli'], 'load_kind' => 'komple']);
        $this->assertSame(2, LoadFilterService::activeCount($n));
        $this->assertContains('Kasa: Damper', LoadFilterService::chips($n));
        $this->assertContains('Komple yük', LoadFilterService::chips($n));

        // Tır + uzun dorse şoförü: yalnız kısa dorse isteyen ilanı görmez, "13.60" isteyeni görür
        DriverVehicle::query()->where('driver_profile_id', $this->driver->id)->update(['vehicle_type' => 'tir', 'body_type' => 'tenteli', 'trailer_length' => 'uzun']);
        $short = $this->load(['vehicle_type' => 'tir', 'body_types' => ['kisa_dorse', 'tenteli']]);
        $long = $this->load(['vehicle_type' => 'tir', 'body_types' => ['uzun_dorse']]);
        $ids = $svc->applyToLoads(Load::query(), LoadFilterService::normalize([]), $this->driver->fresh())->pluck('id')->all();
        $this->assertContains($long->id, $ids);
        $this->assertContains($kapali->id, $ids, 'tenteli listede: uygun');
        $this->assertNotContains($short->id, $ids);
        $this->assertNotContains($frigo->id, $ids);
    }

    public function test_district_filters_narrow_within_selected_provinces(): void
    {
        $ceyhan = $this->load(['pickup_location' => 'Adana Ceyhan', 'pickup_province_code' => 1, 'pickup_district' => 'Ceyhan']);
        $kozan = $this->load(['pickup_location' => 'Adana Kozan', 'pickup_province_code' => 1, 'pickup_district' => 'Kozan']);
        $adana = $this->load(['pickup_location' => 'Adana', 'pickup_province_code' => 1, 'pickup_district' => null]);
        $aydin = $this->load(['pickup_location' => 'Aydın Nazilli', 'pickup_province_code' => 9, 'pickup_district' => 'Nazilli']);
        $svc = app(LoadFilterService::class);
        $ids = fn (array $f) => $svc->applyToLoads(Load::query(), LoadFilterService::normalize($f + ['vehicle_mode' => 'any']), $this->driver)->pluck('id')->all();

        // İl seçili, ilçe yok: ilin tamamı
        $this->assertEqualsCanonicalizing([$ceyhan->id, $kozan->id, $adana->id], $ids(['pickup_provinces' => [1]]));
        // Adana yalnız Ceyhan + Aydın tamamı
        $this->assertEqualsCanonicalizing([$ceyhan->id, $aydin->id], $ids(['pickup_provinces' => [1, 9], 'pickup_districts' => [1 => ['Ceyhan']]]));
        // Seçili olmayan ilin ilçesi ve katalogda olmayan ilçe atılır
        $n = LoadFilterService::normalize(['pickup_provinces' => [1], 'pickup_districts' => [1 => ['Ceyhan', 'Uydurma'], 9 => ['Nazilli']]]);
        $this->assertSame([1 => ['Ceyhan']], $n['pickup_districts']);
        $this->assertContains('Çıkış: Adana (Ceyhan)', LoadFilterService::chips($n));
        $this->assertSame('Her yer', LoadFilterService::placesLabel([]));
        $this->assertSame('Adana (Ceyhan, Kozan), Aydın', LoadFilterService::placesLabel([1, 9], [1 => ['Ceyhan', 'Kozan']]));
    }

    public function test_normalize_and_presets(): void
    {
        $n = LoadFilterService::normalize(['vehicle_mode' => 'weird', 'pickup_provinces' => ['34', 99, 'x'], 'near_radius_km' => 50, 'sort' => 'distance', 'min_weight' => '-5']);
        $this->assertSame('mine', $n['vehicle_mode']);
        $this->assertSame([34], $n['pickup_provinces']);
        $this->assertNull($n['near_radius_km'], 'Koordinat yoksa yakınımda kapanmalı');
        $this->assertSame('newest', $n['sort'], 'Yakınımda kapalıyken mesafe sıralaması düşmeli');
        $this->assertSame(0, $n['min_weight']);
        $this->assertSame(0, LoadFilterService::activeCount(LoadFilterService::normalize([])));
        $this->assertContains('Çıkış: İstanbul', LoadFilterService::chips($n));

        $preset = DriverFilterPreset::create(['driver_profile_id' => $this->driver->id, 'name' => 'İstanbul çıkışlı', 'is_default' => true, 'filters' => $n]);
        $this->assertSame([34], $this->driver->filterPresets()->first()->filters['pickup_provinces']);
        $this->assertTrue($preset->is_default);
    }
}
