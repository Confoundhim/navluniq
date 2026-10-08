<?php

namespace Tests\Feature\Driver;

use App\Models\DriverProfile;
use App\Models\DriverSavedLoad;
use App\Models\DriverVehicle;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Models\User;
use App\Services\LoadFilterService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Dış kaynak modülü yeni filtre çubuğu (2026-10-08): hızlı çipler, çıkış ⇄ varış, rozet kaldırma, dorse boyu / acil / tazelik / yük
 * kategorisi / en çok fiyat süzgeçleri, ton başı fiyata duyarlı fiyat süzgeci ve sıralama, kaydedilenlerde yayından kalkan ilan.
 */
class LoadFilterBarTest extends TestCase
{
    use RefreshDatabase;

    private Scraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->scraper = Scraper::create(['name' => 'Grup A', 'type' => 'notification', 'source_identifier' => 'notif:grup-a', 'is_active' => true]);
    }

    private function driver(): User
    {
        $user = User::factory()->driver()->create();
        $user->syncRoles(['driver']);
        $profile = DriverProfile::create(['user_id' => $user->id, 'kyc_status' => 'approved', 'kyc_verified_at' => now(), 'premium_until' => now()->addMonth()]);
        DriverVehicle::create(['driver_profile_id' => $profile->id, 'plate' => '34TST001', 'vehicle_type' => 'tir', 'body_type' => 'tenteli', 'is_active' => true]);

        return $user->fresh();
    }

    private function scraped(array $attrs = []): ScrapedLoad
    {
        return ScrapedLoad::create(array_merge([
            'scraper_id' => $this->scraper->id, 'content_hash' => uniqid('h'), 'raw_message' => 'x', 'encrypted_sender_phone' => Crypt::encryptString('5321112233'),
            'pickup_location' => 'Ankara', 'pickup_province_code' => 6, 'pickup_lat' => 39.92, 'pickup_lng' => 32.85,
            'delivery_location' => 'İzmir', 'delivery_province_code' => 35, 'delivery_lat' => 38.42, 'delivery_lng' => 27.14,
            'vehicle_type' => 'tir', 'vehicle_type_source' => 'keyword', 'status' => 'parsed_success', 'visibility' => 'public',
            'published_at' => now(), 'last_seen_at' => now(), 'retention_expires_at' => now()->addDays(7), 'is_incomplete' => false,
        ], $attrs));
    }

    public function test_per_ton_price_is_compared_as_vehicle_price_in_filter_and_sort(): void
    {
        $perTon = $this->scraped(['price' => 2450, 'price_unit' => 'per_ton', 'weight' => 26000, 'goods_type' => 'Kömür']); // ≈ 63.700 ₺
        $total = $this->scraped(['price' => 30000, 'price_unit' => 'total', 'goods_type' => 'Paletli yük']);
        $cheap = $this->scraped(['price' => 12000, 'price_unit' => 'total']);
        $svc = app(LoadFilterService::class);
        $ids = fn (array $f) => $svc->applyToScraped(ScrapedLoad::query(), LoadFilterService::normalize($f + ['vehicle_mode' => 'any']), null)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$perTon->id, $total->id], $ids(['min_price' => 20000]), 'ton başı ilan tonajla çarpılarak eşiği geçer');
        $this->assertEqualsCanonicalizing([$total->id, $cheap->id], $ids(['max_price' => 40000]));
        $this->assertSame([$perTon->id, $total->id, $cheap->id], $ids(['sort' => 'price_desc']), 'fiyat sıralaması araç başı karşılığına göre');
        $this->assertSame([$perTon->id], $ids(['goods_categories' => ['Kömür']]));
    }

    public function test_trailer_length_urgent_and_freshness_filters(): void
    {
        $uzun = $this->scraped(['body_types' => ['tenteli', 'uzun_dorse']]);
        $kisa = $this->scraped(['body_types' => ['kisa_dorse']]);
        $plain = $this->scraped(['body_types' => null, 'parse_metadata' => ['urgent' => true], 'last_seen_at' => now()->subHours(30)]);
        $svc = app(LoadFilterService::class);
        $ids = fn (array $f) => $svc->applyToScraped(ScrapedLoad::query(), LoadFilterService::normalize($f + ['vehicle_mode' => 'any']), null)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$uzun->id, $plain->id], $ids(['trailer_length' => 'uzun']), 'boy yazmayan ilan gizlenmez');
        $this->assertEqualsCanonicalizing([$kisa->id, $plain->id], $ids(['trailer_length' => 'kisa']));
        $this->assertSame([$plain->id], $ids(['urgent' => true]));
        $this->assertEqualsCanonicalizing([$uzun->id, $kisa->id], $ids(['seen_within_hours' => '24']));
        $n = LoadFilterService::normalize(['trailer_length' => 'uzun', 'urgent' => '1', 'seen_within_hours' => '24', 'max_price' => 5000, 'min_price' => 9000]);
        $this->assertSame(['uzun', true, '24', null], [$n['trailer_length'], $n['urgent'], $n['seen_within_hours'], $n['max_price']], 'en çok < en az ise en çok düşer');
        $this->assertSame(4, LoadFilterService::activeCount($n)); // dorse boyu, acil, tazelik, fiyat
        $labels = array_column(LoadFilterService::chipItems($n), 'label', 'key');
        $this->assertSame('Uzun dorse (13.60)', $labels['trailer_length']);
        $this->assertSame('Acil', $labels['urgent']);
        $this->assertSame('', LoadFilterService::without($n, 'trailer_length')['trailer_length']);
        $this->assertFalse(LoadFilterService::without($n, 'urgent')['urgent']);
    }

    public function test_quick_chips_swap_and_chip_removal_on_the_page(): void
    {
        $driver = $this->driver();
        $this->scraped(['pickup_location' => 'Ankara', 'delivery_location' => 'İzmir']);
        $this->scraped(['pickup_location' => 'İzmir', 'pickup_province_code' => 35, 'delivery_location' => 'Ankara', 'delivery_province_code' => 6, 'price' => 40000, 'price_unit' => 'total']);
        $this->actingAs($driver);

        $t = Volt::test('driver.loads.index')->call('setTab', 'external');
        $t->assertSee('2 ilan')->assertSee('Filtreler')->assertSee('Yakınımda');
        $t->call('addProvince', 'pickup', 6)->assertSee('1 ilan')->assertSee('Çıkış: Ankara');
        $t->call('swapSides');
        $this->assertSame([6], $t->get('filters.delivery_provinces'));
        $this->assertSame([], $t->get('filters.pickup_provinces'));
        $t->assertSee('Varış: Ankara')->call('removeChip', 'delivery')->assertSee('2 ilan')->assertDontSee('Varış: Ankara');
        $t->call('toggleQuick', 'priced')->assertSee('1 ilan')->assertSee('Yalnız fiyatlı')->call('toggleQuick', 'priced')->assertSee('2 ilan');
        $t->call('toggleQuick', 'uzun');
        $this->assertSame('uzun', $t->get('filters.trailer_length'));
        $t->call('toggleQuick', 'uzun');
        $this->assertSame('', $t->get('filters.trailer_length'));
        $t->call('toggleQuick', 'mine');
        $this->assertSame('any', $t->get('filters.vehicle_mode'));
        $t->call('setWeightPreset', '18000-');
        $this->assertSame([18000, null], [$t->get('filters.min_weight'), $t->get('filters.max_weight')]);
        $t->call('setWeightPreset', '18000-');
        $this->assertNull($t->get('filters.min_weight'));
        $t->set('search', 'İzmir Ankara')->assertSee('2 ilan'); // sözcükler ayrı aranır: rota iki yönde de eşleşir (SQLite LIKE İ/i ayırır, MySQL ayırmaz)
        $t->set('search', 'İzmir Kömür')->assertSee('0 ilan');
    }

    public function test_saved_tab_hides_external_loads_that_left_publication(): void
    {
        $driver = $this->driver();
        $live = $this->scraped(['pickup_location' => 'Bursa', 'pickup_province_code' => 16]);
        $gone = $this->scraped(['pickup_location' => 'Konya', 'pickup_province_code' => 42]);
        DriverSavedLoad::create(['driver_profile_id' => $driver->driverProfile->id, 'scraped_load_id' => $live->id]);
        DriverSavedLoad::create(['driver_profile_id' => $driver->driverProfile->id, 'scraped_load_id' => $gone->id]);
        $gone->update(['visibility' => 'private', 'status' => 'rejected']);

        $this->actingAs($driver);
        Volt::test('driver.loads.index')->call('setTab', 'saved')->assertSee('Bursa')->assertDontSee('Konya');
    }
}
