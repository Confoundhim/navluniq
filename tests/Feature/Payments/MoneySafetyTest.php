<?php

namespace Tests\Feature\Payments;

use App\Models\CargoOwnerProfile;
use App\Models\DriverProfile;
use App\Models\DriverTrip;
use App\Models\DriverVehicle;
use App\Models\KycDocument;
use App\Models\Load;
use App\Models\PaymentOrder;
use App\Models\Payout;
use App\Models\Shipment;
use App\Models\User;
use App\Models\UserNotification;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\Data\Checkout;
use App\Payments\Data\RefundResult;
use App\Payments\Data\TransferResult;
use App\Payments\Data\WebhookResult;
use App\Payments\GatewayManager;
use App\Services\DisputeService;
use App\Services\KycService;
use App\Services\LoadService;
use App\Services\OfferService;
use App\Services\PaymentService;
use App\Services\ShipmentService;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use RuntimeException;
use Tests\TestCase;

/** İadesi ayarlanabilir sahte ödeme kuruluşu. */
final class RefundableFakeGateway implements PaymentGateway
{
    public bool $refundSucceeds = true;

    public int $refunds = 0;

    public function id(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Sahte Kuruluş';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function isSandbox(): bool
    {
        return true;
    }

    public function createCheckout(PaymentOrder $order, array $context): Checkout
    {
        $order->update(['status' => 'pending', 'request_snapshot' => $context]);

        return new Checkout('iframe', 'https://fake.test/pay/'.$order->merchant_oid, 'tok', 600);
    }

    public function parseWebhook(Request $request): WebhookResult
    {
        $oid = (string) $request->input('merchant_oid');
        $status = (string) $request->input('status', 'success');

        return new WebhookResult($request->input('sig') === 'ok', $oid, $status, $request->input('amount') !== null ? (float) $request->input('amount') : null, $oid.':'.$status, $request->all(), 'fake-ref');
    }

    public function refund(PaymentOrder $order, float $amount): RefundResult
    {
        $this->refunds++;

        return $this->refundSucceeds ? new RefundResult(true, '{"ok":1}') : new RefundResult(false, '{"ok":0}', 'Kuruluş reddetti');
    }

    public function supportsSubMerchants(): bool
    {
        return false;
    }

    public function registerSubMerchant(array $data): ?string
    {
        return null;
    }

    public function transferToSubMerchant(Payout $payout, string $subMerchantRef): TransferResult
    {
        return new TransferResult(false, null, 'desteklenmiyor');
    }
}

/**
 * Yayın öncesi denetim P1-P6: iptal/ödeme yarışı, tutar uyuşmazlığı, ödenmiş ilanda yönetici iptal+iade, reddedilen iadenin
 * takibi, uyuşmazlık kararı bildirimi, belge yükleme bildirimleri, uyuşmazlıkta teslimat kanıtı.
 */
class MoneySafetyTest extends TestCase
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
        Storage::fake('kyc_private');
        config(['services.payment.provider' => 'fake', 'services.payment.vat_rate' => 20]);
        $this->gateway = new RefundableFakeGateway;
        app(GatewayManager::class)->swap('fake', $this->gateway);
        $this->admin = User::factory()->create(['current_role' => 'admin']);
        $this->admin->syncRoles(['super_admin']);
    }

    private function driver(): User
    {
        $user = User::factory()->driver()->create();
        $profile = DriverProfile::create(['user_id' => $user->id, 'kyc_status' => 'approved']);
        DriverVehicle::create(['driver_profile_id' => $profile->id, 'plate' => '34ABC123', 'vehicle_type' => 'tir', 'is_active' => true]);

        return $user;
    }

    private function assignedLoad(User $ownerUser, User $driverUser): Load
    {
        $owner = CargoOwnerProfile::create(['user_id' => $ownerUser->id, 'type' => 'individual']);
        $load = app(LoadService::class)->publish($owner, [
            'pickup_location' => 'İstanbul', 'delivery_location' => 'Ankara', 'pickup_date' => now()->addDay(), 'delivery_date' => now()->addDays(2),
            'vehicle_type' => 'tir', 'goods_type' => 'Paletli Yük', 'weight' => 12000, 'price' => 10000, 'body_types' => ['tenteli'], 'load_kind' => 'komple',
        ]);
        $offer = app(OfferService::class)->submit($driverUser->driverProfile, $load, 10000);
        app(OfferService::class)->accept($load, $offer, $ownerUser->id);

        return $load->fresh();
    }

    private function pay(Load $load, User $owner, ?float $amount = null): PaymentOrder
    {
        $order = app(PaymentService::class)->orderFor($load, $owner);
        app(PaymentService::class)->checkout($order, Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '10.0.0.1']));
        $body = ['merchant_oid' => $order->merchant_oid, 'status' => 'success', 'sig' => 'ok'];
        if ($amount !== null) {
            $body['amount'] = $amount;
        }
        $this->post('/odeme/bildirim/fake', $body)->assertOk();

        return $order->fresh();
    }

    private function notificationsOf(User $user, string $title): Collection
    {
        return UserNotification::query()->where('user_id', $user->id)->where('title', $title)->get();
    }

    public function test_owner_cannot_cancel_during_a_fresh_payment_attempt_and_a_late_payment_on_a_cancelled_load_is_refunded(): void
    {
        $owner = User::factory()->create();
        $driver = $this->driver();
        $load = $this->assignedLoad($owner, $driver);
        $payments = app(PaymentService::class);
        $order = $payments->orderFor($load, $owner);
        $payments->checkout($order, Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '10.0.0.1']));
        $this->assertSame('pending', $order->fresh()->status);

        // Ödeme ekranı az önce açıldı: iptal reddedilir (sağlayıcı sonucu gelmeden para havada kalmasın)
        try {
            app(LoadService::class)->cancel($load, $owner->cargoOwnerProfile);
            $this->fail('iptal reddedilmeliydi');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ödeme işlemi başlatılmış', $e->getMessage());
        }

        // 15 dakikadan eski açık emir: iptal edilir, emir "cancelled" olur
        PaymentOrder::query()->whereKey($order->id)->update(['updated_at' => now()->subMinutes(20)]);
        app(LoadService::class)->cancel($load, $owner->cargoOwnerProfile, 'vazgeçtim');
        $this->assertSame([Load::STATUS_CANCELLED, 'cancelled'], [$load->fresh()->status, $order->fresh()->status]);

        // Sağlayıcıdan gecikmiş "başarılı" bildirimi gelir: para havuza girmez, iade edilir, yük sahibi bilgilendirilir
        $this->post('/odeme/bildirim/fake', ['merchant_oid' => $order->merchant_oid, 'status' => 'success', 'sig' => 'ok'])->assertOk();
        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertSame(1, $this->gateway->refunds);
        $this->assertSame(Load::ESCROW_PENDING, $load->fresh()->escrow_status, 'iptal edilmiş ilanın havuzu "ödendi" olmaz');
        $this->assertCount(1, $this->notificationsOf($owner, 'Ödemeniz iade edildi'));
    }

    public function test_amount_mismatch_is_rejected_and_reported_instead_of_being_accepted_as_paid(): void
    {
        $owner = User::factory()->create();
        $load = $this->assignedLoad($owner, $this->driver());
        $order = $this->pay($load, $owner, 9000.0); // sipariş 10.000, sağlayıcı 9.000 bildirdi

        $this->assertSame('failed', $order->status);
        $this->assertStringContainsString('Tutar uyuşmazlığı', (string) $order->failure_message);
        $this->assertSame(Load::ESCROW_PENDING, $load->fresh()->escrow_status);
        $this->assertCount(1, $this->notificationsOf($this->admin, 'Ödeme tutarı uyuşmuyor'));
        $this->assertCount(1, $this->notificationsOf($owner, 'Ödemeniz doğrulanamadı'));

        // Doğru tutarlı yeni bildirim (yeni emir) yine ödeme alır
        $this->assertSame('paid', $this->pay($load, $owner, 10000.0)->status);
        $this->assertSame(Load::ESCROW_PAID, $load->fresh()->escrow_status);
    }

    public function test_admin_cancels_a_paid_load_with_refund_from_the_operations_screen(): void
    {
        $owner = User::factory()->create();
        $driver = $this->driver();
        $load = $this->assignedLoad($owner, $driver);
        $order = $this->pay($load, $owner);
        $this->assertSame('paid', $order->status);
        $this->assertNotNull(DriverTrip::query()->where('load_id', $load->id)->first());

        $this->actingAs($this->admin);
        Volt::test('admin.operations-center')->call('select', $load->id)->assertSee('İptal et ve iade et')
            ->set('suspendReason', 'Şoför ulaşılamadı, yük sahibi vazgeçti')->call('cancelPaid')->assertHasNoErrors();

        $load->refresh();
        $this->assertSame([Load::STATUS_CANCELLED, Load::ESCROW_REFUNDED, 'refunded'], [$load->status, $load->escrow_status, $order->fresh()->status]);
        $this->assertSame(Shipment::STATUS_CANCELLED, $load->shipment->status);
        $this->assertSame(DriverTrip::STATUS_CLOSED, DriverTrip::query()->where('load_id', $load->id)->value('status'));
        $this->assertCount(1, $this->notificationsOf($owner, 'Sevkiyat iptal edildi, navlun bedeli iade ediliyor'));
        $this->assertCount(1, $this->notificationsOf($driver, 'Sevkiyat iptal edildi'));
        $this->assertSame(0, Payout::query()->count());
    }

    public function test_refused_refund_is_tracked_and_completed_manually_from_the_finance_screen(): void
    {
        $this->gateway->refundSucceeds = false;
        $owner = User::factory()->create();
        $driver = $this->driver();
        $load = $this->assignedLoad($owner, $driver);
        $order = $this->pay($load, $owner);

        // Yolda uyuşmazlık → hakem yük sahibine iade dedi → kuruluş reddetti
        $shipments = app(ShipmentService::class);
        $shipments->startTransit($load->fresh()->shipment, $driver->driverProfile);
        $dispute = app(DisputeService::class)->open($load->fresh(), $owner, 'Yük hasarlı geldi');
        // Uyuşmazlık açıkken şoför teslimat kanıtını yine yükleyebilir; durum uyuşmazlık kalır, otomatik onay işlemez
        $shipments->markDelivered($load->fresh()->shipment, $driver->driverProfile, UploadedFile::fake()->image('pod.jpg'), 'Teslim edildi');
        $this->assertSame([Shipment::STATUS_DISPUTED, Load::STATUS_DISPUTED], [$load->fresh()->shipment->status, $load->fresh()->status]);
        $this->assertNotNull($load->fresh()->shipment->delivered_at);
        $this->assertNull($load->fresh()->shipment->auto_approval_due_at);

        app(DisputeService::class)->resolve($dispute, $this->admin, 'owner_refunded', 'Hasar fotoğrafı açık');
        $load->refresh();
        $this->assertSame('refund_pending', $order->fresh()->status);
        $this->assertSame(Load::ESCROW_ON_HOLD, $load->escrow_status, 'para gitmeden "iade edildi" denmez');
        $this->assertCount(1, $this->notificationsOf($this->admin, 'İade tamamlanamadı, elle yapılmalı'));
        $decision = $this->notificationsOf($owner, 'Uyuşmazlık karara bağlandı')->first();
        $this->assertStringStartsWith('http', (string) $decision->action_url, 'karar bildiriminin bağlantısı gerçek adres olmalı');
        $this->assertSame('dispute', $decision->type);
        $this->assertStringContainsString('finans ekibi tarafından tamamlanacak', implode(' ', (array) $decision->lines));

        // Finans ekibi sağlayıcı panelinden iade etti, ekranda "İade yapıldı" dedi
        $this->actingAs($this->admin);
        Volt::test('admin.finance-manager')->set('activeTab', 'orders')->assertSee('İade yapıldı')
            ->set('reference.order-'.$order->id, 'IADE-123')->call('markRefunded', $order->id)->assertHasNoErrors();
        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertSame(Load::ESCROW_REFUNDED, $load->fresh()->escrow_status);
        $this->assertCount(1, $this->notificationsOf($owner, 'İadeniz tamamlandı'));
    }

    public function test_dispute_in_favor_of_driver_creates_payout_outside_the_lock_and_links_both_parties(): void
    {
        $owner = User::factory()->create();
        $driver = $this->driver();
        $load = $this->assignedLoad($owner, $driver);
        $this->pay($load, $owner);
        $shipments = app(ShipmentService::class);
        $shipments->startTransit($load->fresh()->shipment, $driver->driverProfile);
        $shipments->markDelivered($load->fresh()->shipment, $driver->driverProfile, UploadedFile::fake()->image('pod.jpg'));
        $dispute = app(DisputeService::class)->open($load->fresh(), $owner, 'Geç geldi');
        app(DisputeService::class)->resolve($dispute, $this->admin, 'driver_paid', 'Teslim kanıtı yeterli');

        $this->assertSame(1, Payout::query()->where('load_id', $load->id)->count());
        $this->assertSame(Load::ESCROW_RELEASE_APPROVED, $load->fresh()->escrow_status);
        $this->assertStringContainsString('/uyusmazlik', (string) $this->notificationsOf($driver, 'Uyuşmazlık karara bağlandı')->first()->action_url);
    }

    public function test_kyc_upload_notifies_the_user_and_the_review_team(): void
    {
        $user = User::factory()->driver()->create();
        DriverProfile::create(['user_id' => $user->id, 'kyc_status' => 'not_submitted']);
        $kyc = app(KycService::class);
        foreach (KycDocument::DRIVER_REQUIRED as $type) {
            $kyc->upload($user, 'driver', $type, UploadedFile::fake()->image($type.'.jpg'));
        }
        $this->assertSame('pending', $user->driverProfile->fresh()->kyc_status);
        $this->assertCount(1, $this->notificationsOf($user, 'Belgeleriniz alındı'));
        $this->assertCount(1, $this->notificationsOf($this->admin, 'Yeni belge incelemesi bekliyor'));
    }

    public function test_repeat_keeps_body_type_and_load_kind(): void
    {
        $owner = User::factory()->create();
        $load = $this->assignedLoad($owner, $this->driver());
        $again = app(LoadService::class)->repeat($load, $owner->cargoOwnerProfile);
        $this->assertSame([['tenteli'], 'komple'], [$again->body_types, $again->load_kind]);
    }
}
