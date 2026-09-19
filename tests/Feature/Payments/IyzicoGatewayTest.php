<?php

namespace Tests\Feature\Payments;

use App\Models\BankAccount;
use App\Models\CargoOwnerProfile;
use App\Models\CmsContent;
use App\Models\DriverProfile;
use App\Models\DriverVehicle;
use App\Models\Load;
use App\Models\PaymentEvent;
use App\Models\PaymentOrder;
use App\Models\Payout;
use App\Models\User;
use App\Payments\GatewayManager;
use App\Payments\Gateways\IyzicoGateway;
use App\Services\LoadService;
use App\Services\OfferService;
use App\Services\PaymentService;
use App\Services\ShipmentService;
use App\Support\RuntimeMailConfig;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IyzicoGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
        Storage::fake('private');
        Settings::set('payment_provider', 'iyzico');
        Settings::set('iyzico_api_key', 'sandbox-api-key');
        Settings::set('iyzico_secret_key', 'sandbox-secret');
        Settings::set('iyzico_sandbox', '1');
    }

    private function driver(): User
    {
        $user = User::factory()->driver()->create();
        $profile = DriverProfile::create(['user_id' => $user->id, 'kyc_status' => 'approved']);
        DriverVehicle::create(['driver_profile_id' => $profile->id, 'plate' => '34IYZ'.$user->id, 'vehicle_type' => 'tir', 'is_active' => true]);

        return $user->fresh();
    }

    private function assignedLoad(User $ownerUser, User $driverUser): Load
    {
        $owner = CargoOwnerProfile::create(['user_id' => $ownerUser->id, 'type' => 'individual', 'kyc_status' => 'approved']);
        $load = app(LoadService::class)->publish($owner, [
            'pickup_location' => 'İstanbul', 'delivery_location' => 'Ankara', 'pickup_date' => now()->addDay(), 'delivery_date' => now()->addDays(2),
            'vehicle_type' => 'tir', 'goods_type' => 'Paletli Yük', 'weight' => 12000, 'price' => 10000,
        ]);
        $offer = app(OfferService::class)->submit($driverUser->driverProfile, $load, 10000);
        app(OfferService::class)->accept($load, $offer, $ownerUser->id);

        return $load->fresh();
    }

    public function test_provider_and_keys_come_from_panel_settings(): void
    {
        $manager = app(GatewayManager::class);
        $this->assertSame('iyzico', GatewayManager::selectedId());
        $this->assertInstanceOf(IyzicoGateway::class, $manager->active());
        $this->assertTrue($manager->active()->isConfigured());
        $this->assertTrue($manager->active()->isSandbox());
        $this->assertSame('sandbox-secret', Settings::string('iyzico_secret_key'));
        $this->assertNotSame('sandbox-secret', CmsContent::getVal('iyzico_secret_key'), 'Gizli anahtar veritabanında şifreli durmalı');
    }

    public function test_checkout_redirects_to_iyzico_and_callback_marks_order_paid(): void
    {
        Http::fake([
            'sandbox-api.iyzipay.com/payment/iyzipos/checkoutform/initialize/auth/ecom' => Http::response(['status' => 'success', 'token' => 'tok-123', 'tokenExpireTime' => 1800, 'paymentPageUrl' => 'https://sandbox-cpp.iyzipay.com?token=tok-123']),
            'sandbox-api.iyzipay.com/payment/iyzipos/checkoutform/auth/ecom/detail' => function ($request) {
                return Http::response(['status' => 'success', 'paymentStatus' => 'SUCCESS', 'paymentId' => '990001', 'basketId' => $this->basketId, 'paidPrice' => '10000.00', 'price' => '10000.00',
                    'itemTransactions' => [['paymentTransactionId' => '770001', 'itemId' => 'ORD-1']]]);
            },
        ]);

        $owner = User::factory()->create(['current_role' => 'cargo_owner']);
        $driver = $this->driver();
        $load = $this->assignedLoad($owner, $driver);
        $payments = app(PaymentService::class);
        $order = $payments->orderFor($load, $owner);
        $this->basketId = $order->merchant_oid;

        $checkout = $payments->checkout($order, request());
        $this->assertSame('redirect', $checkout->type);
        $this->assertStringContainsString('token=tok-123', $checkout->url);
        $this->assertSame('pending', $order->fresh()->status);

        Http::assertSent(function ($request) use ($order) {
            if (! str_contains($request->url(), 'initialize')) {
                return true;
            }
            $auth = (string) $request->header('Authorization')[0];
            $decoded = base64_decode(substr($auth, strlen('IYZWSv2 ')));
            $body = $request->data();

            return str_starts_with($auth, 'IYZWSv2 ')
                && str_contains($decoded, 'apiKey:sandbox-api-key&randomKey:')
                && str_contains($decoded, '&signature:')
                && $request->hasHeader('x-iyzi-rnd')
                && $body['basketId'] === $order->merchant_oid
                && $body['paidPrice'] === '10000.00'
                && $body['callbackUrl'] === route('payment.webhook', ['provider' => 'iyzico'])
                && $body['buyer']['identityNumber'] === '11111111111';
        });

        // iyzico kullanıcının tarayıcısını callbackUrl'e token ile POST eder → sonuç sayfasına yönlendirme.
        $this->post('/odeme/bildirim/iyzico', ['token' => 'tok-123'])
            ->assertRedirect(route('payment.result', ['order' => $order->public_id, 'outcome' => 'basarili']));

        $order->refresh();
        $this->assertSame('paid', $order->status);
        $this->assertSame('990001:770001', $order->provider_reference);
        $this->assertSame(Load::ESCROW_PAID, $load->fresh()->escrow_status);

        // Aynı token ikinci kez: çift işlem yok, yine yönlendirme.
        $this->post('/odeme/bildirim/iyzico', ['token' => 'tok-123'])->assertRedirect();
        $this->assertSame(1, PaymentEvent::query()->where('payment_order_id', $order->id)->count());
    }

    public function test_failed_payment_callback_redirects_to_failure_page(): void
    {
        Http::fake([
            'sandbox-api.iyzipay.com/payment/iyzipos/checkoutform/auth/ecom/detail' => Http::response(['status' => 'success', 'paymentStatus' => 'FAILURE', 'basketId' => 'NQS1T1', 'errorMessage' => 'Kart limiti yetersiz']),
        ]);
        $driver = $this->driver();
        $order = PaymentOrder::create(['load_id' => null, 'user_id' => $driver->id, 'purpose' => 'subscription', 'provider' => 'iyzico', 'merchant_oid' => 'NQS1T1', 'amount' => 900, 'currency' => 'TRY', 'service_fee_amount' => 0, 'status' => 'pending']);

        $this->post('/odeme/bildirim/iyzico', ['token' => 'tok-fail'])
            ->assertRedirect(route('payment.result', ['order' => $order->public_id, 'outcome' => 'basarisiz']));
        $this->assertSame('failed', $order->fresh()->status);

        // Token yoksa: geçersiz, ana sayfaya döner; sipariş değişmez.
        $this->post('/odeme/bildirim/iyzico', [])->assertRedirect(route('home'));
    }

    public function test_server_webhook_returns_plain_ok_without_redirect(): void
    {
        Http::fake([
            'sandbox-api.iyzipay.com/*' => Http::response(['status' => 'success', 'paymentStatus' => 'SUCCESS', 'paymentId' => '1', 'basketId' => 'NQS2T1', 'paidPrice' => '900.00', 'itemTransactions' => [['paymentTransactionId' => '2']]]),
        ]);
        $driver = $this->driver();
        PaymentOrder::create(['load_id' => null, 'user_id' => $driver->id, 'purpose' => 'subscription', 'provider' => 'iyzico', 'merchant_oid' => 'NQS2T1', 'amount' => 900, 'currency' => 'TRY', 'service_fee_amount' => 0, 'status' => 'pending']);

        $this->postJson('/odeme/bildirim/iyzico', ['iyziEventType' => 'CHECKOUT_FORM_AUTH', 'token' => 'tok-web', 'paymentConversationId' => 'NQS2T1', 'status' => 'SUCCESS'])
            ->assertOk()->assertSee('OK');
    }

    public function test_marketplace_registers_driver_and_approves_item_on_delivery(): void
    {
        Settings::set('iyzico_marketplace', '1');
        Http::fake([
            'sandbox-api.iyzipay.com/onboarding/submerchant' => Http::response(['status' => 'success', 'subMerchantKey' => 'SUB-KEY-1']),
            'sandbox-api.iyzipay.com/payment/iyzipos/checkoutform/initialize/auth/ecom' => Http::response(['status' => 'success', 'token' => 'tok-m', 'paymentPageUrl' => 'https://sandbox-cpp.iyzipay.com?token=tok-m']),
            'sandbox-api.iyzipay.com/payment/iyzipos/checkoutform/auth/ecom/detail' => function () {
                return Http::response(['status' => 'success', 'paymentStatus' => 'SUCCESS', 'paymentId' => '5', 'basketId' => $this->basketId, 'paidPrice' => '10000.00', 'itemTransactions' => [['paymentTransactionId' => '55']]]);
            },
            'sandbox-api.iyzipay.com/payment/iyzipos/item/approve' => Http::response(['status' => 'success']),
        ]);

        $owner = User::factory()->create(['current_role' => 'cargo_owner']);
        $driver = $this->driver();
        BankAccount::create(['user_id' => $driver->id, 'encrypted_iban' => Crypt::encryptString('TR330006100519786457841326'), 'iban_hash' => hash('sha256', 'x'), 'iban_last4' => '1326', 'account_holder' => $driver->full_name, 'is_default' => true]);
        $load = $this->assignedLoad($owner, $driver);
        $payments = app(PaymentService::class);
        $order = $payments->orderFor($load, $owner);
        $this->basketId = $order->merchant_oid;

        $payments->checkout($order, request());
        $this->assertSame('SUB-KEY-1', $driver->driverProfile->fresh()->payout_provider_ref);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'initialize') && ($r->data()['basketItems'][0]['subMerchantKey'] ?? null) === 'SUB-KEY-1' && $r->data()['basketItems'][0]['subMerchantPrice'] === '9500.00');

        $this->post('/odeme/bildirim/iyzico', ['token' => 'tok-m'])->assertRedirect();
        $this->assertSame('paid', $order->fresh()->status);

        $shipments = app(ShipmentService::class);
        $shipment = $load->fresh()->shipment;
        $shipments->startTransit($shipment, $driver->driverProfile);
        $shipments->markDelivered($shipment->fresh(), $driver->driverProfile, UploadedFile::fake()->image('pod.jpg'), 'Teslim');
        $shipments->approveDelivery($shipment->fresh(), $owner);

        $payout = Payout::query()->where('load_id', $load->id)->first();
        $this->assertSame('paid', $payout->status);
        $this->assertSame('gateway', $payout->channel);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'item/approve') && $r->data()['paymentTransactionId'] === '55');
    }

    public function test_refund_uses_payment_transaction_id(): void
    {
        Http::fake(['sandbox-api.iyzipay.com/payment/refund' => Http::response(['status' => 'success', 'paymentId' => '9'])]);
        $driver = $this->driver();
        $order = PaymentOrder::create(['load_id' => null, 'user_id' => $driver->id, 'purpose' => 'subscription', 'provider' => 'iyzico', 'merchant_oid' => 'NQS3T1', 'amount' => 900, 'currency' => 'TRY', 'service_fee_amount' => 0, 'status' => 'paid', 'paid_at' => now(), 'provider_reference' => '9:99']);

        $this->assertTrue(app(PaymentService::class)->refund($order, 900, 'Test iadesi'));
        $this->assertSame('refunded', $order->fresh()->status);
        Http::assertSent(fn ($r) => $r->data()['paymentTransactionId'] === '99' && $r->data()['price'] === '900.00');
    }

    public function test_smtp_settings_from_panel_override_env(): void
    {
        config(['mail.default' => 'log', 'mail.mailers.smtp.host' => '127.0.0.1']);
        Settings::set('mail_password', 'gizli-sifre');
        RuntimeMailConfig::apply();

        $this->assertSame('panel', RuntimeMailConfig::source());
        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('mail.kurumsaleposta.com', config('mail.mailers.smtp.host'));
        $this->assertSame(587, config('mail.mailers.smtp.port'));
        $this->assertSame('gizli-sifre', config('mail.mailers.smtp.password'));
        $this->assertSame('info@navluniq.com', config('mail.from.address'));
        $this->assertNotSame('gizli-sifre', CmsContent::getVal('mail_password'), 'Şifre veritabanında şifreli durmalı');
    }

    public function test_footer_shows_iyzico_and_card_logos(): void
    {
        $this->get('/')->assertOk()->assertSee('images/payment/iyzico-band-colored.svg')->assertSee('Teslimat ve İade Şartları');
    }

    private string $basketId = '';
}
