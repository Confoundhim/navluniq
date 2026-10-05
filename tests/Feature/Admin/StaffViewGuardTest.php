<?php

namespace Tests\Feature\Admin;

use App\Models\CargoOwnerProfile;
use App\Models\DriverProfile;
use App\Models\DriverVehicle;
use App\Models\Load;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Models\User;
use App\Services\DriverTripService;
use App\Services\LoadService;
use App\Services\OfferService;
use App\Services\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/** Denetim Y17: yöneticinin "panel görünümü" profili (is_staff_view) gerçek işlem yapamaz. */
class StaffViewGuardTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'Yönetici görünümünde işlem yapılamaz.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function staffDriver(): DriverProfile
    {
        $admin = User::factory()->create(['current_role' => 'admin']);
        $admin->syncRoles(['super_admin']);
        $profile = DriverProfile::create(['user_id' => $admin->id, 'kyc_status' => 'approved', 'is_staff_view' => true, 'premium_until' => now()->addYears(10)]);
        DriverVehicle::create(['driver_profile_id' => $profile->id, 'plate' => 'YONETIM'.$admin->id, 'vehicle_type' => 'tir', 'body_type' => 'tenteli', 'is_active' => true]);

        return $profile->fresh();
    }

    private function realOwnerLoad(): Load
    {
        $owner = User::factory()->create(['current_role' => 'cargo_owner']);
        $profile = CargoOwnerProfile::create(['user_id' => $owner->id, 'type' => 'individual', 'kyc_status' => 'approved']);

        return Load::create([
            'cargo_owner_profile_id' => $profile->id, 'source_type' => 'internal', 'visibility' => 'public',
            'pickup_location' => 'İzmir', 'pickup_province_code' => 35, 'delivery_location' => 'Ankara', 'delivery_province_code' => 6,
            'pickup_date' => now()->addDays(3), 'vehicle_type' => 'tir', 'body_types' => ['tenteli'], 'goods_type' => 'Paletli yük', 'weight' => 24000, 'price' => 45000,
            'status' => Load::STATUS_ACTIVE, 'escrow_status' => Load::ESCROW_PENDING, 'published_at' => now(),
        ]);
    }

    public function test_staff_view_driver_cannot_offer_take_external_job_or_buy_premium(): void
    {
        $driver = $this->staffDriver();
        $load = $this->realOwnerLoad();

        try {
            app(OfferService::class)->submit($driver, $load, 40000, null, 2);
            $this->fail('Teklif verilmemeliydi');
        } catch (RuntimeException $e) {
            $this->assertSame(self::MESSAGE, $e->getMessage());
        }
        $this->assertDatabaseCount('offers', 0);

        Scraper::create(['name' => 'Grup', 'type' => 'notification', 'source_identifier' => 'notif:grup', 'is_active' => true]);
        $external = ScrapedLoad::create([
            'scraper_id' => 1, 'content_hash' => 'h1', 'raw_message' => 'm', 'sender_phone' => '05551112233',
            'pickup_location' => 'Ankara', 'pickup_province_code' => 6, 'delivery_location' => 'İzmir', 'delivery_province_code' => 35,
            'vehicle_type' => 'tir', 'status' => 'parsed_success', 'visibility' => 'public', 'retention_expires_at' => now()->addDays(30),
        ]);
        try {
            app(DriverTripService::class)->takeExternal($driver, $external, now(), now()->addDay());
            $this->fail('Dış iş alınmamalıydı');
        } catch (RuntimeException $e) {
            $this->assertSame(self::MESSAGE, $e->getMessage());
        }
        $this->assertDatabaseCount('driver_trips', 0);

        try {
            app(SubscriptionService::class)->startCheckout($driver->user);
            $this->fail('Premium ödemesi başlamamalıydı');
        } catch (RuntimeException $e) {
            $this->assertSame(self::MESSAGE, $e->getMessage());
        }
        $this->assertDatabaseCount('payment_orders', 0);
    }

    public function test_staff_view_cargo_owner_cannot_publish_a_load(): void
    {
        $admin = User::factory()->create(['current_role' => 'admin']);
        $admin->syncRoles(['super_admin']);
        $owner = CargoOwnerProfile::create(['user_id' => $admin->id, 'type' => 'individual', 'kyc_status' => 'approved', 'is_staff_view' => true]);

        try {
            app(LoadService::class)->publish($owner, ['pickup_location' => 'İzmir', 'delivery_location' => 'Ankara', 'price' => 45000, 'pickup_date' => now()->addDays(2)->toDateString(), 'vehicle_type' => 'tir', 'goods_type' => 'Paletli yük', 'weight' => 24000]);
            $this->fail('İlan açılmamalıydı');
        } catch (RuntimeException $e) {
            $this->assertSame(self::MESSAGE, $e->getMessage());
        }
        $this->assertDatabaseCount('loads', 0);
    }
}
