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
use App\Models\StoredCard;
use App\Models\Subscription;
use App\Models\User;
use App\Payments\GatewayManager;
use App\Payments\Gateways\IyzicoGateway;
use App\Services\LoadService;
use App\Services\OfferService;
use App\Services\PaymentService;
use App\Services\PayoutService;
use App\Services\ShipmentService;
use App\Services\SubscriptionService;
use App\Support\RuntimeMailConfig;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

class IyzicoGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Settings::set('scraper_free_delay_minutes', '0'); // bu testlerde premium bekleme süresi konu dışı
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
        // iyzico kuralı: satıcı sözleşmesi onaylanmadan alt üye işyeri kaydı yapılmaz; testlerde onaylı başlar.
        $profile = DriverProfile::create(['user_id' => $user->id, 'kyc_status' => 'approved', 'iyzico_seller_agreed_at' => now()]);
        DriverVehicle::create(['driver_profile_id' => $profile->id, 'plate' => '34IYZ'.$user->id, 'vehicle_type' => 'tir', 'is_active' => true]);

        return $user->fresh();
    }

    private function assignedLoad(User $ownerUser, User $driverUser): Load
    {
        // Alıcı kimliği (bireysel: TC) ödeme kuruluşuna gerçek değerle gider; sahte "11111111111" hiç gönderilmez.
        $owner = CargoOwnerProfile::create(['user_id' => $ownerUser->id, 'type' => 'individual', 'kyc_status' => 'approved', 'tc_no' => '10000000146']);
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
                && $body['buyer']['identityNumber'] === '10000000146';
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
        $driver->driverProfile->update(['identity_number' => '10000000146']); // alt üye işyeri için TC şart; sahte TC gönderilmez
        $load = $this->assignedLoad($owner, $driver);
        // Alt üye işyeri kaydı teklif kabulünde yapılır (ödeme ekranında değil)
        $this->assertSame('SUB-KEY-1', $driver->driverProfile->fresh()->payout_provider_ref);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'submerchant') && $r->data()['identityNumber'] === '10000000146' && $r->data()['subMerchantType'] === 'PERSONAL');
        $payments = app(PaymentService::class);
        $order = $payments->orderFor($load, $owner);
        $this->basketId = $order->merchant_oid;
        $this->assertSame(['5.000', '500.0000', '9500.0000'], [$order->commission_rate, $order->commission_amount, $order->driver_net_amount], 'komisyon ödeme emrinde dondurulur');

        $payments->checkout($order, request());
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

    public function test_company_driver_registers_as_limited_company_with_tax_office(): void
    {
        Settings::set('iyzico_marketplace', '1');
        Http::fake(['sandbox-api.iyzipay.com/onboarding/submerchant' => Http::response(['status' => 'success', 'subMerchantKey' => 'SUB-CO-1'])]);
        $owner = User::factory()->create(['current_role' => 'cargo_owner']);
        $driver = $this->driver();
        BankAccount::create(['user_id' => $driver->id, 'encrypted_iban' => Crypt::encryptString('TR330006100519786457841326'), 'iban_hash' => hash('sha256', 'co'), 'iban_last4' => '1326', 'account_holder' => 'Deneme Nakliyat Ltd. Şti.', 'is_default' => true]);
        $driver->driverProfile->update(['legal_type' => DriverProfile::LEGAL_COMPANY, 'tax_number' => '1234567890', 'tax_office' => 'Başkent']);

        $this->assignedLoad($owner, $driver);

        $this->assertSame('SUB-CO-1', $driver->driverProfile->fresh()->payout_provider_ref);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'submerchant')
            && $r->data()['subMerchantType'] === 'LIMITED_OR_JOINT_STOCK_COMPANY'
            && $r->data()['taxNumber'] === '1234567890'
            && $r->data()['taxOffice'] === 'Başkent'
            && $r->data()['legalCompanyTitle'] === 'Deneme Nakliyat Ltd. Şti.'
            && ! isset($r->data()['identityNumber']));
    }

    public function test_sole_proprietor_driver_registers_as_private_company_with_identity_and_tax_office(): void
    {
        Settings::set('iyzico_marketplace', '1');
        Http::fake(['sandbox-api.iyzipay.com/onboarding/submerchant' => Http::response(['status' => 'success', 'subMerchantKey' => 'SUB-SOLE-1'])]);
        $driver = $this->driver();
        BankAccount::create(['user_id' => $driver->id, 'encrypted_iban' => Crypt::encryptString('TR330006100519786457841326'), 'iban_hash' => hash('sha256', 'sole'), 'iban_last4' => '1326', 'account_holder' => 'Ali Veli Nakliyat', 'is_default' => true]);
        $driver->driverProfile->update(['legal_type' => DriverProfile::LEGAL_SOLE, 'identity_number' => '10000000146', 'tax_office' => 'Kadıköy']);

        $this->assertTrue(app(PayoutService::class)->ensureSubMerchant($driver->driverProfile->fresh()));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'submerchant')
            && $r->data()['subMerchantType'] === 'PRIVATE_COMPANY'
            && $r->data()['identityNumber'] === '10000000146'
            && $r->data()['taxOffice'] === 'Kadıköy'
            && $r->data()['legalCompanyTitle'] === 'Ali Veli Nakliyat'
            && ! isset($r->data()['taxNumber']));

        // Vergi dairesi yoksa şahıs şirketi de kaydedilmez
        $driver->driverProfile->fresh()->update(['tax_office' => null, 'payout_provider_ref' => null]);
        $this->assertFalse(app(PayoutService::class)->ensureSubMerchant($driver->driverProfile->fresh()));
        $this->assertStringContainsString('vergi dairesi', (string) app(PayoutService::class)->payoutReadinessBlocker($driver->driverProfile->fresh()));
    }

    public function test_seller_agreement_is_required_before_submerchant_registration_and_offer_acceptance(): void
    {
        Settings::set('iyzico_marketplace', '1');
        Http::fake(['sandbox-api.iyzipay.com/onboarding/submerchant' => Http::response(['status' => 'success', 'subMerchantKey' => 'SUB-AGR'])]);
        $driver = $this->driver();
        BankAccount::create(['user_id' => $driver->id, 'encrypted_iban' => Crypt::encryptString('TR330006100519786457841326'), 'iban_hash' => hash('sha256', 'agr'), 'iban_last4' => '1326', 'account_holder' => $driver->full_name, 'is_default' => true]);
        $driver->driverProfile->update(['identity_number' => '10000000146', 'iyzico_seller_agreed_at' => null]);

        $this->assertFalse(app(PayoutService::class)->ensureSubMerchant($driver->driverProfile->fresh()));
        Http::assertNothingSent();
        $this->assertStringContainsString('satıcı sözleşmesi', (string) app(PayoutService::class)->payoutReadinessBlocker($driver->driverProfile->fresh()));

        // Ödemelerim: kutu görünür, işaretlenmeden kaydedilmez; işaretlenince onay zamanı yazılır ve kayıt yapılır
        $this->actingAs($driver);
        $this->get(route('driver.wallet.index'))->assertOk()->assertSee('iyzico Pazaryeri Satıcı Sözleşmesi')->assertSee(IyzicoGateway::SELLER_AGREEMENT_URL);
        Volt::test('driver.wallet.index')->set(['iban' => 'TR330006100519786457841326', 'account_holder' => $driver->full_name, 'bank_password' => 'password'])
            ->call('saveBankAccount')->assertHasErrors(['iyzico_terms']);
        Volt::test('driver.wallet.index')->set(['iban' => 'TR330006100519786457841326', 'account_holder' => $driver->full_name, 'bank_password' => 'password', 'iyzico_terms' => true])
            ->call('saveBankAccount')->assertHasNoErrors();
        $profile = $driver->driverProfile->fresh();
        $this->assertNotNull($profile->iyzico_seller_agreed_at);
        $this->assertSame('SUB-AGR', $profile->payout_provider_ref);
        $this->assertNull(app(PayoutService::class)->payoutReadinessBlocker($profile));
        $this->actingAs($driver->fresh());
        $this->get(route('driver.wallet.index'))->assertOk()->assertDontSee('iyzico Pazaryeri Satıcı Sözleşmesi');
    }

    public function test_buyer_agreement_is_asked_once_before_the_first_iyzico_payment(): void
    {
        Settings::set('iyzico_marketplace', '1');
        Http::fake([
            'sandbox-api.iyzipay.com/onboarding/submerchant' => Http::response(['status' => 'success', 'subMerchantKey' => 'SUB-B']),
            'sandbox-api.iyzipay.com/payment/iyzipos/checkoutform/initialize/auth/ecom' => Http::response(['status' => 'success', 'token' => 'tok-b', 'paymentPageUrl' => 'https://sandbox-cpp.iyzipay.com?token=tok-b']),
        ]);
        $owner = User::factory()->create(['current_role' => 'cargo_owner']);
        $driver = $this->driver();
        BankAccount::create(['user_id' => $driver->id, 'encrypted_iban' => Crypt::encryptString('TR330006100519786457841326'), 'iban_hash' => hash('sha256', 'b'), 'iban_last4' => '1326', 'account_holder' => $driver->full_name, 'is_default' => true]);
        $driver->driverProfile->update(['identity_number' => '10000000146']);
        $load = $this->assignedLoad($owner, $driver);

        // İlk ödeme: iyzico'ya yönlendirilmez, alıcı sözleşmesi kutusu çıkar
        $this->actingAs($owner->fresh());
        $this->get(route('cargo-owner.finance.payment', $load->id))->assertOk()->assertSee('iyzico Pazaryeri Alıcı Sözleşmesi')->assertSee(IyzicoGateway::BUYER_AGREEMENT_URL);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'initialize'));

        Volt::test('cargo-owner.finance.payment', ['loadId' => $load->id])->call('acceptAndPay')->assertHasErrors(['buyer_terms']);
        Volt::test('cargo-owner.finance.payment', ['loadId' => $load->id])->set('buyer_terms', true)->call('acceptAndPay')->assertHasNoErrors()
            ->assertRedirect('https://sandbox-cpp.iyzipay.com?token=tok-b');
        $this->assertNotNull($owner->fresh()->iyzico_buyer_agreed_at);

        // Sonraki açılış: doğrudan iyzico'ya
        $this->actingAs($owner->fresh());
        $this->get(route('cargo-owner.finance.payment', $load->id))->assertRedirect('https://sandbox-cpp.iyzipay.com?token=tok-b');
    }

    public function test_company_driver_without_tax_office_is_not_registered(): void
    {
        Settings::set('iyzico_marketplace', '1');
        Http::fake();
        $driver = $this->driver();
        BankAccount::create(['user_id' => $driver->id, 'encrypted_iban' => Crypt::encryptString('TR330006100519786457841326'), 'iban_hash' => hash('sha256', 'co2'), 'iban_last4' => '1326', 'account_holder' => 'X', 'is_default' => true]);
        $driver->driverProfile->update(['legal_type' => DriverProfile::LEGAL_COMPANY, 'tax_number' => '1234567890', 'tax_office' => null]);

        $this->assertFalse(app(PayoutService::class)->ensureSubMerchant($driver->driverProfile->fresh()));
        Http::assertNothingSent();
    }

    public function test_existing_submerchant_is_updated_instead_of_duplicated(): void
    {
        Settings::set('iyzico_marketplace', '1');
        Http::fake([
            'sandbox-api.iyzipay.com/onboarding/submerchant/retrieve' => Http::response(['status' => 'success', 'subMerchantKey' => 'SUB-OLD']),
            'sandbox-api.iyzipay.com/onboarding/submerchant' => function ($request) {
                return $request->method() === 'PUT'
                    ? Http::response(['status' => 'success'])
                    : Http::response(['status' => 'failure', 'errorCode' => '10000', 'errorMessage' => 'Bu dış kimlikle kayıtlı alt üye işyeri zaten var']);
            },
        ]);
        $driver = $this->driver();
        BankAccount::create(['user_id' => $driver->id, 'encrypted_iban' => Crypt::encryptString('TR330006100519786457841326'), 'iban_hash' => hash('sha256', 'up'), 'iban_last4' => '1326', 'account_holder' => $driver->full_name, 'is_default' => true]);
        $driver->driverProfile->update(['identity_number' => '10000000146']);

        $this->assertTrue(app(PayoutService::class)->ensureSubMerchant($driver->driverProfile->fresh()));
        $this->assertSame('SUB-OLD', $driver->driverProfile->fresh()->payout_provider_ref);
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/onboarding/submerchant') && $r->data()['subMerchantKey'] === 'SUB-OLD' && $r->data()['identityNumber'] === '10000000146');
    }

    public function test_connection_diagnosis_reports_keys_marketplace_and_webhook(): void
    {
        Settings::set('iyzico_marketplace', '1');
        Http::fake([
            'sandbox-api.iyzipay.com/payment/bin/check' => Http::response(['status' => 'success', 'binNumber' => '554960']),
            'sandbox-api.iyzipay.com/onboarding/submerchant/retrieve' => Http::response(['status' => 'failure', 'errorCode' => '10601', 'errorMessage' => 'Alt üye işyeri bulunamadı']),
        ]);
        $admin = User::factory()->create(['current_role' => 'admin']);
        $admin->syncRoles(['super_admin']);
        $this->actingAs($admin);

        $c = Volt::test('admin.settings-center')->set('activeTab', 'payment')->assertSee('Bağlantıyı sına')->call('diagnosePayment');
        $rows = collect($c->get('paymentDiagnosis'));
        $this->assertTrue($rows->firstWhere('label', 'Anahtarlar ve imza (test (sandbox))')['ok']);
        $this->assertTrue($rows->firstWhere('label', 'Pazaryeri (alt üye işyeri) yetkisi')['ok']);
        $this->assertStringContainsString('/odeme/bildirim/iyzico', $rows->firstWhere('label', 'Bildirim adresi')['detail']); // test ortamında http olduğundan ok=false olabilir
        $c->assertSee('iyzico anahtarları kabul etti');

    }

    public function test_connection_diagnosis_shows_iyzico_rejections_verbatim(): void
    {
        Settings::set('iyzico_marketplace', '1');
        Http::fake([
            'sandbox-api.iyzipay.com/payment/bin/check' => Http::response(['status' => 'failure', 'errorCode' => '1001', 'errorMessage' => 'api bilgileri bulunamadı']),
            'sandbox-api.iyzipay.com/onboarding/submerchant/retrieve' => Http::response(['status' => 'failure', 'errorCode' => '5001', 'errorMessage' => 'Pazaryeri yetkiniz bulunmamaktadır']),
        ]);
        $admin = User::factory()->create(['current_role' => 'admin']);
        $admin->syncRoles(['super_admin']);
        $this->actingAs($admin);

        $rows = collect(Volt::test('admin.settings-center')->set('activeTab', 'payment')->call('diagnosePayment')->assertSee('api bilgileri bulunamadı')->get('paymentDiagnosis'));
        $this->assertFalse($rows->firstWhere('label', 'Anahtarlar ve imza (test (sandbox))')['ok']);
        $this->assertFalse($rows->firstWhere('label', 'Pazaryeri (alt üye işyeri) yetkisi')['ok']);
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

    /** Kart saklama: ödeme formu kart anahtarını taşır, sorgu yanıtındaki kart kaydedilir, yenileme /payment/auth ile kayıtlı karttan çekilir. */
    public function test_card_storage_saves_card_on_subscription_payment_and_renews_with_payment_auth(): void
    {
        Settings::set('iyzico_card_storage', '1');
        Settings::set('premium_monthly_price', '900');
        Http::fake([
            'sandbox-api.iyzipay.com/payment/iyzipos/checkoutform/initialize/auth/ecom' => Http::response(['status' => 'success', 'token' => 'tok-sub', 'tokenExpireTime' => 1800, 'paymentPageUrl' => 'https://sandbox-cpp.iyzipay.com?token=tok-sub']),
            'sandbox-api.iyzipay.com/payment/iyzipos/checkoutform/auth/ecom/detail' => fn () => Http::response(['status' => 'success', 'paymentStatus' => 'SUCCESS', 'paymentId' => '990009', 'basketId' => $this->basketId, 'paidPrice' => '900.00', 'price' => '900.00',
                'cardUserKey' => 'CUK-1', 'cardToken' => 'CTK-1', 'lastFourDigits' => '0008', 'cardAssociation' => 'MASTER_CARD', 'cardFamily' => 'Bonus', 'itemTransactions' => [['paymentTransactionId' => '770009', 'itemId' => 'ORD-1']]]),
            'sandbox-api.iyzipay.com/payment/auth' => Http::response(['status' => 'success', 'paymentId' => '990010', 'paidPrice' => '900.00', 'itemTransactions' => [['paymentTransactionId' => '770010']]]),
        ]);
        $driver = $this->driver();
        $driver->driverProfile->update(['identity_number' => '10000000146']);
        $payments = app(PaymentService::class);
        $subscriptions = app(SubscriptionService::class);

        $order = $subscriptions->startCheckout($driver->fresh(), 1, true);
        $this->assertTrue((bool) $order->auto_renew);
        $this->basketId = $order->merchant_oid;
        $payments->checkout($order, request());
        Http::assertSent(fn ($r) => str_contains($r->url(), 'initialize') && $r->data()['paymentGroup'] === 'SUBSCRIPTION' && ! array_key_exists('cardUserKey', $r->data()));

        $this->post('/odeme/bildirim/iyzico', ['token' => 'tok-sub'])->assertRedirect();
        $card = StoredCard::query()->where('user_id', $driver->id)->firstOrFail();
        $this->assertSame(['CUK-1', 'CTK-1', 'Mastercard •••• 0008'], [$card->card_user_key, $card->card_token, $card->label()]);
        $subscription = Subscription::query()->where('user_id', $driver->id)->firstOrFail();
        $this->assertTrue($subscription->auto_renew);
        $this->assertSame($card->id, $subscription->stored_card_id);

        // İkinci ödeme formu kayıtlı kart anahtarını taşır (iyzico sayfasında kart listelenir)
        $second = $subscriptions->startCheckout($driver->fresh(), 3, true);
        $payments->checkout($second, request());
        Http::assertSent(fn ($r) => str_contains($r->url(), 'initialize') && ($r->data()['cardUserKey'] ?? null) === 'CUK-1');

        // Yenileme: dönem 60 saat sonra bitiyor → /payment/auth kayıtlı kartla, 3D Secure'süz
        $subscription->update(['current_period_ends_at' => now()->addHours(60)]);
        $driver->driverProfile->update(['premium_until' => now()->addHours(60)]);
        $this->assertSame(1, $subscriptions->renewDue());
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/payment/auth') && $r->data()['paymentCard'] === ['cardUserKey' => 'CUK-1', 'cardToken' => 'CTK-1']
            && $r->data()['paidPrice'] === '900.00' && $r->data()['paymentGroup'] === 'SUBSCRIPTION' && $r->data()['buyer']['identityNumber'] === '10000000146');
        $renewal = PaymentOrder::query()->where('user_id', $driver->id)->where('stored_card_id', $card->id)->firstOrFail();
        $this->assertSame('paid', $renewal->status);
        $this->assertSame('990010:770010', $renewal->provider_reference);
        $this->assertEqualsWithDelta(now()->addHours(60)->addMonthsNoOverflow(1)->timestamp, $driver->driverProfile->fresh()->premium_until->timestamp, 5);

        // Kart silme DELETE /cardstorage/card
        Http::fake(['sandbox-api.iyzipay.com/cardstorage/card' => Http::response(['status' => 'success'])]);
        $subscriptions->deleteStoredCard($driver, $card);
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/cardstorage/card') && $r->data()['cardToken'] === 'CTK-1');
        $this->assertFalse($subscription->fresh()->auto_renew);
    }

    public function test_expired_card_error_code_stops_renewal_without_retry(): void
    {
        Settings::set('iyzico_card_storage', '1');
        Http::fake(['sandbox-api.iyzipay.com/payment/auth' => Http::response(['status' => 'failure', 'errorCode' => '10054', 'errorMessage' => 'Kartın son kullanma tarihi hatalı'])]);
        $driver = $this->driver();
        $driver->driverProfile->update(['identity_number' => '10000000146', 'premium_until' => now()->addHours(48)]);
        $card = StoredCard::create(['user_id' => $driver->id, 'provider' => 'iyzico', 'card_user_key' => 'CUK-2', 'card_token' => 'CTK-2', 'last_four' => '0001']);
        $subscription = Subscription::create(['user_id' => $driver->id, 'plan_code' => 'premium_monthly', 'provider' => 'iyzico', 'status' => 'active', 'amount' => 900, 'currency' => 'TRY', 'interval' => 'monthly',
            'auto_renew' => true, 'renew_months' => 1, 'stored_card_id' => $card->id, 'current_period_starts_at' => now()->subMonth(), 'current_period_ends_at' => now()->addHours(48)]);

        $this->assertSame(0, app(SubscriptionService::class)->renewDue());
        $subscription->refresh();
        $this->assertFalse($subscription->auto_renew);
        $this->assertStringContainsString('10054', (string) $subscription->last_renewal_error);
        $this->assertSame('failed', PaymentOrder::query()->where('user_id', $driver->id)->latest('id')->value('status'));
        $this->assertSame(1, PaymentEvent::query()->where('event_type', 'renewal')->where('status', 'failed')->count());
    }

    public function test_request_snapshot_hides_buyer_and_address_blocks(): void
    {
        Http::fake(['sandbox-api.iyzipay.com/payment/iyzipos/checkoutform/initialize/auth/ecom' => Http::response(['status' => 'success', 'token' => 'tok-snap', 'tokenExpireTime' => 1800, 'paymentPageUrl' => 'https://sandbox-cpp.iyzipay.com?token=tok-snap'])]);
        $driver = $this->driver();
        $driver->driverProfile->update(['identity_number' => '10000000146']);
        $order = app(SubscriptionService::class)->startCheckout($driver->fresh(), 1);
        app(PaymentService::class)->checkout($order, request());

        Http::assertSent(fn ($r) => str_contains($r->url(), 'initialize') && ($r->data()['buyer']['identityNumber'] ?? null) === '10000000146');
        $snapshot = $order->fresh()->request_snapshot;
        $this->assertSame(['[gizlendi]', '[gizlendi]', '[gizlendi]'], [$snapshot['buyer'], $snapshot['shippingAddress'], $snapshot['billingAddress']]);
        $this->assertSame($order->merchant_oid, $snapshot['conversationId']);
        $this->assertStringNotContainsString('10000000146', json_encode($snapshot));
        $this->assertStringNotContainsString($driver->email, json_encode($snapshot));
    }

    public function test_server_webhook_without_checkout_token_is_acknowledged_quietly(): void
    {
        Http::fake();
        Log::shouldReceive('warning')->never();
        Log::shouldReceive('error')->never();

        // iyzico API_AUTH (kayıtlı kart çekimi) olayı: token yok, sonuç zaten çekim anında işlendi
        $this->post('/odeme/bildirim/iyzico', ['iyziEventType' => 'API_AUTH', 'paymentConversationId' => 'NQS1T2601', 'paymentId' => '12345', 'status' => 'SUCCESS'])->assertOk()->assertSee('OK');
        Http::assertNothingSent();
    }

    public function test_transport_failure_during_stored_card_charge_is_flagged_and_payment_detail_settles_it_later(): void
    {
        Settings::set('iyzico_card_storage', '1');
        Settings::set('premium_monthly_price', '900');
        Http::fake(['sandbox-api.iyzipay.com/payment/auth' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);
        $driver = $this->driver();
        $driver->driverProfile->update(['identity_number' => '10000000146', 'premium_until' => now()->addHours(48)]);
        $card = StoredCard::create(['user_id' => $driver->id, 'provider' => 'iyzico', 'card_user_key' => 'CUK-T', 'card_token' => 'CTK-T', 'last_four' => '0003']);
        $subscription = Subscription::create(['user_id' => $driver->id, 'plan_code' => 'premium_monthly', 'provider' => 'iyzico', 'status' => 'active', 'amount' => 900, 'currency' => 'TRY', 'interval' => 'monthly',
            'auto_renew' => true, 'renew_months' => 1, 'stored_card_id' => $card->id, 'current_period_starts_at' => now()->subMonth(), 'current_period_ends_at' => now()->addHours(48)]);

        $this->assertSame(0, app(SubscriptionService::class)->renewDue());
        $subscription->refresh();
        $this->assertSame(0, $subscription->renewal_failures, 'Ağ hatası deneme sayılmaz');
        $this->assertTrue($subscription->auto_renew);
        $order = PaymentOrder::query()->where('user_id', $driver->id)->latest('id')->firstOrFail();
        $this->assertSame('pending', $order->status);
        $this->assertSame(1, PaymentEvent::query()->where('payment_order_id', $order->id)->where('status', 'unknown')->count());

        // Sonraki deneme: önce /payment/detail ile akıbet sorulur; iyzico "başarılı" diyorsa /payment/auth bir daha çağrılmaz
        $this->travel(3)->hours();
        Http::fake([
            'sandbox-api.iyzipay.com/payment/detail' => Http::response(['status' => 'success', 'paymentStatus' => 'SUCCESS', 'paymentId' => '990077', 'paidPrice' => '900.00', 'itemTransactions' => [['paymentTransactionId' => '770077']]]),
            'sandbox-api.iyzipay.com/payment/auth' => Http::response(['status' => 'success', 'paymentId' => 'OLMAMALI']),
        ]);
        $this->assertSame(1, app(SubscriptionService::class)->renewDue());
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/payment/detail') && $r->data()['paymentConversationId'] === $order->merchant_oid);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/payment/auth'));
        $this->assertSame(['paid', '990077:770077'], [$order->fresh()->status, $order->fresh()->provider_reference]);
        $this->assertEqualsWithDelta(now()->subHours(3)->addHours(48)->addMonthsNoOverflow(1)->timestamp, $driver->driverProfile->fresh()->premium_until->timestamp, 5);
    }

    public function test_diagnose_reports_card_storage_when_enabled(): void
    {
        Settings::set('iyzico_card_storage', '1');
        Http::fake([
            'sandbox-api.iyzipay.com/payment/bin/check' => Http::response(['status' => 'success']),
            'sandbox-api.iyzipay.com/cardstorage/cards' => Http::response(['status' => 'failure', 'errorCode' => '3001', 'errorMessage' => 'Kart kullanıcısı bulunamadı']),
        ]);
        $labels = array_column(app(IyzicoGateway::class)->diagnose(), 'ok', 'label');
        $this->assertTrue($labels['Kart saklama (otomatik yenileme)']);
    }
}
