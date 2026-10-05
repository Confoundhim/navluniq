<?php

namespace Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\CargoOwnerProfile;
use App\Models\DriverProfile;
use App\Models\DriverTrip;
use App\Models\Load;
use App\Models\Offer;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Models\User;
use App\Models\UserNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Denetim Y7: engelleme açık işlere bakar; "engelle ve açık işleri kapat"; banned_by; kullanıcıya bildirim. */
class BanFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
        $this->admin = User::factory()->create(['current_role' => 'admin']);
        $this->admin->syncRoles(['super_admin']);
        $this->admin = $this->admin->fresh();
        $this->actingAs($this->admin);
    }

    private function driverWithOpenWork(): User
    {
        $driver = User::factory()->driver()->create(['first_name' => 'Engin', 'last_name' => 'Şoför']);
        $driver->syncRoles(['driver']);
        $profile = DriverProfile::create(['user_id' => $driver->id, 'kyc_status' => 'approved', 'premium_until' => now()->addMonth()]);

        $ownerUser = User::factory()->create(['current_role' => 'cargo_owner']);
        $owner = CargoOwnerProfile::create(['user_id' => $ownerUser->id, 'type' => 'individual', 'kyc_status' => 'approved']);
        $load = Load::create([
            'cargo_owner_profile_id' => $owner->id, 'source_type' => 'internal', 'visibility' => 'public',
            'pickup_location' => 'İzmir', 'pickup_province_code' => 35, 'delivery_location' => 'Ankara', 'delivery_province_code' => 6,
            'pickup_date' => now()->addDays(3), 'vehicle_type' => 'tir', 'body_types' => ['tenteli'], 'goods_type' => 'Paletli yük', 'weight' => 24000, 'price' => 45000,
            'status' => Load::STATUS_ACTIVE, 'escrow_status' => Load::ESCROW_PENDING, 'published_at' => now(),
        ]);
        Offer::create(['load_id' => $load->id, 'driver_profile_id' => $profile->id, 'amount' => 42000, 'currency' => 'TRY', 'status' => 'pending']);

        Scraper::create(['name' => 'Grup', 'type' => 'notification', 'source_identifier' => 'notif:grup', 'is_active' => true]);
        $ext = ScrapedLoad::create([
            'scraper_id' => 1, 'content_hash' => 'h1', 'raw_message' => 'm', 'sender_phone' => '05551112233',
            'pickup_location' => 'Ankara', 'pickup_province_code' => 6, 'delivery_location' => 'İzmir', 'delivery_province_code' => 35,
            'vehicle_type' => 'tir', 'status' => 'parsed_success', 'visibility' => 'public', 'retention_expires_at' => now()->addDays(30),
        ]);
        DriverTrip::create(['driver_profile_id' => $profile->id, 'source' => 'external', 'scraped_load_id' => $ext->id, 'pickup_location' => 'Ankara', 'pickup_province_code' => 6,
            'delivery_location' => 'İzmir', 'delivery_province_code' => 35, 'pickup_date' => now(), 'delivery_date' => now()->addDay(), 'status' => DriverTrip::STATUS_PLANNED]);

        return $driver->fresh();
    }

    public function test_ban_shows_open_items_and_only_ban_and_close_proceeds(): void
    {
        $driver = $this->driverWithOpenWork();

        $c = Volt::test('admin.users-center')->call('toggle', $driver->id)->set('banReason', 'Sahte belge')->call('prepareBan', $driver->id)
            ->assertSet('banPreviewId', $driver->id)->assertSee('Açık işleri var')->assertSee('1 bekleyen / kabul edilmiş teklif')->assertSee('1 gruptan alınan açık iş')->assertSee('Engelle ve açık işleri kapat');
        $this->assertNull($driver->fresh()->banned_at, 'Açık iş varken ön izleme bekler');

        // Doğrudan ban() çağrısı da açık işi atlayamaz.
        $c->call('ban', $driver->id);
        $this->assertNull($driver->fresh()->banned_at);

        $c->call('cancelBan')->assertSet('banPreviewId', null);

        $c->set('banReason', 'Sahte belge')->call('banAndClose', $driver->id)->assertSee('engellendi')->assertSee('Kapatılan');
        $driver = $driver->fresh();
        $this->assertNotNull($driver->banned_at);
        $this->assertSame($this->admin->id, $driver->banned_by);
        $this->assertSame('Sahte belge', $driver->ban_reason);
        $this->assertSame('rejected', Offer::first()->status);
        $this->assertSame(DriverTrip::STATUS_CLOSED, DriverTrip::first()->status);
        $this->assertSame(1, UserNotification::query()->where('user_id', $driver->id)->where('title', 'Hesabınız engellendi')->count());
        $log = ActivityLog::query()->where('action', 'user.banned')->first();
        $this->assertSame(1, $log->metadata['open_items']['offers']);
        $this->assertSame(1, $log->metadata['closed']['external_trips']);

        Volt::test('admin.users-center')->call('unban', $driver->id);
        $this->assertNull($driver->fresh()->banned_by);
    }

    public function test_user_without_open_work_is_banned_immediately(): void
    {
        $owner = User::factory()->create(['current_role' => 'cargo_owner', 'first_name' => 'Osman', 'last_name' => 'Sahibi']);
        $owner->syncRoles(['cargo_owner']);
        CargoOwnerProfile::create(['user_id' => $owner->id, 'type' => 'individual', 'kyc_status' => 'unsubmitted']);

        Volt::test('admin.users-center')->call('prepareBan', $owner->id)->assertSet('banPreviewId', null)->assertSee('engellendi; giriş yapamaz');
        $owner = $owner->fresh();
        $this->assertNotNull($owner->banned_at);
        $this->assertSame($this->admin->id, $owner->banned_by);
        $this->assertSame('Yönetici kararı', $owner->ban_reason);
        $this->assertSame(1, UserNotification::query()->where('user_id', $owner->id)->where('title', 'Hesabınız engellendi')->count());
    }
}
