<?php

namespace Tests\Feature\Intake;

use App\Models\DriverProfile;
use App\Models\DriverVehicle;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Models\User;
use App\Services\LoadFilterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-10-04 denetimi, 5. paket: şoför tarafı uygunluk. TIR şoförü "kamyonet" yazan ilanı görüyordu (açık araç adı kesin kaynaktır;
 * aynı sınıf ya da bir alt sınıf gösterilir), yükten çıkarılan kasa frigo şoförünü eliyordu (yalnız açıkça yazılan kasa eler).
 */
class DriverRelevanceFilterTest extends TestCase
{
    use RefreshDatabase;

    private DriverProfile $driver;

    private Scraper $source;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::create(['first_name' => 'Şoför', 'last_name' => 'Test', 'email' => 'tir@example.test', 'phone' => '5321111111', 'password' => bcrypt('GucluParola12345'), 'current_role' => 'driver']);
        $this->driver = DriverProfile::create(['user_id' => $user->id, 'kyc_status' => 'approved']);
        DriverVehicle::create(['driver_profile_id' => $this->driver->id, 'plate' => '34TIR123', 'vehicle_type' => 'tir', 'body_type' => 'frigo', 'is_active' => true]);
        $this->source = Scraper::create(['name' => 'G', 'type' => 'notification', 'source_identifier' => 'notif:g', 'is_active' => true]);
    }

    private function load(array $o): ScrapedLoad
    {
        return ScrapedLoad::create(array_merge([
            'scraper_id' => $this->source->id, 'content_hash' => hash('sha256', uniqid('', true)), 'raw_message' => 'Ankara İzmir yük',
            'pickup_location' => 'Ankara', 'pickup_province_code' => 6, 'delivery_location' => 'İzmir', 'delivery_province_code' => 35,
            'status' => 'parsed_success', 'visibility' => 'public',
        ], $o));
    }

    public function test_exact_vehicle_source_narrows_to_same_or_one_class_below_and_inferred_keeps_capacity_rule(): void
    {
        $tirKeyword = $this->load(['vehicle_type' => 'tir', 'vehicle_type_source' => 'keyword']);
        $kirkayakAi = $this->load(['vehicle_type' => 'kirkayak', 'vehicle_type_source' => 'ai']);
        $kamyonetKeyword = $this->load(['vehicle_type' => 'kamyonet', 'vehicle_type_source' => 'keyword']);
        $kamyonetGoods = $this->load(['vehicle_type' => 'kamyonet', 'vehicle_type_source' => 'goods']);
        $kamyonWeight = $this->load(['vehicle_type' => '6_teker_kamyon', 'vehicle_type_source' => 'weight']);
        $kamyonDriver = $this->load(['vehicle_type' => '10_teker_kamyon', 'vehicle_type_source' => 'driver']);
        $unknown = $this->load(['vehicle_type' => null]);

        $ids = app(LoadFilterService::class)->applyToScraped(ScrapedLoad::query(), LoadFilterService::normalize([]), $this->driver)->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$tirKeyword->id, $kirkayakAi->id, $kamyonetGoods->id, $kamyonWeight->id, $unknown->id], $ids);
        $this->assertNotContains($kamyonetKeyword->id, $ids, 'açıkça "kamyonet" yazan ilan TIR şoförüne gösterilmez');
        $this->assertNotContains($kamyonDriver->id, $ids, 'şoförün "10 teker" diye tamamladığı ilan iki sınıf aşağıda');

        $this->assertSame(['tir', 'kirkayak'], LoadFilterService::exactTypesForVehicle('tir'));
        $this->assertSame(['kamyonet', 'panelvan'], LoadFilterService::exactTypesForVehicle('kamyonet'));
        $this->assertSame(['panelvan'], LoadFilterService::exactTypesForVehicle('panelvan'));
        $this->assertCount(7, LoadFilterService::typesForVehicle('tir'));

        // "Tümü" kipinde sınırlama yok.
        $this->assertSame(7, app(LoadFilterService::class)->applyToScraped(ScrapedLoad::query(), LoadFilterService::normalize(['vehicle_mode' => 'any']), $this->driver)->count());
    }

    public function test_body_filter_applies_only_to_explicit_body_sources(): void
    {
        $none = $this->load(['vehicle_type' => 'tir', 'vehicle_type_source' => 'keyword']);
        $frigoKeyword = $this->load(['vehicle_type' => 'tir', 'vehicle_type_source' => 'keyword', 'body_types' => ['frigo'], 'body_type_source' => 'keyword']);
        $tenteliKeyword = $this->load(['vehicle_type' => 'tir', 'vehicle_type_source' => 'keyword', 'body_types' => ['tenteli'], 'body_type_source' => 'keyword']);
        $tenteliGoods = $this->load(['vehicle_type' => 'tir', 'vehicle_type_source' => 'keyword', 'body_types' => ['tenteli', 'kapali'], 'body_type_source' => 'goods']);
        $damperLexicon = $this->load(['vehicle_type' => 'tir', 'vehicle_type_source' => 'keyword', 'body_types' => ['damperli'], 'body_type_source' => 'lexicon']);

        $svc = app(LoadFilterService::class);
        $ids = $svc->applyToScraped(ScrapedLoad::query(), LoadFilterService::normalize([]), $this->driver)->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$none->id, $frigoKeyword->id, $tenteliGoods->id], $ids, 'frigo şoförü: yükten çıkarılan tenteli elemez, açık tenteli/damper eler');

        // Seçtiklerim: damperli seçen şoför açık "tenteli" ilanı görmez ama yükten çıkarılanı görür.
        $ids = $svc->applyToScraped(ScrapedLoad::query(), LoadFilterService::normalize(['vehicle_mode' => 'any', 'body_types' => ['damperli']]), $this->driver)->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$none->id, $tenteliGoods->id, $damperLexicon->id], $ids);
        $this->assertNotContains($tenteliKeyword->id, $ids);
    }
}
