<?php

namespace Tests\Feature\Payments;

use App\Models\CargoOwnerProfile;
use App\Models\CmsContent;
use App\Models\DriverProfile;
use App\Models\DriverVehicle;
use App\Models\Invoice;
use App\Models\Load;
use App\Models\PaymentOrder;
use App\Models\Payout;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Models\Subscription;
use App\Models\SubscriptionCycle;
use App\Models\User;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\Data\Checkout;
use App\Payments\Data\RefundResult;
use App\Payments\Data\TransferResult;
use App\Payments\Data\WebhookResult;
use App\Payments\GatewayManager;
use App\Services\LoadService;
use App\Services\OfferService;
use App\Services\PaymentService;
use App\Services\ScrapedLoadService;
use App\Services\ShipmentService;
use App\Services\SubscriptionService;
use App\Support\Company;
use App\Support\PaymentReadiness;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sahte ödeme kuruluşu: iframe ekranı verir, "merchant_oid" ve "status" alanlı bildirimi imzasız kabul eder,
 * pazaryeri aktarımını destekler. Sağlayıcıdan bağımsız akışın tamamı bununla sınanır.
 */
final class FakeGateway implements PaymentGateway
{
    public array $transfers = [];

    public function __construct(public bool $marketplace = false) {}

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
        return new RefundResult(true, '{"ok":1}');
    }

    public function supportsSubMerchants(): bool
    {
        return $this->marketplace;
    }

    public function registerSubMerchant(array $data): ?string
    {
        return 'SUB-'.$data['iban'];
    }

    public function transferToSubMerchant(Payout $payout, string $subMerchantRef): TransferResult
    {
        $this->transfers[] = [$payout->id, $subMerchantRef];

        return new TransferResult(true, 'TRF-'.$payout->id);
    }
}

class PaymentInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    private FakeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
        Storage::fake('private');
        config(['services.payment.provider' => 'fake', 'services.payment.vat_rate' => 20]);
        $this->gateway = new FakeGateway;
        app(GatewayManager::class)->swap('fake', $this->gateway);
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
            'vehicle_type' => 'tir', 'goods_type' => 'Paletli Yük', 'weight' => 12000, 'price' => 10000,
        ]);
        $offer = app(OfferService::class)->submit($driverUser->driverProfile, $load, 10000);
        app(OfferService::class)->accept($load, $offer, $ownerUser->id);

        return $load->fresh();
    }

    public function test_without_keys_the_null_gateway_keeps_payments_closed(): void
    {
        config(['services.payment.provider' => 'paytr', 'services.paytr.merchant_id' => null, 'services.paytr.merchant_key' => null, 'services.paytr.merchant_salt' => null]);
        $payments = app(PaymentService::class);
        $this->assertFalse($payments->isConfigured());
        $this->assertSame('none', $payments->gateway()->id());

        $this->post('/odeme/bildirim/paytr', ['merchant_oid' => 'x'])->assertOk()->assertSee('bad hash');
        $this->post('/odeme/bildirim/bilinmeyen', ['merchant_oid' => 'x'])->assertOk();
    }

    public function test_escrow_checkout_and_generic_webhook_mark_load_paid_once(): void
    {
        $owner = User::factory()->create();
        $driver = $this->driver();
        $load = $this->assignedLoad($owner, $driver);
        $payments = app(PaymentService::class);

        $order = $payments->orderFor($load, $owner);
        $this->assertSame('fake', $order->provider);
        $checkout = $payments->checkout($order, Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '10.0.0.1']));
        $this->assertSame('iframe', $checkout->type);
        $this->assertStringContainsString('/odeme/sonuc/'.$order->public_id.'/basarili', $order->fresh()->request_snapshot['ok_url']);

        // İmzasız bildirim reddedilir, sipariş değişmez.
        $this->post('/odeme/bildirim/fake', ['merchant_oid' => $order->merchant_oid, 'status' => 'success'])->assertOk();
        $this->assertSame('pending', $order->fresh()->status);

        $this->post('/odeme/bildirim/fake', ['merchant_oid' => $order->merchant_oid, 'status' => 'success', 'sig' => 'ok', 'amount' => 10000])->assertOk()->assertSee('OK');
        $this->post('/odeme/bildirim/fake', ['merchant_oid' => $order->merchant_oid, 'status' => 'success', 'sig' => 'ok'])->assertOk();

        $order->refresh();
        $this->assertSame('paid', $order->status);
        $this->assertSame('fake-ref', $order->provider_reference);
        $this->assertSame(1, $order->events()->count());
        $this->assertSame(Load::ESCROW_PAID, $load->fresh()->escrow_status);

        // Sonuç sayfası: sahibi görür, başkası göremez.
        $this->actingAs($owner)->get(route('payment.result', ['order' => $order->public_id, 'outcome' => 'basarili']))
            ->assertOk()->assertSee('Ödemeniz alındı')->assertSee($order->merchant_oid);
        $this->actingAs($driver)->get(route('payment.result', ['order' => $order->public_id, 'outcome' => 'basarili']))->assertNotFound();

        // Eski PayTR sonuç adresi yeni sayfaya yönlendirir.
        $this->actingAs($owner)->get(route('payment.paytr.success', $load->id))
            ->assertRedirect(route('payment.result', ['order' => $order->public_id, 'outcome' => 'basarili']));
    }

    public function test_failed_webhook_marks_order_failed_and_result_page_offers_retry(): void
    {
        $owner = User::factory()->create();
        $load = $this->assignedLoad($owner, $this->driver());
        $order = app(PaymentService::class)->orderFor($load, $owner);

        $this->post('/odeme/bildirim/fake', ['merchant_oid' => $order->merchant_oid, 'status' => 'failed', 'sig' => 'ok'])->assertOk();
        $this->assertSame('failed', $order->fresh()->status);
        $this->assertSame(Load::ESCROW_PENDING, $load->fresh()->escrow_status);

        $this->actingAs($owner)->get(route('payment.result', ['order' => $order->public_id, 'outcome' => 'basarisiz']))
            ->assertOk()->assertSee('Ödeme tamamlanamadı')->assertSee('Tekrar dene');
    }

    public function test_premium_subscription_purchase_activates_membership_invoice_and_cycle(): void
    {
        Settings::set('premium_monthly_price', '900');
        $driver = $this->driver();
        $subscriptions = app(SubscriptionService::class);

        $order = $subscriptions->startCheckout($driver);
        $this->assertSame(PaymentService::PURPOSE_SUBSCRIPTION, $order->purpose);
        $this->assertSame(900.0, (float) $order->amount);
        $this->assertSame($order->id, $subscriptions->startCheckout($driver)->id, 'Açık sipariş yeniden kullanılmalı');

        $this->actingAs($driver)->get(route('driver.premium.checkout'))->assertOk()->assertSee('fake.test/pay/');

        $this->post('/odeme/bildirim/fake', ['merchant_oid' => $order->merchant_oid, 'status' => 'success', 'sig' => 'ok', 'amount' => 900])->assertOk();
        $this->post('/odeme/bildirim/fake', ['merchant_oid' => $order->merchant_oid, 'status' => 'success', 'sig' => 'ok', 'amount' => 900])->assertOk();

        $profile = $driver->driverProfile->fresh();
        $this->assertTrue($profile->isPremium());
        $this->assertEqualsWithDelta(now()->addMonth()->timestamp, $profile->premium_until->timestamp, 60);

        $this->assertSame(1, Subscription::query()->where('user_id', $driver->id)->count());
        $this->assertSame(1, SubscriptionCycle::query()->where('payment_order_id', $order->id)->count());
        $invoice = Invoice::query()->where('payment_order_id', $order->id)->first();
        $this->assertNotNull($invoice);
        $this->assertSame('subscription', $invoice->invoice_type);
        $this->assertSame(750.0, (float) $invoice->base_amount);
        $this->assertSame(150.0, (float) $invoice->tax_amount);
        $this->assertSame(900.0, (float) $invoice->total_amount);

        // İkinci ay: mevcut sürenin üzerine eklenir.
        $second = $subscriptions->startCheckout($driver);
        $this->post('/odeme/bildirim/fake', ['merchant_oid' => $second->merchant_oid, 'status' => 'success', 'sig' => 'ok'])->assertOk();
        $this->assertEqualsWithDelta(now()->addMonths(2)->timestamp, $profile->fresh()->premium_until->timestamp, 120);
        $this->assertSame(2, SubscriptionCycle::query()->count());

        $this->actingAs($driver->fresh())->get(route('driver.premium.index'))->assertOk()->assertSee('1 ay daha uzat');
        $this->actingAs($driver)->get(route('payment.result', ['order' => $order->public_id, 'outcome' => 'basarili']))->assertOk()->assertSee('Premium üyeliğiniz etkinleştirildi');
    }

    public function test_subscription_requires_approved_documents_and_expiry_job_closes_periods(): void
    {
        $user = User::factory()->driver()->create();
        DriverProfile::create(['user_id' => $user->id, 'kyc_status' => 'pending']);
        $this->expectException(\RuntimeException::class);
        app(SubscriptionService::class)->startCheckout($user);
    }

    public function test_subscription_expiry_job(): void
    {
        $driver = $this->driver();
        Subscription::create(['user_id' => $driver->id, 'plan_code' => 'premium_monthly', 'provider' => 'fake', 'status' => 'active', 'amount' => 900, 'currency' => 'TRY', 'interval' => 'monthly', 'current_period_ends_at' => now()->subDay()]);
        $this->assertSame(1, app(SubscriptionService::class)->expireDue());
        $this->assertSame('expired', Subscription::first()->status);
    }

    public function test_marketplace_gateway_releases_driver_payout_automatically_and_creates_commission_invoice(): void
    {
        $this->gateway->marketplace = true;
        $owner = User::factory()->create();
        $driver = $this->driver();
        $driver->driverProfile->update(['payout_provider_ref' => 'SUB-1', 'payout_provider' => 'fake']);
        $load = $this->assignedLoad($owner, $driver);
        $order = app(PaymentService::class)->orderFor($load, $owner);
        $this->post('/odeme/bildirim/fake', ['merchant_oid' => $order->merchant_oid, 'status' => 'success', 'sig' => 'ok'])->assertOk();

        $shipments = app(ShipmentService::class);
        $shipment = $load->fresh()->shipment;
        $shipments->startTransit($shipment, $driver->driverProfile);
        $shipments->markDelivered($shipment->fresh(), $driver->driverProfile, UploadedFile::fake()->image('pod.jpg'), 'Teslim');
        $shipments->approveDelivery($shipment->fresh(), $owner);

        $payout = Payout::query()->where('load_id', $load->id)->first();
        $this->assertSame('paid', $payout->status);
        $this->assertSame('gateway', $payout->channel);
        $this->assertSame('TRF-'.$payout->id, $payout->reference_no);
        $this->assertCount(1, $this->gateway->transfers);
        $this->assertSame(Load::ESCROW_RELEASED, $load->fresh()->escrow_status);

        $invoice = Invoice::query()->where('payout_id', $payout->id)->where('invoice_type', 'commission')->first();
        $this->assertNotNull($invoice);
        $this->assertSame(500.0, (float) $invoice->total_amount); // %5 × 10.000
    }

    public function test_without_marketplace_support_payout_stays_manual(): void
    {
        $owner = User::factory()->create();
        $driver = $this->driver();
        $load = $this->assignedLoad($owner, $driver);
        $order = app(PaymentService::class)->orderFor($load, $owner);
        $this->post('/odeme/bildirim/fake', ['merchant_oid' => $order->merchant_oid, 'status' => 'success', 'sig' => 'ok'])->assertOk();

        $shipments = app(ShipmentService::class);
        $shipment = $load->fresh()->shipment;
        $shipments->startTransit($shipment, $driver->driverProfile);
        $shipments->markDelivered($shipment->fresh(), $driver->driverProfile, UploadedFile::fake()->image('pod.jpg'), 'Teslim');
        $shipments->approveDelivery($shipment->fresh(), $owner);

        $payout = Payout::query()->where('load_id', $load->id)->first();
        $this->assertSame('pending', $payout->status);
        $this->assertSame('manual', $payout->channel);
        $this->assertCount(0, $this->gateway->transfers);
    }

    public function test_readiness_checklist_flags_missing_company_info_and_escrow_wording(): void
    {
        config(['app.url' => 'http://navluniq.test']);
        $checks = PaymentReadiness::checks();
        $byLabel = collect($checks)->keyBy('label');
        $this->assertTrue($byLabel['Şirket unvanı']['ok'], 'Unvan koddaki varsayılandan dolu gelmeli');
        $this->assertTrue($byLabel['MERSİS numarası']['ok']);
        $this->assertTrue($byLabel['Ticaret sicil numarası']['ok']);
        $this->assertFalse($byLabel['HTTPS adres']['ok']);
        $this->assertTrue($byLabel['Anahtarlar tanımlı']['ok']);
        $this->assertNotNull($byLabel['HTTPS adres']['fix']);

        CmsContent::setVal('company_name', 'Panelden Girilen A.Ş.');
        $this->assertSame('Panelden Girilen A.Ş.', Company::get('name'));
        $this->assertSame('6301483181', Company::get('tax_no'));
        $summary = PaymentReadiness::summary($checks);
        $this->assertLessThan($summary['total'], $summary['ok']);
    }

    public function test_scraped_load_purge_and_vehicle_auto_approve_blocker(): void
    {
        $scraper = Scraper::create(['name' => 'Grup', 'type' => 'notification', 'source_identifier' => 'notif:grup', 'is_active' => true]);
        $old = ScrapedLoad::create(['scraper_id' => $scraper->id, 'raw_message' => 'eski', 'pickup_location' => 'Ankara', 'delivery_location' => 'İzmir',
            'status' => 'parsed_success', 'visibility' => 'public', 'message_id' => 'a', 'retention_expires_at' => now()->subDay()]);
        $fresh = ScrapedLoad::create(['scraper_id' => $scraper->id, 'raw_message' => 'yeni tenteli 0532 123 45 67', 'pickup_location' => 'Ankara', 'delivery_location' => 'İzmir',
            'pickup_province_code' => 6, 'delivery_province_code' => 35, 'encrypted_sender_phone' => Crypt::encryptString('5321234567'),
            'status' => 'parsed_partial', 'visibility' => 'private', 'message_id' => 'b', 'retention_expires_at' => now()->addDays(20)]);

        $service = app(ScrapedLoadService::class);
        $this->assertSame(1, $service->purgeExpired());
        $this->assertNull(ScrapedLoad::find($old->id));
        $this->assertNotNull(ScrapedLoad::find($fresh->id));

        Settings::set('scraper_auto_approve_require_vehicle', '1');
        $this->assertSame('araç tipi yok', $service->autoApprovalBlocker($fresh));
        $fresh->update(['vehicle_type' => 'tir']);
        $this->assertNull($service->autoApprovalBlocker($fresh));

        $unresolved = ScrapedLoad::create(['scraper_id' => $scraper->id, 'raw_message' => 'x 0532 123 45 67', 'pickup_location' => 'Bilinmezköy', 'delivery_location' => 'İzmir',
            'encrypted_sender_phone' => Crypt::encryptString('5321234567'), 'vehicle_type' => 'tir',
            'status' => 'parsed_partial', 'visibility' => 'private', 'message_id' => 'c']);
        $this->assertSame('il çözülemedi', $service->autoApprovalBlocker($unresolved));
    }
}
