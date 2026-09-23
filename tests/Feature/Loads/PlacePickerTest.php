<?php

namespace Tests\Feature\Loads;

use App\Models\DriverFilterPreset;
use App\Models\DriverProfile;
use App\Models\DriverVehicle;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** İlan havuzu: il/ilçe seçim kutuları (etiketler kutunun içinde, ilçe etiketleri, "Her yer"). */
class PlacePickerTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_picks_provinces_and_districts_as_chips(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->driver()->create();
        $profile = DriverProfile::create(['user_id' => $user->id, 'kyc_status' => 'approved']);
        DriverVehicle::create(['driver_profile_id' => $profile->id, 'plate' => '01ADN123', 'vehicle_type' => 'tir', 'is_active' => true]);
        $this->actingAs($user);

        $c = Volt::test('driver.loads.index')->assertSee('Her yer')
            ->call('toggleProvince', 'pickup', 1)->assertSee('Adana ilçeleri (tümü)')->assertSee('Ceyhan')
            ->call('toggleDistrict', 'pickup', 1, 'Ceyhan')->call('toggleDistrict', 'pickup', 1, 'Kozan')
            ->assertSee('Adana ilçeleri: Ceyhan, Kozan')->assertSee('(2 ilçe)')
            ->call('toggleProvince', 'delivery', 9)->assertSee('Varış: Aydın');
        $this->assertSame([1 => ['Ceyhan', 'Kozan']], $c->get('filters')['pickup_districts']);

        // İlçe kaldırılınca ilin tamamı; il kaldırılınca ilçeleri de gider; "Her yer" temizler
        $c->call('toggleDistrict', 'pickup', 1, 'Ceyhan')->call('toggleDistrict', 'pickup', 1, 'Kozan')->assertSee('Adana ilçeleri (tümü)');
        $c->call('toggleDistrict', 'pickup', 1, 'Ceyhan')->call('removeProvince', 'pickup', 1);
        $this->assertSame([], $c->get('filters')['pickup_districts']);
        $c->call('clearSide', 'delivery');
        $this->assertSame([], $c->get('filters')['delivery_provinces']);

        // Kaydedilen set ilçeleri taşır
        $c->call('toggleProvince', 'pickup', 1)->call('toggleDistrict', 'pickup', 1, 'Ceyhan')
            ->set('presetName', 'Ceyhan çıkışlı')->set('presetDefault', true)->call('savePreset');
        $this->assertSame([1 => ['Ceyhan']], DriverFilterPreset::first()->filters['pickup_districts']);
    }
}
