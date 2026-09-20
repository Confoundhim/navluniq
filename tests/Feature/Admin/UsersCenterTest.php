<?php

namespace Tests\Feature\Admin;

use App\Models\CargoOwnerProfile;
use App\Models\DriverProfile;
use App\Models\KycDocument;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class UsersCenterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $driver;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create(['current_role' => 'admin']);
        $this->admin->syncRoles(['super_admin']);
        $this->admin = $this->admin->fresh();

        $this->driver = User::factory()->driver()->create(['first_name' => 'Engin', 'last_name' => 'Şoför']);
        $this->driver->syncRoles(['driver']);
        DriverProfile::create(['user_id' => $this->driver->id, 'kyc_status' => 'pending']);
        KycDocument::create(['user_id' => $this->driver->id, 'document_type' => 'driver_license', 'storage_disk' => 'kyc', 'storage_path' => 'x/ehliyet.jpg', 'sha256' => str_repeat('a', 64), 'status' => 'pending']);

        $this->owner = User::factory()->create(['current_role' => 'cargo_owner', 'first_name' => 'Osman', 'last_name' => 'Sahibi']);
        $this->owner->syncRoles(['cargo_owner']);
        CargoOwnerProfile::create(['user_id' => $this->owner->id, 'type' => 'individual', 'kyc_status' => 'unsubmitted']);
    }

    public function test_admin_lists_and_filters_users(): void
    {
        $this->actingAs($this->admin)->get('/adminsystem/users')->assertOk()->assertSee('Kullanıcılar')->assertSee('Engin Şoför')->assertSee('Osman Sahibi');

        Volt::test('admin.users-center')->set('role', 'driver')->assertSee('Engin Şoför')->assertDontSee('Osman Sahibi')
            ->set('role', '')->set('search', 'osman')->assertSee('Osman Sahibi')->assertDontSee('Engin Şoför')
            ->set('search', '')->set('kyc', 'pending')->assertSee('Engin Şoför')->assertDontSee('Osman Sahibi');
    }

    public function test_admin_approves_documents_and_can_reset(): void
    {
        $this->actingAs($this->admin);
        Volt::test('admin.users-center')->call('approveKyc', $this->driver->id, 'driver');

        $profile = $this->driver->driverProfile->fresh();
        $this->assertSame('approved', $profile->kyc_status);
        $this->assertSame($this->admin->id, $profile->kyc_verified_by);
        $this->assertSame('approved', KycDocument::where('user_id', $this->driver->id)->first()->status);
        $this->assertSame('Belge doğrulamanız tamamlandı', UserNotification::where('user_id', $this->driver->id)->latest('id')->first()->title);

        Volt::test('admin.users-center')->call('approveKyc', $this->owner->id, 'cargo_owner');
        $this->assertSame('approved', $this->owner->cargoOwnerProfile->fresh()->kyc_status, 'Belge yüklenmemiş olsa da yönetici onaylayabilir');

        Volt::test('admin.users-center')->call('resetKyc', $this->driver->id, 'driver');
        $this->assertSame('pending', $this->driver->driverProfile->fresh()->kyc_status);
        Volt::test('admin.users-center')->call('resetKyc', $this->owner->id, 'cargo_owner');
        $this->assertSame('unsubmitted', $this->owner->cargoOwnerProfile->fresh()->kyc_status, 'Belge yoksa "gönderilmedi"');
    }

    public function test_admin_gifts_premium_extends_it_and_revokes(): void
    {
        $this->actingAs($this->admin);
        Volt::test('admin.users-center')->call('grantPremium', $this->driver->id, 7);

        $profile = $this->driver->driverProfile->fresh();
        $this->assertTrue($profile->isPremium());
        $this->assertEqualsWithDelta(7, now()->diffInDays($profile->premium_until), 0.01);
        $sub = Subscription::where('user_id', $this->driver->id)->first();
        $this->assertSame(['premium_gift', 'manual', 'active'], [$sub->plan_code, $sub->provider, $sub->status]);
        $this->assertSame(0.0, (float) $sub->amount);
        $this->assertStringContainsString('7 günlük premium', UserNotification::where('user_id', $this->driver->id)->latest('id')->first()->lines[0]);

        // Üzerine eklenir: 7 + 30 gün.
        Volt::test('admin.users-center')->call('grantPremium', $this->driver->id, 30);
        $this->assertEqualsWithDelta(37, now()->diffInDays($this->driver->driverProfile->fresh()->premium_until), 0.01);
        $this->assertSame(2, Subscription::where('user_id', $this->driver->id)->count());

        Volt::test('admin.users-center')->call('revokePremium', $this->driver->id);
        $this->assertFalse($this->driver->driverProfile->fresh()->isPremium());
        $this->assertSame(0, Subscription::where('user_id', $this->driver->id)->where('status', 'active')->count());

        // Yük sahibinin şoför profili yok: hata mesajı, çökme yok.
        Volt::test('admin.users-center')->call('grantPremium', $this->owner->id, 7);
        $this->assertNull($this->owner->driverProfile);
    }

    public function test_admin_bans_and_unbans_but_not_staff(): void
    {
        $this->actingAs($this->admin);
        Volt::test('admin.users-center')->set('banReason', 'Sahte belge')->call('ban', $this->driver->id);
        $driver = $this->driver->fresh();
        $this->assertNotNull($driver->banned_at);
        $this->assertSame('Sahte belge', $driver->ban_reason);

        Volt::test('admin.users-center')->call('unban', $this->driver->id);
        $this->assertNull($this->driver->fresh()->banned_at);

        Volt::test('admin.users-center')->call('ban', $this->admin->id);
        $this->assertNull($this->admin->fresh()->banned_at);
    }

    public function test_kyc_validator_can_approve_but_cannot_gift_premium(): void
    {
        $validator = User::factory()->create(['current_role' => 'admin']);
        $validator->syncRoles(['kyc_validator']);
        $this->actingAs($validator->fresh());

        $this->get('/adminsystem/users')->assertOk();
        Volt::test('admin.users-center')->call('grantPremium', $this->driver->id, 7);
        $this->assertFalse($this->driver->driverProfile->fresh()->isPremium());
        Volt::test('admin.users-center')->call('approveKyc', $this->driver->id, 'driver');
        $this->assertSame('approved', $this->driver->driverProfile->fresh()->kyc_status);
    }
}
