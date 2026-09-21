<?php

namespace Tests\Feature\Admin;

use App\Models\DriverProfile;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelSwitchTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create(['current_role' => 'admin']);
        $user->syncRoles(['super_admin']);

        return $user->fresh();
    }

    public function test_admin_can_open_driver_and_cargo_owner_panels_without_losing_admin_access(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $this->get(route('admin.panel-switch', 'driver'))->assertRedirect(route('driver.dashboard'));
        $admin->refresh();
        $this->assertSame('approved', $admin->driverProfile->kyc_status);
        $this->assertTrue($admin->driverProfile->isPremium());
        $this->assertSame(1, $admin->driverProfile->vehicles()->count());
        $this->assertSame('admin', $admin->current_role, 'Yönetici rolü değişmez');

        $this->get(route('driver.dashboard'))->assertOk()->assertSee('Yönetim paneline dön');
        $this->get(route('driver.loads.index', ['tab' => 'external']))->assertOk()->assertSee('Dış kaynak ilanlar');

        $this->get(route('admin.panel-switch', 'cargo_owner'))->assertRedirect(route('cargo-owner.dashboard'));
        $this->assertNotNull($admin->fresh()->cargoOwnerProfile);
        $this->get(route('cargo-owner.dashboard'))->assertOk()->assertSee('Yönetim paneline dön');

        $this->get(route('admin.dashboard'))->assertOk();
        $this->get(route('admin.panel-switch', 'driver'))->assertRedirect(route('driver.dashboard'));
        $this->assertSame(1, $admin->fresh()->driverProfile->vehicles()->count(), 'İkinci geçişte ikinci araç açılmaz');
    }

    public function test_regular_users_cannot_use_panel_switch_and_still_cannot_cross_panels(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $driver = User::factory()->create(['current_role' => 'driver']);
        DriverProfile::create(['user_id' => $driver->id, 'kyc_status' => 'approved']);

        $this->actingAs($driver)->get(route('admin.panel-switch', 'cargo_owner'))->assertRedirect();
        $this->assertNull($driver->fresh()->cargoOwnerProfile);
        $this->actingAs($driver)->get(route('cargo-owner.dashboard'))->assertRedirect(route('driver.dashboard'));
    }
}
