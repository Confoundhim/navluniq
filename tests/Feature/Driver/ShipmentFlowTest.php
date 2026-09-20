<?php

namespace Tests\Feature\Driver;

use App\Models\CargoOwnerProfile;
use App\Models\DriverProfile;
use App\Models\DriverVehicle;
use App\Models\Load;
use App\Models\Offer;
use App\Models\Shipment;
use App\Models\User;
use App\Services\OfferService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Şoför tarafı sevkiyat akışı: teklif, yola çıkış, teslimat kanıtı ve canlı konum.
 */
class ShipmentFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $driver;

    private Load $load;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->owner = User::factory()->create(['current_role' => 'cargo_owner']);
        $this->owner->syncRoles(['cargo_owner']);
        CargoOwnerProfile::create(['user_id' => $this->owner->id, 'type' => 'individual']);

        $this->driver = User::factory()->driver()->create();
        $this->driver->syncRoles(['driver']);
        $profile = DriverProfile::create(['user_id' => $this->driver->id, 'kyc_status' => 'approved']);
        DriverVehicle::create([
            'driver_profile_id' => $profile->id,
            'plate' => '34ABC123',
            'brand' => 'Ford',
            'model' => 'Cargo',
            'vehicle_type' => 'kamyonet',
            'is_active' => true,
        ]);

        $this->owner = $this->owner->fresh();
        $this->driver = $this->driver->fresh();

        $this->load = Load::create([
            'cargo_owner_profile_id' => $this->owner->cargoOwnerProfile->id,
            'visibility' => 'public',
            'pickup_location' => 'İstanbul',
            'delivery_location' => 'Ankara',
            'pickup_date' => now()->addDay(),
            'vehicle_type' => 'kamyonet',
            'goods_type' => 'Paletli Yük',
            'weight' => 1200,
            'price' => 15000,
            'status' => Load::STATUS_ACTIVE,
            'escrow_status' => Load::ESCROW_PENDING,
            'published_at' => now(),
        ]);
    }

    public function test_driver_submits_offer_from_load_pool(): void
    {
        $this->actingAs($this->driver);

        Volt::test('driver.loads.index')
            ->assertSee('İstanbul')
            ->call('openOffer', $this->load->id)
            ->assertSet('offerModalOpen', true)
            ->set('amount', '14000')
            ->set('estimated_days', '2')
            ->set('message', 'Yarın sabah yükleme yapabilirim.')
            ->call('submitOffer')
            ->assertHasNoErrors()
            ->assertSet('offerModalOpen', false)
            ->assertSet('tab', 'offers')
            ->assertSee('Değerlendiriliyor');

        $this->assertDatabaseHas('offers', [
            'load_id' => $this->load->id,
            'driver_profile_id' => $this->driver->driverProfile->id,
            'status' => 'pending',
            'estimated_days' => 2,
        ]);
    }

    public function test_unapproved_driver_sees_waiting_message_instead_of_loads(): void
    {
        $this->driver->driverProfile->update(['kyc_status' => 'pending']);
        $this->actingAs($this->driver);

        Volt::test('driver.loads.index')
            ->assertSee('Belgeleriniz onay bekliyor')
            ->assertDontSee('İstanbul');
        Volt::test('driver.loads.index')->call('setTab', 'external')
            ->assertSee('Belgeleriniz onay bekliyor')
            ->assertDontSee('izinli dış kaynaklardan');

        $this->driver->driverProfile->update(['kyc_status' => 'approved']);
        Volt::test('driver.loads.index')->assertDontSee('Belgeleriniz onay bekliyor')->assertSee('İstanbul');
    }

    public function test_offer_is_rejected_when_kyc_is_not_approved(): void
    {
        $this->driver->driverProfile->update(['kyc_status' => 'pending']);
        $this->actingAs($this->driver);

        Volt::test('driver.loads.index')
            ->call('openOffer', $this->load->id)
            ->set('amount', '14000')
            ->set('estimated_days', '2')
            ->call('submitOffer')
            ->assertHasErrors(['amount']);

        $this->assertDatabaseCount('offers', 0);
    }

    public function test_driver_withdraws_pending_offer(): void
    {
        $offer = app(OfferService::class)->submit($this->driver->driverProfile, $this->load, 14000, null, 2);
        $this->actingAs($this->driver);

        Volt::test('driver.loads.index')
            ->call('setTab', 'offers')
            ->assertSee('Geri çek')
            ->call('withdrawOffer', $offer->id)
            ->assertSee('Geri çekildi');

        $this->assertSame('withdrawn', $offer->fresh()->status);
    }

    public function test_driver_cannot_start_transit_while_escrow_is_pending(): void
    {
        $shipment = $this->acceptedShipment();
        $this->actingAs($this->driver);

        Volt::test('driver.shipments.show', ['loadId' => $this->load->id])
            ->assertSee('Yük sahibi ödemeyi yapmadan yola çıkamazsınız')
            ->call('startTransit')
            ->assertSee('ödemesini yapmadan yola çıkamazsınız');

        $this->assertSame(Shipment::STATUS_AWAITING_PICKUP, $shipment->fresh()->status);
        $this->assertSame(Load::STATUS_ASSIGNED, $this->load->fresh()->status);
    }

    public function test_driver_starts_transit_and_uploads_proof_of_delivery(): void
    {
        Storage::fake('private');
        Storage::fake('local');

        $shipment = $this->acceptedShipment();
        $this->load->update(['escrow_status' => Load::ESCROW_PAID]);
        $this->actingAs($this->driver);

        $component = Volt::test('driver.shipments.show', ['loadId' => $this->load->id])
            ->assertSee('Yükü aldım, yola çıktım')
            ->call('startTransit');

        $this->assertSame(Shipment::STATUS_IN_TRANSIT, $shipment->fresh()->status);
        $this->assertSame(Load::STATUS_ON_THE_WAY, $this->load->fresh()->status);

        $component
            ->assertSee('Konum paylaşımı')
            ->set('pod_file', UploadedFile::fake()->image('pod.jpg', 640, 480))
            ->set('pod_note', 'Depoya teslim edildi.')
            ->call('markDelivered')
            ->assertHasNoErrors()
            ->assertSee('onayı bekleniyor');

        $shipment = $shipment->fresh();
        $this->assertSame(Shipment::STATUS_DELIVERED, $shipment->status);
        $this->assertNotNull($shipment->auto_approval_due_at);
        $this->assertSame(Load::STATUS_DELIVERED, $this->load->fresh()->status);

        $evidence = $shipment->evidence()->first();
        $this->assertNotNull($evidence);
        $this->assertSame('pod', $evidence->type);
        $this->assertSame('Depoya teslim edildi.', $evidence->metadata['note']);
        Storage::disk('private')->assertExists($evidence->storage_path);
    }

    public function test_location_endpoint_records_driver_position(): void
    {
        $shipment = $this->acceptedShipment();
        $this->load->update(['escrow_status' => Load::ESCROW_PAID, 'status' => Load::STATUS_ON_THE_WAY]);
        $shipment->update(['status' => Shipment::STATUS_IN_TRANSIT, 'in_transit_at' => now()]);

        $this->actingAs($this->driver)
            ->postJson(route('driver.location.store'), [
                'lat' => 40.9923,
                'lng' => 29.1244,
                'speed' => 18.5,
                'heading' => 90,
                'accuracy' => 12,
                'shipment_id' => $shipment->id,
            ])
            ->assertOk()
            ->assertJson(['ok' => true, 'recorded' => true]);

        $this->assertDatabaseHas('driver_locations', [
            'driver_profile_id' => $this->driver->driverProfile->id,
            'shipment_id' => $shipment->id,
        ]);
    }

    public function test_other_driver_is_redirected_from_foreign_shipment(): void
    {
        $this->acceptedShipment();

        $other = User::factory()->driver()->create();
        $other->syncRoles(['driver']);
        DriverProfile::create(['user_id' => $other->id, 'kyc_status' => 'approved']);

        $this->actingAs($other->fresh());

        Volt::test('driver.shipments.show', ['loadId' => $this->load->id])
            ->assertRedirect(route('driver.shipments.index'));
    }

    /** Teklif verilip yük sahibince kabul edilmiş, ödemesi bekleyen sevkiyat üretir. */
    private function acceptedShipment(): Shipment
    {
        $offers = app(OfferService::class);
        $offer = $offers->submit($this->driver->driverProfile, $this->load, 14000, null, 2);

        $shipment = $offers->accept($this->load->fresh(), Offer::findOrFail($offer->id), $this->owner->id);
        $this->load = $this->load->fresh();

        return $shipment;
    }
}
