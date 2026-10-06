<?php

namespace Tests\Feature\Loads;

use App\Models\CargoOwnerProfile;
use App\Models\DriverLocation;
use App\Models\DriverProfile;
use App\Models\DriverVehicle;
use App\Models\Load;
use App\Models\Offer;
use App\Models\Shipment;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\DriverLocationService;
use App\Services\LoadService;
use App\Services\ShipmentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Canlı takip paketi (2026-10-06, docs/PANEL_DENETIMI_2026-10-06.md Paket 2): seyreltilmiş tam iz, varışa kalan km, tahmini varış,
 * konum tazeliği, yolda takılan sevkiyat uyarısı, otomatik onay hatırlatması, konum paylaşımının kendiliğinden başlaması.
 */
class LiveTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function owner(): User
    {
        $user = User::factory()->create(['current_role' => 'cargo_owner', 'first_name' => 'Ayşe', 'last_name' => 'Yılmaz']);
        $user->syncRoles(['cargo_owner']);
        CargoOwnerProfile::create(['user_id' => $user->id, 'type' => 'individual', 'nvi_verified' => true]);

        return $user->fresh();
    }

    private function driver(): User
    {
        $user = User::factory()->driver()->create();
        $user->syncRoles(['driver']);
        $profile = DriverProfile::create(['user_id' => $user->id, 'kyc_status' => 'approved', 'kyc_verified_at' => now(), 'premium_until' => now()->addMonth()]);
        DriverVehicle::create(['driver_profile_id' => $profile->id, 'plate' => '06'.random_int(100, 999).'ZZ'.random_int(10, 99), 'brand' => 'Mercedes', 'model' => 'Actros', 'vehicle_type' => 'tir', 'is_active' => true]);

        return $user->fresh();
    }

    /** Yolda bir sevkiyat: Ankara → İzmir, kabul edilmiş teklif 2 gün. */
    private function inTransit(User $owner, User $driver, array $loadExtra = [], array $shipmentExtra = []): array
    {
        $load = Load::create(array_merge([
            'cargo_owner_profile_id' => $owner->cargoOwnerProfile->id, 'driver_profile_id' => $driver->driverProfile->id, 'source_type' => 'internal', 'visibility' => 'public',
            'pickup_location' => 'Ankara Yenimahalle', 'pickup_province_code' => 6, 'pickup_lat' => 39.97, 'pickup_lng' => 32.75,
            'delivery_location' => 'İzmir Aliağa', 'delivery_province_code' => 35, 'delivery_lat' => 38.80, 'delivery_lng' => 26.97,
            'pickup_date' => now()->subDay(), 'delivery_date' => now()->addDay(), 'vehicle_type' => 'tir', 'goods_type' => Load::GOODS_TYPES[0], 'price' => 18500,
            'status' => Load::STATUS_ON_THE_WAY, 'escrow_status' => Load::ESCROW_PAID, 'published_at' => now()->subDays(2),
        ], $loadExtra));
        $offer = Offer::create(['load_id' => $load->id, 'driver_profile_id' => $driver->driverProfile->id, 'amount' => 18500, 'currency' => 'TRY', 'status' => 'accepted', 'estimated_days' => 2, 'expires_at' => now()->addDay()]);
        $shipment = Shipment::create(array_merge(['load_id' => $load->id, 'accepted_offer_id' => $offer->id, 'driver_profile_id' => $driver->driverProfile->id,
            'status' => Shipment::STATUS_IN_TRANSIT, 'pickup_confirmed_at' => now()->subHours(3), 'in_transit_at' => now()->subHours(3)], $shipmentExtra));

        return [$load, $shipment];
    }

    public function test_trail_is_decimated_but_keeps_first_and_last_points(): void
    {
        [$load, $shipment] = $this->inTransit($this->owner(), $driver = $this->driver());
        $rows = [];
        for ($i = 0; $i < 700; $i++) {
            $rows[] = ['driver_profile_id' => $driver->driverProfile->id, 'shipment_id' => $shipment->id, 'latitude' => 39.97 - $i * 0.001, 'longitude' => 32.75 - $i * 0.005, 'speed' => 80, 'heading' => 0, 'recorded_at' => now()->subMinutes(700 - $i)];
        }
        DriverLocation::query()->insert($rows);

        $trail = app(DriverLocationService::class)->trailFor($shipment);
        $this->assertLessThanOrEqual(DriverLocationService::TRAIL_MAX_POINTS + 1, count($trail['trail']));
        $this->assertGreaterThan(200, count($trail['trail']), 'Eski sınır 200 noktaydı; tüm yol seyreltilmiş olarak gelmeli');
        $this->assertEqualsWithDelta(39.97, $trail['trail'][0][0], 0.0001, 'İlk nokta korunur');
        $this->assertEqualsWithDelta(39.97 - 699 * 0.001, end($trail['trail'])[0], 0.0001, 'Son nokta korunur');
        $this->assertEqualsWithDelta(39.97 - 699 * 0.001, (float) $trail['latest']->latitude, 0.0001);

        $km = DriverLocationService::remainingKm($trail['latest'], 38.80, 26.97);
        $this->assertNotNull($km);
        $this->assertGreaterThan(200, $km);
        $this->assertNull(DriverLocationService::remainingKm($trail['latest'], null, null));
    }

    public function test_owner_page_shows_eta_remaining_distance_and_stale_location_warning(): void
    {
        [$load, $shipment] = $this->inTransit($owner = $this->owner(), $driver = $this->driver());
        DriverLocation::create(['driver_profile_id' => $driver->driverProfile->id, 'shipment_id' => $shipment->id, 'latitude' => 39.50, 'longitude' => 30.50, 'speed' => 70, 'heading' => 0, 'recorded_at' => now()->subMinutes(40)]);

        $this->actingAs($owner);
        Volt::test('cargo-owner.shipments.show', ['loadId' => $load->id])
            ->assertSee('Tahmini varış '.now()->subHours(3)->addDays(2)->format('d.m'))
            ->assertSee('Konum bir süredir gelmiyor')
            ->assertSee('varışa')
            ->assertSee('Yük alındı, yola çıkıldı')
            ->assertDontSee('Yük teslim alındı');
    }

    public function test_overdue_transit_is_reported_once_to_driver_owner_and_operations(): void
    {
        [$load] = $this->inTransit($owner = $this->owner(), $driver = $this->driver(), ['delivery_date' => now()->subDays(3)]);
        $this->inTransit($owner, $this->driver()); // teslim tarihi yarın: uyarı yok

        $svc = app(LoadService::class);
        $this->assertSame(1, $svc->notifyOverdueTransit());
        $this->assertSame(0, $svc->notifyOverdueTransit(), 'İkinci çalıştırmada aynı sevkiyat yeniden uyarılmaz');
        $this->assertNotNull($load->fresh()->transit_overdue_notified_at);
        $this->assertSame(1, UserNotification::query()->where('user_id', $driver->id)->where('title', 'Teslimat bildirimi bekleniyor')->count());
        $this->assertSame(1, UserNotification::query()->where('user_id', $owner->id)->where('title', 'Sevkiyat teslim tarihini geçti')->count());
    }

    public function test_owner_is_reminded_once_before_automatic_approval(): void
    {
        $owner = $this->owner();
        [$load, $shipment] = $this->inTransit($owner, $this->driver(), ['status' => Load::STATUS_DELIVERED], ['status' => Shipment::STATUS_DELIVERED, 'delivered_at' => now()->subHours(50), 'auto_approval_due_at' => now()->addHours(10)]);
        $this->inTransit($owner, $this->driver(), ['status' => Load::STATUS_DELIVERED], ['status' => Shipment::STATUS_DELIVERED, 'delivered_at' => now(), 'auto_approval_due_at' => now()->addHours(70)]); // henüz erken
        $this->inTransit($owner, $this->driver(), ['status' => Load::STATUS_DISPUTED], ['status' => Shipment::STATUS_DISPUTED, 'delivered_at' => now()->subHours(50), 'auto_approval_due_at' => now()->addHours(10)]); // uyuşmazlıkta hatırlatma yok

        $svc = app(ShipmentService::class);
        $this->assertSame(1, $svc->remindPendingApprovals());
        $this->assertSame(0, $svc->remindPendingApprovals());
        $this->assertNotNull($shipment->fresh()->approval_reminded_at);
        $this->assertSame(1, UserNotification::query()->where('user_id', $owner->id)->where('title', 'Teslimatı onaylamanız bekleniyor')->count());
    }

    public function test_driver_page_auto_starts_location_sharing_when_arriving_from_the_job_card(): void
    {
        [$load] = $this->inTransit($this->owner(), $driver = $this->driver());
        $this->actingAs($driver);
        $this->get(route('driver.jobs.show', $load->id).'?konum=1')->assertOk()->assertSee('autoStart: true', false);
        $this->get(route('driver.jobs.show', $load->id))->assertOk()->assertSee('autoStart: false', false);
    }

    public function test_cancelled_shipment_stays_visible_in_the_owner_shipment_list(): void
    {
        [$load] = $this->inTransit($owner = $this->owner(), $this->driver(), ['status' => Load::STATUS_CANCELLED, 'escrow_status' => Load::ESCROW_REFUNDED], ['status' => Shipment::STATUS_CANCELLED]);
        $this->actingAs($owner);
        Volt::test('cargo-owner.shipments.index')->assertSee('Ankara Yenimahalle');
    }
}
