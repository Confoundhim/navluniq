<?php

namespace Tests\Feature\Driver;

use App\Models\CargoOwnerProfile;
use App\Models\DriverFilterPreset;
use App\Models\DriverProfile;
use App\Models\DriverVehicle;
use App\Models\Load;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Şoför ana sayfası: araç, kasa ve varsayılan filtre setine göre süzülmüş sistem + dış kaynak ilanları. */
class DashboardMatchedLoadsTest extends TestCase
{
    use RefreshDatabase;

    private function load(CargoOwnerProfile $owner, array $o): Load
    {
        return Load::create(array_merge([
            'cargo_owner_profile_id' => $owner->id, 'source_type' => 'internal', 'visibility' => 'public',
            'pickup_location' => 'Ankara', 'pickup_province_code' => 6, 'delivery_location' => 'İzmir', 'delivery_province_code' => 35,
            'pickup_date' => now()->addDay(), 'vehicle_type' => 'tir', 'goods_type' => 'Paletli yük', 'weight' => 24000, 'price' => 45000,
            'status' => Load::STATUS_ACTIVE, 'escrow_status' => Load::ESCROW_PENDING, 'published_at' => now(),
        ], $o));
    }

    public function test_dashboard_lists_loads_matching_vehicle_body_and_default_preset(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $ownerUser = User::factory()->create();
        $owner = CargoOwnerProfile::create(['user_id' => $ownerUser->id, 'type' => 'individual']);
        $driverUser = User::factory()->driver()->create();
        $profile = DriverProfile::create(['user_id' => $driverUser->id, 'kyc_status' => 'approved', 'premium_until' => now()->addMonth()]);
        DriverVehicle::create(['driver_profile_id' => $profile->id, 'plate' => '06TNT123', 'vehicle_type' => 'tir', 'body_type' => 'tenteli', 'is_active' => true]);

        $this->load($owner, ['pickup_location' => 'Ankara Ostim', 'body_types' => ['tenteli', 'kapali']]);   // uygun
        $this->load($owner, ['pickup_location' => 'Ankara Sincan', 'body_types' => ['damperli']]);         // kasa uymaz
        $this->load($owner, ['pickup_location' => 'Bursa', 'pickup_province_code' => 16, 'body_types' => ['tenteli']]); // il filtresine takılır
        Scraper::create(['name' => 'Grup', 'type' => 'notification', 'source_identifier' => 'notif:grup', 'is_active' => true]);
        ScrapedLoad::create(['scraper_id' => 1, 'content_hash' => 'x1', 'raw_message' => 'm', 'sender_phone' => null, 'pickup_location' => 'Ankara Kazan', 'pickup_province_code' => 6,
            'delivery_location' => 'Konya', 'delivery_province_code' => 42, 'vehicle_type' => 'tir', 'vehicle_type_source' => 'keyword', 'body_types' => ['tenteli', 'uzun_dorse'],
            'status' => 'parsed_success', 'visibility' => 'public', 'retention_expires_at' => now()->addDays(30)]);
        DriverFilterPreset::create(['driver_profile_id' => $profile->id, 'name' => 'Ankara çıkışlı', 'is_default' => true, 'filters' => ['pickup_provinces' => [6]]]);

        $this->actingAs($driverUser);
        Volt::test('driver.dashboard')
            ->assertSee('Size uygun ilanlar')
            ->assertSee('TIR · Tenteli')->assertSee('filtre: Ankara çıkışlı')
            ->assertSee('Ankara Ostim')->assertSee('Ankara Kazan')->assertSee('Gruptan derlendi')
            ->assertDontSee('Ankara Sincan')->assertDontSee('Bursa');

        // Premium değilse dış kaynak görünmez
        $profile->forceFill(['premium_until' => null])->save();
        $this->actingAs($driverUser->fresh());
        Volt::test('driver.dashboard')->assertSee('Ankara Ostim')->assertDontSee('Ankara Kazan');
    }
}
