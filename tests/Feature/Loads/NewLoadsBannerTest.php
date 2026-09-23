<?php

namespace Tests\Feature\Loads;

use App\Models\CargoOwnerProfile;
use App\Models\DriverProfile;
use App\Models\DriverVehicle;
use App\Models\Load;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** İlan havuzu sabit kalır; yeni ilan gelince "N yeni ilan · Göster" çıkar, dokununca liste yenilenir. */
class NewLoadsBannerTest extends TestCase
{
    use RefreshDatabase;

    private function load(CargoOwnerProfile $owner, string $pickup): Load
    {
        return Load::create([
            'cargo_owner_profile_id' => $owner->id, 'source_type' => 'internal', 'visibility' => 'public',
            'pickup_location' => $pickup, 'pickup_province_code' => 6, 'delivery_location' => 'İzmir', 'delivery_province_code' => 35,
            'pickup_date' => now()->addDay(), 'vehicle_type' => 'tir', 'goods_type' => 'Paletli yük', 'weight' => 24000, 'price' => 45000,
            'status' => Load::STATUS_ACTIVE, 'escrow_status' => Load::ESCROW_PENDING, 'published_at' => now(),
        ]);
    }

    public function test_new_loads_wait_behind_a_banner_until_the_driver_asks(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $ownerUser = User::factory()->create();
        $owner = CargoOwnerProfile::create(['user_id' => $ownerUser->id, 'type' => 'individual']);
        $driver = User::factory()->driver()->create();
        $profile = DriverProfile::create(['user_id' => $driver->id, 'kyc_status' => 'approved']);
        DriverVehicle::create(['driver_profile_id' => $profile->id, 'plate' => '06AA111', 'vehicle_type' => 'tir', 'is_active' => true]);
        $this->load($owner, 'Ankara Eski');
        $this->actingAs($driver);

        $c = Volt::test('driver.loads.index')->assertSee('Ankara Eski')->assertDontSee('yeni ilan');

        // Sayı değişmediyse tazeleme hiçbir şey çizmez
        $c->call('checkNew');
        $this->assertArrayNotHasKey('html', $c->effects);

        // Yeni ilan geldi: liste yerinde durur, düğme çıkar
        $this->travel(2)->seconds();
        $this->load($owner, 'Ankara Yeni');
        $c->call('checkNew')->assertSee('1 yeni ilan')->assertSee('Ankara Eski')->assertDontSee('Ankara Yeni');
        $this->assertArrayHasKey('html', $c->effects);

        // Göster: liste yeni ana taşınır
        $c->call('showNew')->assertSee('Ankara Yeni')->assertDontSee('yeni ilan');

        // Sekme/filtre değişimi de yeni ana taşır
        $this->travel(2)->seconds();
        $this->load($owner, 'Ankara Üçüncü');
        $c->call('checkNew')->assertSee('1 yeni ilan');
        $c->call('setTab', 'pool')->assertSee('Ankara Üçüncü')->assertDontSee('yeni ilan');
    }

    public function test_lists_show_fifty_per_page_with_simple_page_numbers(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $ownerUser = User::factory()->create();
        $owner = CargoOwnerProfile::create(['user_id' => $ownerUser->id, 'type' => 'individual']);
        $driver = User::factory()->driver()->create();
        $profile = DriverProfile::create(['user_id' => $driver->id, 'kyc_status' => 'approved']);
        DriverVehicle::create(['driver_profile_id' => $profile->id, 'plate' => '06AA222', 'vehicle_type' => 'tir', 'is_active' => true]);
        for ($i = 1; $i <= 52; $i++) {
            $this->load($owner, 'Ankara Nokta '.$i);
        }
        $this->actingAs($driver);

        $c = Volt::test('driver.loads.index')
            ->assertSee('Ankara Nokta 52')->assertSee('Ankara Nokta 3')->assertDontSee('Ankara Nokta 2 ')->assertDontSee('Ankara Nokta 1 ')
            ->assertSee('Sonraki ›')->assertSee('Sayfa 1 / 2')->assertSee('toplam 52 kayıt');
        $c->call('gotoPage', 2)->assertSee('Ankara Nokta 1')->assertSee('Ankara Nokta 2')->assertDontSee('Ankara Nokta 3 ')->assertSee('Sayfa 2 / 2');
    }
}
