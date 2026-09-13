<?php

namespace Tests\Feature\Services;

use App\Models\CargoOwnerProfile;
use App\Models\DriverProfile;
use App\Models\DriverVehicle;
use App\Models\Load;
use App\Models\Offer;
use App\Models\Payout;
use App\Models\Shipment;
use App\Models\User;
use App\Services\DisputeService;
use App\Services\LoadService;
use App\Services\OfferService;
use App\Services\PaymentService;
use App\Services\ReviewService;
use App\Services\ShipmentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * İlan → teklif → kabul → ödeme → sevkiyat → teslimat → onay → hakediş akışının uçtan uca doğrulaması.
 */
class MarketplaceFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $ownerUser;

    private CargoOwnerProfile $owner;

    private User $driverUser;

    private DriverProfile $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
        Storage::fake('private');

        $this->ownerUser = User::factory()->create();
        $this->owner = CargoOwnerProfile::create(['user_id' => $this->ownerUser->id, 'type' => 'individual']);

        $this->driverUser = User::factory()->driver()->create();
        $this->driver = DriverProfile::create(['user_id' => $this->driverUser->id, 'kyc_status' => 'approved']);
        DriverVehicle::create(['driver_profile_id' => $this->driver->id, 'plate' => '34ABC123', 'brand' => 'Ford', 'model' => 'Cargo', 'vehicle_type' => 'tir', 'is_active' => true]);
    }

    private function publishLoad(): Load
    {
        return app(LoadService::class)->publish($this->owner, [
            'pickup_location' => 'İstanbul',
            'delivery_location' => 'Ankara',
            'pickup_date' => now()->addDay(),
            'delivery_date' => now()->addDays(2),
            'vehicle_type' => 'tir',
            'goods_type' => 'Paletli Yük',
            'weight' => 12000,
            'price' => 15000,
        ]);
    }

    public function test_published_load_is_visible_to_drivers(): void
    {
        $load = $this->publishLoad();

        $this->assertSame('public', $load->visibility);
        $this->assertSame(Load::STATUS_ACTIVE, $load->status);
        $this->assertNotNull($load->published_at);
        $this->assertSame(1, Load::query()->where('status', Load::STATUS_ACTIVE)->where('visibility', 'public')->count());
    }

    public function test_load_below_minimum_price_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        app(LoadService::class)->publish($this->owner, [
            'pickup_location' => 'İstanbul', 'delivery_location' => 'Ankara', 'pickup_date' => now()->addDay(),
            'vehicle_type' => 'tir', 'goods_type' => 'Paletli Yük', 'price' => 10,
        ]);
    }

    public function test_unverified_driver_cannot_offer(): void
    {
        $load = $this->publishLoad();
        $this->driver->update(['kyc_status' => 'pending']);

        $this->expectException(RuntimeException::class);
        app(OfferService::class)->submit($this->driver->fresh(), $load, 14000);
    }

    public function test_full_lifecycle_until_payout(): void
    {
        $load = $this->publishLoad();
        $offers = app(OfferService::class);

        $offer = $offers->submit($this->driver, $load, 14000, 'Yarın sabah yükleyebilirim', 2);
        $this->assertSame('pending', $offer->status);

        // Aynı ilana ikinci aktif teklif engellenir.
        try {
            $offers->submit($this->driver, $load, 13000);
            $this->fail('İkinci teklif engellenmeliydi');
        } catch (RuntimeException) {
        }

        $shipment = $offers->accept($load, $offer, $this->ownerUser->id);
        $load->refresh();
        $this->assertSame(Load::STATUS_ASSIGNED, $load->status);
        $this->assertSame(Load::ESCROW_PENDING, $load->escrow_status);
        $this->assertSame('14000.00', (string) $load->price);
        $this->assertSame(Shipment::STATUS_AWAITING_PICKUP, $shipment->status);
        $this->assertSame('private', $load->visibility);

        // Ödeme alınmadan yola çıkılamaz.
        $shipments = app(ShipmentService::class);
        try {
            $shipments->startTransit($shipment, $this->driver);
            $this->fail('Ödeme olmadan yola çıkılmamalıydı');
        } catch (RuntimeException) {
        }

        // PayTR callback simülasyonu: imzalı bildirim.
        config(['services.paytr.merchant_id' => '1', 'services.paytr.merchant_key' => 'key', 'services.paytr.merchant_salt' => 'salt']);
        $payments = app(PaymentService::class);
        $order = $payments->orderFor($load, $this->ownerUser);
        $total = (string) (int) round($order->amount * 100);
        $hash = base64_encode(hash_hmac('sha256', $order->merchant_oid.'salt'.'success'.$total, 'key', true));

        $response = $this->post(route('payment.paytr.callback'), [
            'merchant_oid' => $order->merchant_oid, 'status' => 'success', 'total_amount' => $total, 'hash' => $hash, 'payment_type' => 'card',
        ]);
        $response->assertOk()->assertSee('OK');

        // Aynı bildirim ikinci kez gelirse çift işlem olmaz.
        $this->post(route('payment.paytr.callback'), [
            'merchant_oid' => $order->merchant_oid, 'status' => 'success', 'total_amount' => $total, 'hash' => $hash,
        ])->assertOk();

        $load->refresh();
        $this->assertSame(Load::ESCROW_PAID, $load->escrow_status);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(1, $order->events()->count());

        $shipments->startTransit($shipment->fresh(), $this->driver);
        $this->assertSame(Load::STATUS_ON_THE_WAY, $load->fresh()->status);

        $shipments->markDelivered($shipment->fresh(), $this->driver, UploadedFile::fake()->image('pod.jpg'), 'Teslim edildi');
        $this->assertSame(Load::STATUS_DELIVERED, $load->fresh()->status);
        $this->assertSame(1, $shipment->evidence()->count());
        Storage::disk('private')->assertExists($shipment->evidence()->first()->storage_path);

        $shipments->approveDelivery($shipment->fresh(), $this->ownerUser);
        $load->refresh();
        $this->assertSame(Load::STATUS_COMPLETED, $load->status);
        $this->assertSame(Load::ESCROW_RELEASE_APPROVED, $load->escrow_status);

        $payout = Payout::query()->where('load_id', $load->id)->first();
        $this->assertNotNull($payout);
        $this->assertSame('pending', $payout->status);
        $this->assertEquals(14000 * 0.05, (float) $payout->commission_amount);
        $this->assertEquals(14000 * 0.95, (float) $payout->net_amount);

        $review = app(ReviewService::class)->submit($load, $this->ownerUser, 5, 'Sorunsuz');
        $this->assertSame($this->driverUser->id, $review->reviewee_id);
        $this->assertSame(5.0, $this->driverUser->averageRating());
    }

    public function test_callback_with_bad_hash_is_rejected(): void
    {
        config(['services.paytr.merchant_id' => '1', 'services.paytr.merchant_key' => 'key', 'services.paytr.merchant_salt' => 'salt']);
        $load = $this->publishLoad();
        $offer = app(OfferService::class)->submit($this->driver, $load, 14000);
        app(OfferService::class)->accept($load, $offer, $this->ownerUser->id);
        $order = app(PaymentService::class)->orderFor($load->fresh(), $this->ownerUser);

        $this->post(route('payment.paytr.callback'), [
            'merchant_oid' => $order->merchant_oid, 'status' => 'success', 'total_amount' => '1400000', 'hash' => 'bozuk',
        ])->assertOk()->assertSee('bad hash');

        $this->assertSame(Load::ESCROW_PENDING, $load->fresh()->escrow_status);
    }

    public function test_dispute_locks_escrow_and_resolution_pays_driver(): void
    {
        $load = $this->publishLoad();
        $offer = app(OfferService::class)->submit($this->driver, $load, 14000);
        $shipment = app(OfferService::class)->accept($load, $offer, $this->ownerUser->id);
        $load->refresh()->update(['escrow_status' => Load::ESCROW_PAID]);

        app(ShipmentService::class)->startTransit($shipment->fresh(), $this->driver);

        $admin = User::factory()->create(['current_role' => 'admin']);
        $admin->syncRoles(['super_admin']);

        $dispute = app(DisputeService::class)->open($load->fresh(), $this->ownerUser, 'Yük hasarlı geldi, fotoğraflar ekte.');
        $this->assertSame(Load::ESCROW_ON_HOLD, $load->fresh()->escrow_status);
        $this->assertSame(Load::STATUS_DISPUTED, $load->fresh()->status);

        // Uyuşmazlık açıkken teslimat onaylanamaz.
        try {
            app(ShipmentService::class)->approveDelivery($shipment->fresh(), $this->ownerUser);
            $this->fail('Uyuşmazlık varken onay verilmemeliydi');
        } catch (RuntimeException) {
        }

        app(DisputeService::class)->defend($dispute, $this->driver, 'Yük teslim anında sağlamdı, tutanak imzalandı.');
        app(DisputeService::class)->resolve($dispute->fresh(), $admin, 'driver_paid', 'Teslim tutanağı geçerli.');

        $this->assertSame('resolved_driver_paid', $dispute->fresh()->status);
        $this->assertSame(Load::ESCROW_RELEASE_APPROVED, $load->fresh()->escrow_status);
        $this->assertSame(1, Payout::query()->where('load_id', $load->id)->count());
    }

    public function test_owner_can_cancel_unpaid_load_but_not_paid_one(): void
    {
        $load = $this->publishLoad();
        $offer = app(OfferService::class)->submit($this->driver, $load, 14000);
        app(LoadService::class)->cancel($load, $this->owner, 'Vazgeçtim');
        $this->assertSame(Load::STATUS_CANCELLED, $load->fresh()->status);
        $this->assertSame('rejected', $offer->fresh()->status);

        $paid = $this->publishLoad();
        $paid->update(['status' => Load::STATUS_ASSIGNED, 'escrow_status' => Load::ESCROW_PAID]);
        $this->expectException(RuntimeException::class);
        app(LoadService::class)->cancel($paid, $this->owner);
    }

    public function test_expired_offers_are_closed_by_scheduler(): void
    {
        $load = $this->publishLoad();
        $offer = app(OfferService::class)->submit($this->driver, $load, 14000);
        Offer::query()->whereKey($offer->id)->update(['expires_at' => now()->subHour()]);

        $this->artisan('offers:expire')->assertSuccessful();
        $this->assertSame('expired', $offer->fresh()->status);
    }
}
