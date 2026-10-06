<?php

namespace Tests\Feature\CargoOwner;

use App\Models\CargoOwnerProfile;
use App\Models\DriverProfile;
use App\Models\DriverVehicle;
use App\Models\Load;
use App\Models\Offer;
use App\Models\Review;
use App\Models\User;
use App\Services\PaymentService;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Paket 3: teklif kartında ödenecek toplam ve şoför güveni; ödeme sayfasında son ödeme saati ve iyzico bandı (Y8). */
class OfferCardInfoTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->owner = User::factory()->create(['current_role' => 'cargo_owner']);
        $this->owner->syncRoles(['cargo_owner']);
        CargoOwnerProfile::create(['user_id' => $this->owner->id, 'type' => 'individual']);
        $this->owner = $this->owner->fresh();

        $user = User::factory()->driver()->create();
        $user->syncRoles(['driver']);
        $profile = DriverProfile::create(['user_id' => $user->id, 'kyc_status' => 'approved', 'kyc_verified_at' => now()]);
        DriverVehicle::create(['driver_profile_id' => $profile->id, 'plate' => '34ABC123', 'vehicle_type' => 'tir', 'is_active' => true]);
        $this->driver = $user->fresh();
    }

    public function test_offer_card_shows_total_to_pay_completed_count_and_last_two_reviews(): void
    {
        Settings::set('commission_cargo_owner', '2.5');
        $load = $this->load(['status' => Load::STATUS_ACTIVE, 'price' => 15000]);
        Offer::create(['load_id' => $load->id, 'driver_profile_id' => $this->driver->driverProfile->id, 'amount' => 14000, 'currency' => 'TRY', 'status' => 'pending', 'expires_at' => now()->addDay()]);

        // 3 tamamlanmış sevkiyat + 1 iade ile kapanan (sayılmaz) + başka şoförün 1 sevkiyatı
        foreach (range(1, 3) as $i) {
            $this->load(['status' => Load::STATUS_COMPLETED, 'escrow_status' => Load::ESCROW_RELEASED, 'driver_profile_id' => $this->driver->driverProfile->id]);
        }
        $this->load(['status' => Load::STATUS_COMPLETED, 'escrow_status' => Load::ESCROW_REFUNDED, 'driver_profile_id' => $this->driver->driverProfile->id]);
        $otherDriver = DriverProfile::create(['user_id' => User::factory()->driver()->create()->id, 'kyc_status' => 'approved']);
        $this->load(['status' => Load::STATUS_COMPLETED, 'escrow_status' => Load::ESCROW_RELEASED, 'driver_profile_id' => $otherDriver->id]);

        $done = Load::query()->where('status', Load::STATUS_COMPLETED)->where('driver_profile_id', $this->driver->driverProfile->id)->orderBy('id')->get();
        foreach (['Eski yorum, görünmesin', 'Zamanında geldi, titiz.', 'Yük hasarsız teslim edildi.'] as $i => $comment) {
            Review::create(['load_id' => $done[$i]->id, 'reviewer_id' => $this->owner->id, 'reviewee_id' => $this->driver->id, 'rating' => 5 - $i, 'comment' => $comment, 'created_at' => now()->subDays(10 - $i)]);
        }

        $total = app(PaymentService::class)->amountsFor(14000.0, $this->driver->driverProfile)['total'];
        $this->assertSame(14350.0, $total);

        $this->actingAs($this->owner);
        Volt::test('cargo-owner.loads.offers', ['loadId' => $load->id])
            ->assertSee('Kabul edersen ödeyeceğin toplam')
            ->assertSee('14.350,00 ₺')
            ->assertSee('3 tamamlanmış sevkiyat')
            ->assertSee('Zamanında geldi, titiz.')
            ->assertSee('Yük hasarsız teslim edildi.')
            ->assertDontSee('Eski yorum, görünmesin');
    }

    public function test_offer_card_for_first_time_driver_says_so(): void
    {
        $load = $this->load(['status' => Load::STATUS_ACTIVE]);
        Offer::create(['load_id' => $load->id, 'driver_profile_id' => $this->driver->driverProfile->id, 'amount' => 14000, 'currency' => 'TRY', 'status' => 'pending', 'expires_at' => now()->addDay()]);

        $this->actingAs($this->owner);
        Volt::test('cargo-owner.loads.offers', ['loadId' => $load->id])->assertSee('İlk NavlunIQ sevkiyatı olacak')->assertDontSee('Son yorumlar');
    }

    public function test_payment_page_shows_due_time_and_hides_iyzico_band_when_not_payable_or_not_configured(): void
    {
        config()->set('services.paytr.merchant_id', null);
        $this->actingAs($this->owner);
        $due = now()->addHours(20);
        $load = $this->load(['status' => Load::STATUS_ASSIGNED, 'escrow_status' => Load::ESCROW_PENDING, 'driver_profile_id' => $this->driver->driverProfile->id, 'payment_due_at' => $due]);

        // Ödeme kuruluşu kapalı: son ödeme satırı var, band yok
        $this->get(route('cargo-owner.finance.payment', $load->id))->assertOk()
            ->assertSee('Son ödeme: '.$due->format('d.m H:i'))
            ->assertSee('ilan yeniden havuza döner')
            ->assertDontSee('iyzico-band');

        // Ödeme adımında olmayan ilan: ne son ödeme ne band
        $load->update(['status' => Load::STATUS_ON_THE_WAY, 'escrow_status' => Load::ESCROW_PAID]);
        $this->get(route('cargo-owner.finance.payment', $load->id))->assertOk()
            ->assertSee('Bu ilan ödeme adımında değil')
            ->assertDontSee('Son ödeme:')
            ->assertDontSee('iyzico-band');
    }

    private function load(array $extra = []): Load
    {
        return Load::create(array_merge([
            'cargo_owner_profile_id' => $this->owner->cargoOwnerProfile->id,
            'source_type' => 'internal',
            'visibility' => 'public',
            'pickup_location' => 'Ankara',
            'delivery_location' => 'İzmir',
            'pickup_date' => now()->addDay(),
            'vehicle_type' => 'tir',
            'goods_type' => Load::GOODS_TYPES[0],
            'price' => 14000,
            'status' => Load::STATUS_ACTIVE,
            'escrow_status' => Load::ESCROW_PENDING,
            'published_at' => now(),
        ], $extra));
    }
}
