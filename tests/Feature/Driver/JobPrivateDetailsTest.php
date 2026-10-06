<?php

namespace Tests\Feature\Driver;

use App\Models\CargoOwnerProfile;
use App\Models\DriverProfile;
use App\Models\DriverVehicle;
use App\Models\Load;
use App\Models\Offer;
use App\Models\Shipment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Şoförün iş ayrıntı sayfası: açık adres, yükleme yetkilisi ve yük sahibinin notu yalnız ödeme alındıktan sonra görünür
 * (Paket 3'ün Load::privateAddressFor / pickupContactFor / notesFor yardımcıları şoför tarafına bağlı).
 */
class JobPrivateDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_private_details_appear_on_the_driver_job_page_only_after_payment(): void
    {
        $driver = $this->driver();
        [$load] = $this->assigned($this->owner(), $driver, ['escrow_status' => Load::ESCROW_PENDING, 'status' => Load::STATUS_ASSIGNED]);

        $this->actingAs($driver);
        $this->get(route('driver.jobs.show', $load->id))->assertOk()
            ->assertSee('Ankara Yenimahalle')
            ->assertDontSee('Ostim OSB 1234')
            ->assertDontSee('Aliağa OSB 4')
            ->assertDontSee('Depo Sorumlusu')
            ->assertDontSee('Forklift var')
            ->assertSee('ödeme alındığında burada görünür');

        $load->update(['escrow_status' => Load::ESCROW_PAID, 'status' => Load::STATUS_ON_THE_WAY]);

        $this->get(route('driver.jobs.show', $load->id))->assertOk()
            ->assertSee('Ostim OSB 1234. Cadde No:12 Kapı 3')
            ->assertSee('Aliağa OSB 4. Sokak No:5')
            ->assertSee('Depo Sorumlusu')
            ->assertSee('0312 123 45 67')
            ->assertSee('tel:+903121234567')
            ->assertSee('Forklift var, sabah 08:00 yükleme.')
            ->assertDontSee('ödeme alındığında burada görünür');
    }

    public function test_landline_contact_has_no_whatsapp_button_but_mobile_has(): void
    {
        $driver = $this->driver();
        [$load] = $this->assigned($this->owner(), $driver);

        $this->actingAs($driver);
        $this->get(route('driver.jobs.show', $load->id))->assertOk()->assertDontSee('wa.me/903121234567');

        $load->update(['pickup_contact_phone' => '5321234567']);
        $this->get(route('driver.jobs.show', $load->id))->assertOk()->assertSee('wa.me/905321234567')->assertSee('0532 123 45 67');
    }

    /** @return array{0: Load, 1: Shipment} */
    private function assigned(User $owner, User $driver, array $loadExtra = []): array
    {
        $load = Load::create(array_merge([
            'cargo_owner_profile_id' => $owner->cargoOwnerProfile->id, 'driver_profile_id' => $driver->driverProfile->id, 'source_type' => 'internal', 'visibility' => 'public',
            'pickup_location' => 'Ankara Yenimahalle', 'pickup_province_code' => 6, 'pickup_district' => 'Yenimahalle', 'pickup_lat' => 39.97, 'pickup_lng' => 32.75,
            'delivery_location' => 'İzmir Aliağa', 'delivery_province_code' => 35, 'delivery_district' => 'Aliağa', 'delivery_lat' => 38.80, 'delivery_lng' => 26.97,
            'pickup_address_private' => 'Ostim OSB 1234. Cadde No:12 Kapı 3', 'delivery_address_private' => 'Aliağa OSB 4. Sokak No:5',
            'pickup_contact_name' => 'Depo Sorumlusu', 'pickup_contact_phone' => '3121234567', 'notes' => 'Forklift var, sabah 08:00 yükleme.',
            'pickup_date' => now()->addDay(), 'vehicle_type' => 'tir', 'goods_type' => Load::GOODS_TYPES[0], 'price' => 18500,
            'status' => Load::STATUS_ON_THE_WAY, 'escrow_status' => Load::ESCROW_PAID, 'published_at' => now()->subDay(),
        ], $loadExtra));
        $offer = Offer::create(['load_id' => $load->id, 'driver_profile_id' => $driver->driverProfile->id, 'amount' => 18500, 'currency' => 'TRY', 'status' => 'accepted', 'estimated_days' => 2, 'expires_at' => now()->addDay()]);
        $shipment = Shipment::create(['load_id' => $load->id, 'accepted_offer_id' => $offer->id, 'driver_profile_id' => $driver->driverProfile->id, 'status' => Shipment::STATUS_AWAITING_PICKUP]);

        return [$load, $shipment];
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
}
