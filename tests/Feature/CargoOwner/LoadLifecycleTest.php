<?php

namespace Tests\Feature\CargoOwner;

use App\Models\CargoOwnerProfile;
use App\Models\DriverLocation;
use App\Models\DriverProfile;
use App\Models\DriverVehicle;
use App\Models\Load;
use App\Models\Offer;
use App\Models\PaymentOrder;
use App\Models\SavedAddress;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Phone;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class LoadLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_wizard_publishes_a_public_load(): void
    {
        $owner = $this->cargoOwner();

        Volt::test('cargo-owner.loads.create')
            ->set('pickup_location', 'Ostim OSB 1234. Cadde No:12 Yenimahalle / Ankara')
            ->set('delivery_location', 'Aliağa OSB 4. Sokak No:5 Aliağa / İzmir')
            ->set('pickup_date', now()->addDay()->format('Y-m-d'))
            ->set('delivery_date', now()->addDays(3)->format('Y-m-d'))
            ->call('nextStep')
            ->assertHasNoErrors()
            ->assertSet('currentStep', 2)
            ->set('goods_type', Load::GOODS_TYPES[0])
            ->set('vehicle_type', 'tir')
            ->set('weight', '24000')
            ->set('e_irsaliye_no', '')
            ->call('nextStep')
            ->assertHasNoErrors()
            ->assertSet('currentStep', 3)
            ->set('price', '18500')
            ->set('terms_accepted', true)
            ->call('submitLoad')
            ->assertHasNoErrors()
            ->assertRedirect(route('cargo-owner.loads.index'));

        $this->assertDatabaseHas('loads', [
            'cargo_owner_profile_id' => $owner->cargoOwnerProfile->id,
            'status' => Load::STATUS_ACTIVE,
            'escrow_status' => Load::ESCROW_PENDING,
            'visibility' => 'public',
            'vehicle_type' => 'tir',
            'weight' => 24000,
        ]);

        $load = Load::query()->firstOrFail();
        $this->assertSame(18500.0, (float) $load->price);
        $this->assertNotNull($load->published_at);
    }

    public function test_wizard_rejects_price_below_minimum(): void
    {
        $this->cargoOwner();

        Volt::test('cargo-owner.loads.create')
            ->set('pickup_location', 'Ostim OSB 1234. Cadde No:12 Yenimahalle / Ankara')
            ->set('delivery_location', 'Aliağa OSB 4. Sokak No:5 Aliağa / İzmir')
            ->set('pickup_date', now()->addDay()->format('Y-m-d'))
            ->call('nextStep')
            ->set('weight', '1000')
            ->call('nextStep')
            ->set('price', '10')
            ->set('terms_accepted', true)
            ->call('submitLoad')
            ->assertHasErrors(['price']);

        $this->assertDatabaseCount('loads', 0);
    }

    public function test_accepting_an_offer_assigns_driver_and_creates_shipment(): void
    {
        $owner = $this->cargoOwner();
        $load = Load::create([
            'cargo_owner_profile_id' => $owner->cargoOwnerProfile->id,
            'source_type' => 'internal',
            'visibility' => 'public',
            'pickup_location' => 'Ankara',
            'delivery_location' => 'İzmir',
            'pickup_date' => now()->addDay(),
            'vehicle_type' => 'tir',
            'goods_type' => Load::GOODS_TYPES[0],
            'price' => 15000,
            'status' => Load::STATUS_ACTIVE,
            'escrow_status' => Load::ESCROW_PENDING,
            'published_at' => now(),
        ]);

        $driver = $this->approvedDriver();
        $offer = Offer::create([
            'load_id' => $load->id,
            'driver_profile_id' => $driver->driverProfile->id,
            'amount' => 14000,
            'currency' => 'TRY',
            'message' => 'Yarın sabah yüklemeye hazırım.',
            'estimated_days' => 2,
            'status' => 'pending',
            'expires_at' => now()->addDays(2),
        ]);

        $this->actingAs($owner);

        Volt::test('cargo-owner.loads.offers', ['loadId' => $load->id])
            ->assertSee($driver->full_name)
            ->assertSee('Belgeleri doğrulandı')
            ->assertSee('34ABC123')
            ->call('acceptOffer', $offer->id)
            ->assertRedirect(route('cargo-owner.finance.payment', $load->id));

        $load->refresh();
        $this->assertSame(Load::STATUS_ASSIGNED, $load->status);
        $this->assertSame(Load::ESCROW_PENDING, $load->escrow_status);
        $this->assertSame($driver->driverProfile->id, $load->driver_profile_id);
        $this->assertSame(14000.0, (float) $load->price);
        $this->assertSame('accepted', $offer->fresh()->status);

        $shipment = Shipment::query()->where('load_id', $load->id)->firstOrFail();
        $this->assertSame(Shipment::STATUS_AWAITING_PICKUP, $shipment->status);
        $this->assertSame($driver->driverProfile->id, $shipment->driver_profile_id);
        $this->assertSame($offer->id, $shipment->accepted_offer_id);
        $this->assertNotNull($shipment->vehicle_id);
    }

    public function test_offers_page_redirects_for_foreign_load(): void
    {
        $other = $this->cargoOwner();
        $load = Load::create([
            'cargo_owner_profile_id' => $other->cargoOwnerProfile->id,
            'pickup_location' => 'Ankara',
            'delivery_location' => 'İzmir',
            'pickup_date' => now()->addDay(),
            'vehicle_type' => 'tir',
            'goods_type' => Load::GOODS_TYPES[0],
            'price' => 15000,
            'status' => Load::STATUS_ACTIVE,
        ]);

        $this->actingAs($this->cargoOwner());

        Volt::test('cargo-owner.loads.offers', ['loadId' => $load->id])
            ->assertRedirect(route('cargo-owner.loads.index'));
    }

    public function test_address_book_creates_an_address(): void
    {
        $owner = $this->cargoOwner();

        Volt::test('cargo-owner.address-book.index')
            ->call('openCreate')
            ->assertSet('modalOpen', true)
            ->set('title', 'Merkez depo')
            ->set('contact_person', 'Depo Sorumlusu')
            ->set('contact_phone', '0532 100 20 30')
            ->set('city', 'Ankara')
            ->set('district', 'Yenimahalle')
            ->set('address_detail', 'Ostim OSB 1234. Cadde No:12')
            ->set('type', 'pickup')
            ->set('is_default', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('modalOpen', false)
            ->assertSee('Merkez depo');

        $this->assertDatabaseHas('saved_addresses', [
            'user_id' => $owner->id,
            'title' => 'Merkez depo',
            'contact_phone' => '5321002030',
            'type' => 'pickup',
            'is_default' => 1,
        ]);

        $this->assertSame('Ostim OSB 1234. Cadde No:12, Yenimahalle / Ankara', SavedAddress::query()->firstOrFail()->fullAddress());
    }

    public function test_panel_pages_render_with_a_live_shipment(): void
    {
        $owner = $this->cargoOwner();
        $driver = $this->approvedDriver();
        $this->actingAs($owner);

        $load = Load::create([
            'cargo_owner_profile_id' => $owner->cargoOwnerProfile->id,
            'driver_profile_id' => $driver->driverProfile->id,
            'visibility' => 'private',
            'pickup_location' => 'Ankara',
            'delivery_location' => 'İzmir',
            'pickup_date' => now()->addDay(),
            'vehicle_type' => 'tir',
            'goods_type' => Load::GOODS_TYPES[0],
            'price' => 14000,
            'status' => Load::STATUS_ON_THE_WAY,
            'escrow_status' => Load::ESCROW_PAID,
            'published_at' => now()->subDay(),
        ]);
        $shipment = Shipment::create([
            'load_id' => $load->id,
            'driver_profile_id' => $driver->driverProfile->id,
            'vehicle_id' => $driver->driverProfile->activeVehicle->id,
            'status' => Shipment::STATUS_IN_TRANSIT,
            'pickup_confirmed_at' => now()->subHours(3),
            'in_transit_at' => now()->subHours(3),
        ]);
        DriverLocation::create([
            'driver_profile_id' => $driver->driverProfile->id,
            'shipment_id' => $shipment->id,
            'latitude' => 39.15,
            'longitude' => 29.98,
            'recorded_at' => now()->subMinutes(2),
        ]);
        PaymentOrder::create([
            'load_id' => $load->id,
            'user_id' => $owner->id,
            'purpose' => 'escrow',
            'provider' => 'paytr',
            'merchant_oid' => 'NQTEST1',
            'amount' => 14000,
            'currency' => 'TRY',
            'status' => 'paid',
            'paid_at' => now()->subHours(4),
        ]);

        $this->get(route('cargo-owner.shipments.show', $load->id))
            ->assertOk()
            ->assertSee('ownerTrackMap')
            ->assertSee('34ABC123')
            ->assertSee($driver->full_name)
            ->assertSee('Uyuşmazlık aç')
            ->assertSee('tel:0'.Phone::normalize($driver->phone));
        $this->get(route('cargo-owner.shipments.index'))->assertOk()->assertSee('Yolda');
        $this->get(route('cargo-owner.dashboard'))->assertOk()->assertSee('34ABC123');
        $this->get(route('cargo-owner.loads.index'))->assertOk()->assertSee('Sevkiyatı görüntüle');
        $this->get(route('cargo-owner.finance.index'))->assertOk()->assertSee('NQTEST1')->assertSee('14.000,00');
        $this->get(route('cargo-owner.finance.payment', $load->id))->assertOk()->assertSee('Bu ilan ödeme adımında değil');
        $this->get(route('cargo-owner.disputes.index'))->assertOk()->assertSee('Henüz uyuşmazlık kaydınız yok');

        Volt::test('cargo-owner.disputes.index', [])
            ->call('openModal', $load->id)
            ->set('claim', 'Paletlerin bir kısmı hasarlı geldi, fotoğraflar ektedir.')
            ->call('submitDispute')
            ->assertHasNoErrors();

        $this->assertSame(Load::STATUS_DISPUTED, $load->fresh()->status);
        $this->assertSame(Load::ESCROW_ON_HOLD, $load->fresh()->escrow_status);
        $this->get(route('cargo-owner.shipments.show', $load->id))->assertOk()->assertSee('açık bir uyuşmazlık var');
        $this->get(route('cargo-owner.disputes.index'))->assertOk()->assertSee('Ankara')->assertSee('İnceleniyor')->assertSee('Paletlerin bir kısmı hasarlı');
    }

    public function test_payment_page_shows_activation_notice_when_provider_is_not_configured(): void
    {
        config()->set('services.paytr.merchant_id', null);
        $owner = $this->cargoOwner();
        $driver = $this->approvedDriver();
        $this->actingAs($owner);

        $load = Load::create([
            'cargo_owner_profile_id' => $owner->cargoOwnerProfile->id,
            'driver_profile_id' => $driver->driverProfile->id,
            'pickup_location' => 'Ankara',
            'delivery_location' => 'İzmir',
            'pickup_date' => now()->addDay(),
            'vehicle_type' => 'tir',
            'goods_type' => Load::GOODS_TYPES[0],
            'price' => 14000,
            'status' => Load::STATUS_ASSIGNED,
            'escrow_status' => Load::ESCROW_PENDING,
        ]);

        $this->get(route('cargo-owner.finance.payment', $load->id))
            ->assertOk()
            ->assertSee('Ödeme altyapısı aktivasyon aşamasında')
            ->assertDontSee('paytriframe');

        $this->assertDatabaseCount('payment_orders', 0);
    }

    private function cargoOwner(): User
    {
        $user = User::factory()->create(['current_role' => 'cargo_owner']);
        $user->syncRoles(['cargo_owner']);
        CargoOwnerProfile::create(['user_id' => $user->id, 'type' => 'individual']);
        $this->actingAs($user);

        return $user->fresh();
    }

    private function approvedDriver(): User
    {
        $user = User::factory()->driver()->create();
        $user->syncRoles(['driver']);
        $profile = DriverProfile::create(['user_id' => $user->id, 'kyc_status' => 'approved', 'kyc_verified_at' => now()]);
        DriverVehicle::create([
            'driver_profile_id' => $profile->id,
            'plate' => '34ABC123',
            'brand' => 'Mercedes',
            'model' => 'Actros',
            'vehicle_type' => 'tir',
            'is_active' => true,
        ]);

        return $user->fresh();
    }
}
