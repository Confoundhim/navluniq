<?php

namespace Tests\Feature\Loads;

use App\Models\BankAccount;
use App\Models\CargoOwnerProfile;
use App\Models\DriverProfile;
use App\Models\DriverTrip;
use App\Models\DriverVehicle;
use App\Models\Load;
use App\Models\PaymentOrder;
use App\Models\Shipment;
use App\Models\User;
use App\Models\UserNotification;
use App\Payments\GatewayManager;
use App\Services\LoadService;
use App\Services\OfferService;
use App\Services\PaymentService;
use App\Services\ShipmentService;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use RuntimeException;
use Tests\Feature\Payments\RefundableFakeGateway;
use Tests\TestCase;

/**
 * İptal ve iade politikası (Osman'ın kararı 4, 2026-10-05): yola çıkılmadan yük sahibi tam iadeyle iptal eder, şoför ödeme
 * sonrası vazgeçebilir (tam iade), "şoför gelmedi" zaman aşımı; yola çıkıldıktan sonra yalnız uyuşmazlık.
 */
class CancelRefundPolicyTest extends TestCase
{
    use RefreshDatabase;

    private RefundableFakeGateway $gateway;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Settings::set('scraper_free_delay_minutes', '0');
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
        Storage::fake('private');
        config(['services.payment.provider' => 'fake', 'services.payment.vat_rate' => 20]);
        $this->gateway = new RefundableFakeGateway;
        app(GatewayManager::class)->swap('fake', $this->gateway);
        $this->admin = User::factory()->create(['current_role' => 'admin']);
        $this->admin->syncRoles(['super_admin']);
    }

    private function driver(): User
    {
        $user = User::factory()->driver()->create();
        $profile = DriverProfile::create(['user_id' => $user->id, 'kyc_status' => 'approved', 'identity_number' => '10000000146']);
        DriverVehicle::create(['driver_profile_id' => $profile->id, 'plate' => '16CR'.$user->id, 'vehicle_type' => 'tir', 'is_active' => true]);
        $iban = 'TR330006100519786457841326';
        BankAccount::create(['user_id' => $user->id, 'encrypted_iban' => Crypt::encryptString($iban), 'iban_hash' => hash('sha256', $iban.$user->id), 'iban_last4' => '1326', 'account_holder' => 'Test Şoför', 'is_default' => true]);

        return $user->fresh();
    }

    private function paidLoad(User $ownerUser, User $driverUser, array $overrides = []): array
    {
        $owner = CargoOwnerProfile::query()->firstOrCreate(['user_id' => $ownerUser->id], ['type' => 'individual']);
        $load = app(LoadService::class)->publish($owner, array_merge([
            'pickup_location' => 'İstanbul', 'delivery_location' => 'Ankara', 'pickup_date' => now()->addDay(), 'delivery_date' => now()->addDays(2),
            'vehicle_type' => 'tir', 'goods_type' => 'Paletli Yük', 'weight' => 12000, 'price' => 10000,
        ], $overrides));
        $offer = app(OfferService::class)->submit($driverUser->driverProfile, $load, 10000);
        app(OfferService::class)->accept($load, $offer, $ownerUser->id);
        $order = app(PaymentService::class)->orderFor($load->fresh(), $ownerUser);
        app(PaymentService::class)->checkout($order, Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '10.0.0.1']));
        $this->post('/odeme/bildirim/fake', ['merchant_oid' => $order->merchant_oid, 'status' => 'success', 'sig' => 'ok'])->assertOk();

        return [$load->fresh(), $offer->fresh(), $order->fresh()];
    }

    private function titles(User $user): array
    {
        return UserNotification::query()->where('user_id', $user->id)->pluck('title')->all();
    }

    public function test_owner_cancels_a_paid_shipment_before_transit_with_full_refund_from_the_shipment_page(): void
    {
        $owner = User::factory()->create();
        $driver = $this->driver();
        [$load, $offer, $order] = $this->paidLoad($owner, $driver);
        $this->assertTrue($load->canBeCancelledBeforeTransit());

        // Eski iptal yolu artık doğru düğmeye yönlendirir
        try {
            app(LoadService::class)->cancel($load, $owner->fresh()->cargoOwnerProfile);
            $this->fail('ödenmiş ilan genel iptalle kapanmamalı');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('İptal et ve iade al', $e->getMessage());
        }

        $this->actingAs($owner->fresh());
        Volt::test('cargo-owner.shipments.show', ['loadId' => $load->id])->assertSee('İptal et ve iade al')
            ->set('cancel_reason', 'Yük başka araçla gitti')->call('cancelPaid')->assertSee('iade edildi');

        $load->refresh();
        $this->assertSame([Load::STATUS_CANCELLED, Load::ESCROW_REFUNDED, 'refunded', 'rejected'], [$load->status, $load->escrow_status, $order->fresh()->status, $offer->fresh()->status]);
        $this->assertSame([10000.0], $this->gateway->refundedAmounts, 'tam iade');
        $this->assertSame(Shipment::STATUS_CANCELLED, $load->shipment->status);
        $this->assertSame(DriverTrip::STATUS_CLOSED, DriverTrip::query()->where('load_id', $load->id)->value('status'));
        $this->assertSame('İade ile kapandı', $load->statusLabel());
        $this->assertContains('Yük sahibi sevkiyatı iptal etti', $this->titles($driver));
        $this->assertContains('Sevkiyat iptal edildi, navlun bedeli iade edildi', $this->titles($owner));
        $this->assertStringContainsString('Yük başka araçla gitti', implode(' ', (array) UserNotification::query()->where('user_id', $driver->id)->latest('id')->first()->lines));
    }

    public function test_paid_cancel_is_impossible_after_transit_and_refused_refund_becomes_refund_pending(): void
    {
        $owner = User::factory()->create();
        $driver = $this->driver();
        [$load] = $this->paidLoad($owner, $driver);
        app(ShipmentService::class)->startTransit($load->shipment, $driver->driverProfile);

        $this->actingAs($owner->fresh());
        Volt::test('cargo-owner.shipments.show', ['loadId' => $load->id])->assertDontSee('İptal et ve iade al')->assertSee('Uyuşmazlık aç');
        try {
            app(LoadService::class)->cancelByOwnerPaid($load->fresh(), $owner->fresh()->cargoOwnerProfile);
            $this->fail('yola çıkmış sevkiyat iptal edilememeli');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('yola çıkmamış', $e->getMessage());
        }

        // Kuruluş iadeyi reddeder: havuz "iade bekleniyor", finans "İade yapıldı" deyince kapanır
        $this->gateway->refundSucceeds = false;
        [$second, , $order2] = $this->paidLoad(User::factory()->create(), $driver);
        $secondOwner = $second->cargoOwnerProfile->user;
        $this->assertFalse(app(LoadService::class)->cancelByOwnerPaid($second, $second->cargoOwnerProfile, 'vazgeçtim'));
        $second->refresh();
        $this->assertSame([Load::STATUS_CANCELLED, Load::ESCROW_REFUND_PENDING, 'refund_pending'], [$second->status, $second->escrow_status, $order2->fresh()->status]);
        $this->assertSame('İade bekleniyor', $second->statusLabel());
        $this->assertContains('Sevkiyat iptal edildi, iade başlatıldı', $this->titles($secondOwner));
        $this->assertContains('İade tamamlanamadı, elle yapılmalı', $this->titles($this->admin));

        app(PaymentService::class)->markRefundedManually($order2->fresh(), $this->admin, 'IADE-7');
        $this->assertSame([Load::ESCROW_REFUNDED, 'refunded'], [$second->fresh()->escrow_status, $order2->fresh()->status]);
        $this->assertSame('İade ile kapandı', $second->fresh()->statusLabel());
    }

    public function test_driver_withdraws_after_payment_before_transit_and_the_owner_is_refunded(): void
    {
        $owner = User::factory()->create();
        $driver = $this->driver();
        [$load, $offer, $order] = $this->paidLoad($owner, $driver);
        $trip = DriverTrip::query()->where('load_id', $load->id)->firstOrFail();

        $this->actingAs($driver->fresh());
        Volt::test('driver.jobs.index')->assertSee('Vazgeç')->call('withdrawJob', $trip->id)->assertSee('Vazgeçtiniz');

        $load->refresh();
        $this->assertSame([Load::STATUS_ACTIVE, Load::ESCROW_PENDING, 'public', null], [$load->status, $load->escrow_status, $load->visibility, $load->driver_profile_id]);
        $this->assertSame(['withdrawn', 'refunded', DriverTrip::STATUS_CLOSED], [$offer->fresh()->status, $order->fresh()->status, $trip->fresh()->status]);
        $this->assertSame([10000.0], $this->gateway->refundedAmounts);
        $this->assertSame(1, $driver->driverProfile->fresh()->withdrawals_after_payment);
        $this->assertContains('Navlun bedeliniz iade edildi', $this->titles($owner));
        $this->assertContains('Şoför ataması kaldırıldı, ilanınız yeniden havuzda', $this->titles($owner));

        // İlan yeniden teklif alır, yeni kabul yeni ödeme emri açar
        $other = $this->driver();
        $offer2 = app(OfferService::class)->submit($other->driverProfile, $load, 9800);
        app(OfferService::class)->accept($load->fresh(), $offer2, $owner->id);
        $order2 = app(PaymentService::class)->orderFor($load->fresh(), $owner);
        $this->assertNotSame($order->id, $order2->id);
        $this->assertSame(9800.0, (float) $order2->amount);
    }

    public function test_no_show_timeout_notifies_owner_driver_and_operations_once(): void
    {
        Settings::set('no_show_grace_days', '1');
        $owner = User::factory()->create();
        $driver = $this->driver();
        [$load] = $this->paidLoad($owner, $driver);
        Load::query()->whereKey($load->id)->update(['pickup_date' => now()->subDays(3)]);
        [$fresh] = $this->paidLoad(User::factory()->create(), $this->driver()); // yükleme tarihi gelecekte: uyarılmaz

        Artisan::call('loads:no-show');
        $this->assertNotNull($load->fresh()->no_show_notified_at);
        $this->assertNull($fresh->fresh()->no_show_notified_at);
        $this->assertContains('Şoför yükü henüz almadı', $this->titles($owner));
        $this->assertStringContainsString('İptal et ve iade al', implode(' ', (array) UserNotification::query()->where('user_id', $owner->id)->where('title', 'Şoför yükü henüz almadı')->first()->lines));
        $this->assertContains('Yükleme tarihi geçti, yola çıkmadınız', $this->titles($driver));
        $this->assertContains('Şoför gelmedi şüphesi', $this->titles($this->admin));

        // Bir kez: ikinci çalıştırma yeni bildirim üretmez
        $this->assertSame(0, app(LoadService::class)->notifyNoShows());
        $this->assertSame(1, UserNotification::query()->where('user_id', $owner->id)->where('title', 'Şoför yükü henüz almadı')->count());

        // Sevkiyat sayfasında uyarı + iptal düğmesi
        $this->actingAs($owner->fresh());
        Volt::test('cargo-owner.shipments.show', ['loadId' => $load->id])->assertSee('şoför hâlâ yola çıkmadı')->assertSee('İptal et ve iade al');
    }

    public function test_admin_cancel_with_refused_refund_marks_refund_pending_instead_of_leaving_paid(): void
    {
        $this->gateway->refundSucceeds = false;
        $owner = User::factory()->create();
        [$load, , $order] = $this->paidLoad($owner, $this->driver());
        $this->assertFalse(app(LoadService::class)->cancelPaid($load, $this->admin, 'Şoföre ulaşılamadı'));
        $this->assertSame([Load::STATUS_CANCELLED, Load::ESCROW_REFUND_PENDING, 'refund_pending'], [$load->fresh()->status, $load->fresh()->escrow_status, $order->fresh()->status]);
        $this->assertSame(0, PaymentOrder::query()->where('status', 'paid')->count());
    }
}
