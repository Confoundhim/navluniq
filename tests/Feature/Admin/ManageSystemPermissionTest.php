<?php

namespace Tests\Feature\Admin;

use App\Models\Backup;
use App\Models\BannedIp;
use App\Models\DriverProfile;
use App\Models\DriverVehicle;
use App\Models\User;
use App\Services\DeployService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Denetim Y5: "manage system" izni, personel oluşturma kısıtı, ölü "manage ai settings" izni. */
class ManageSystemPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        @unlink(DeployService::lockPath());
        @unlink(DeployService::logPath());
        parent::tearDown();
    }

    private function staff(string $role, array $extra = []): User
    {
        $user = User::factory()->create(['current_role' => 'admin']);
        $user->syncRoles([$role]);
        foreach ($extra as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    public function test_seeder_gives_manage_system_only_to_super_admin_and_drops_dead_ai_permission(): void
    {
        $this->assertTrue(Role::findByName('super_admin')->hasPermissionTo('manage system'));
        foreach (['kyc_validator', 'financial_officer', 'support_agent'] as $role) {
            $this->assertFalse(Role::findByName($role)->hasPermissionTo('manage system'), $role);
        }
        $this->assertSame(0, Permission::query()->where('name', 'manage ai settings')->count());

        // Yeniden çalıştırma eski izni geri getirmez.
        Permission::create(['name' => 'manage ai settings', 'guard_name' => 'web']);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->assertSame(0, Permission::query()->where('name', 'manage ai settings')->count());
    }

    public function test_settings_admin_can_view_but_not_operate_system_actions(): void
    {
        config()->set('services.deploy.command', 'echo guncelleme');
        $admin = $this->staff('support_agent', ['manage settings']);
        $this->actingAs($admin);

        $this->get('/adminsystem/health')->assertOk()->assertSee('Güncelleme yalnız sistem yönetimi yetkisiyle başlatılır');
        Volt::test('admin.health-center')->call('startUpdate')->assertSee('sistem yönetimi yetkisi gerekir');
        $this->assertFalse(DeployService::isRunning());

        $this->get('/adminsystem/backups')->assertOk();
        Volt::test('admin.backups-center')->call('createBackup', 'database')->assertSee('sistem yönetimi yetkisi gerekir');
        $this->assertSame(0, Backup::count());

        $this->get('/adminsystem/firewall')->assertOk()->assertSee('yalnız sistem yönetimi yetkisiyle');
        Volt::test('admin.firewall-center')->set('ipAddress', '203.0.113.9')->set('reason', 'Saldırı denemesi')->call('ban')->assertSee('sistem yönetimi yetkisi gerekir');
        $this->assertSame(0, BannedIp::count());

        $driver = User::factory()->driver()->create();
        $profile = DriverProfile::create(['user_id' => $driver->id, 'kyc_status' => 'approved']);
        $vehicle = DriverVehicle::create(['driver_profile_id' => $profile->id, 'plate' => '06TEST01', 'vehicle_type' => 'tir', 'body_type' => 'tenteli', 'is_active' => true]);
        $vehicle->delete();
        Volt::test('admin.rollback-center')->set('trashType', 'vehicles')->call('forceDelete', 'vehicles', $vehicle->id)->assertSee('sistem yönetimi yetkisi gerekir');
        $this->assertSame(1, DriverVehicle::onlyTrashed()->count());
    }

    public function test_super_admin_operates_system_actions(): void
    {
        $this->actingAs($this->staff('super_admin'));

        Volt::test('admin.firewall-center')->set('ipAddress', '203.0.113.9')->set('reason', 'Saldırı denemesi')->call('ban')->assertSee('yasaklandı');
        $this->assertSame(1, BannedIp::count());
        Volt::test('admin.firewall-center')->call('unban', BannedIp::first()->id);
        $this->assertSame(0, BannedIp::count());

        $driver = User::factory()->driver()->create();
        $profile = DriverProfile::create(['user_id' => $driver->id, 'kyc_status' => 'approved']);
        $vehicle = DriverVehicle::create(['driver_profile_id' => $profile->id, 'plate' => '06TEST02', 'vehicle_type' => 'tir', 'body_type' => 'tenteli', 'is_active' => true]);
        $vehicle->delete();
        Volt::test('admin.rollback-center')->set('trashType', 'vehicles')->assertSee('bağlı kayıt yok')->call('forceDelete', 'vehicles', $vehicle->id)->assertSee('kalıcı olarak silindi');
        $this->assertSame(0, DriverVehicle::withTrashed()->count());
    }

    public function test_users_are_never_force_deleted_from_the_trash(): void
    {
        $this->actingAs($this->staff('super_admin'));
        $user = User::factory()->create();
        $user->delete();

        Volt::test('admin.rollback-center')->assertDontSee('Kalıcı sil')->call('forceDelete', 'users', $user->id)->assertSee('kalıcı silinmez');
        $this->assertSame(1, User::onlyTrashed()->whereKey($user->id)->count());

        Volt::test('admin.rollback-center')->call('restore', 'users', $user->id)->assertSee('geri yüklendi');
        $this->assertNotNull(User::find($user->id));
    }

    public function test_only_super_admin_creates_staff_and_cannot_exceed_own_permissions(): void
    {
        $manager = $this->staff('kyc_validator', ['manage staff']);
        $this->actingAs($manager);
        $this->get('/adminsystem/staff')->assertOk()->assertSee('yalnız süper yönetici oluşturur')->assertDontSee('Hesabı oluştur');

        Volt::test('admin.staff-center')
            ->set('firstName', 'Deneme')->set('lastName', 'Personel')->set('email', 'deneme.personel@ornek.test')->set('phone', '05321234567')
            ->set('password', 'CokGizliSifre123!')->set('role', 'financial_officer')->call('createStaff')->assertSee('yalnız süper yönetici');
        $this->assertNull(User::where('email', 'deneme.personel@ornek.test')->first());

        $this->actingAs($this->staff('super_admin'));
        Volt::test('admin.staff-center')->assertSee('Hesabı oluştur')
            ->set('firstName', 'Deneme')->set('lastName', 'Personel')->set('email', 'deneme.personel@ornek.test')->set('phone', '05321234567')
            ->set('password', 'CokGizliSifre123!')->set('role', 'financial_officer')->call('createStaff')->assertHasNoErrors();
        $created = User::where('email', 'deneme.personel@ornek.test')->first();
        $this->assertNotNull($created);
        $this->assertTrue($created->hasRole('financial_officer'));
    }
}
