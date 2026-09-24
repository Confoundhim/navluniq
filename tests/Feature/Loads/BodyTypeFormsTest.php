<?php

namespace Tests\Feature\Loads;

use App\Models\CargoOwnerProfile;
use App\Models\DriverProfile;
use App\Models\DriverVehicle;
use App\Models\Load;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Yük sahibi ilan formu ve şoför araç formunda kasa / dorse alanları. */
class BodyTypeFormsTest extends TestCase
{
    use RefreshDatabase;

    public function test_cargo_owner_picks_body_types_for_the_vehicle_class_and_load_kind(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Settings::set('min_load_price', '100');
        Storage::fake('private');
        $user = User::factory()->create(['current_role' => 'cargo_owner']);
        CargoOwnerProfile::create(['user_id' => $user->id, 'type' => 'individual']);
        $this->actingAs($user);

        $c = Volt::test('cargo-owner.loads.create')->set('currentStep', 2)
            ->assertSee('Kasa / dorse tipi')->assertSee('Yük biçimi')
            ->set('vehicle_type', 'tir')->assertSee('Uzun dorse (13.60)')
            ->set('body_types', ['tenteli', 'uzun_dorse', 'lowbed'])
            ->set('vehicle_type', 'kamyonet')->assertDontSee('Uzun dorse (13.60)')->assertDontSee('Lowbed')->assertSee('Liftli');
        $this->assertSame(['tenteli'], $c->get('body_types'), 'Araç sınıfı değişince tıra özel seçimler (13.60, lowbed) düşer; tenteli kamyonette de vardır');
        $c->set('vehicle_type', 'panelvan')->assertDontSee('Tenteli')->assertDontSee('Liftli');
        $this->assertSame([], $c->get('body_types'), 'Panelvan yalnız kapalı / frigo');

        $c->set('vehicle_type', 'tir')->set('body_types', ['damperli'])->set('load_kind', 'komple')
            ->set('pickup_location', 'Ankara')->set('delivery_location', 'İzmir')->set('pickup_date', now()->addDay()->format('Y-m-d'))
            ->set('goods_type', Load::GOODS_TYPES[0])->set('weight', '24000')->set('price', '45000')->set('terms_accepted', true)
            ->set('currentStep', 3)->call('submitLoad');
        $load = Load::query()->latest('id')->first();
        $this->assertNotNull($load);
        $this->assertSame(['damperli'], $load->body_types);
        $this->assertSame('komple', $load->load_kind);
        $this->assertSame('Damper', $load->bodyLabel());
    }

    public function test_driver_saves_body_and_trailer_length_on_the_vehicle(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->driver()->create();
        $profile = DriverProfile::create(['user_id' => $user->id, 'kyc_status' => 'approved']);
        $this->actingAs($user);

        Volt::test('driver.vehicles.index')->call('openCreate')
            ->set('plate', '34TNT123')->set('vehicle_type', 'tir')->assertSee('Dorse uzunluğu')
            ->set('body_type', 'tenteli')->set('trailer_length', 'uzun')->call('save')->assertHasNoErrors();
        $v = DriverVehicle::query()->where('driver_profile_id', $profile->id)->first();
        $this->assertSame(['tenteli', 'uzun'], [$v->body_type, $v->trailer_length]);

        // Kamyonete dorse uzunluğu yazılmaz; tenteli + liftli kamyonet olur, damperli olmaz (sınıf dışı → hata); "liftli" kasa cinsi değildir
        Volt::test('driver.vehicles.index')->call('openEdit', $v->id)->set('vehicle_type', 'kamyonet')->assertDontSee('Dorse uzunluğu')->assertSee('Kuyruk lifti')
            ->set('body_type', 'tenteli')->set('trailer_length', 'uzun')->set('has_lift', '1')->call('save')->assertHasNoErrors();
        $v->refresh();
        $this->assertSame(['kamyonet', 'tenteli', null, true], [$v->vehicle_type, $v->body_type, $v->trailer_length, $v->has_lift]);
        Volt::test('driver.vehicles.index')->call('openEdit', $v->id)->set('body_type', 'damperli')->call('save')->assertHasErrors(['body_type']);
        Volt::test('driver.vehicles.index')->call('openEdit', $v->id)->set('body_type', 'liftli')->call('save')->assertHasErrors(['body_type']);
        // Panelvan: kasa kapalı / frigo, lift sorulmaz; kaydedilen lift bilgisi düşer
        Volt::test('driver.vehicles.index')->call('openEdit', $v->id)->set('vehicle_type', 'panelvan')->assertDontSee('Kuyruk lifti')
            ->set('body_type', 'frigo')->call('save')->assertHasNoErrors();
        $v->refresh();
        $this->assertSame(['panelvan', 'frigo', null], [$v->vehicle_type, $v->body_type, $v->has_lift]);
    }
}
