<?php

namespace Tests\Feature\Payments;

use App\Models\BankAccount;
use App\Models\CargoOwnerProfile;
use App\Models\DriverProfile;
use App\Models\DriverTrip;
use App\Models\DriverVehicle;
use App\Models\Load;
use App\Models\PaymentOrder;
use App\Models\Payout;
use App\Models\PayoutAttempt;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserNotification;
use App\Payments\GatewayManager;
use App\Services\AccountService;
use App\Services\LoadService;
use App\Services\OfferService;
use App\Services\PaymentService;
use App\Services\PayoutService;
use App\Services\ShipmentService;
use App\Services\SubscriptionService;
use App\Support\PaymentReadiness;
use App\Support\Settings;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use RuntimeException;
use Tests\TestCase;

/**
 * Para modeli kararı (2026-10-05): iyzico Pazaryeri tek canlı yol (P1/P10), komisyon ödeme emrinde dondurulur (P5),
 * hakediş mutabakatı (P3), IBAN güvenliği (P7/A5), açık emir süresi (P12), abonelik düzeltmeleri (P11), hesap silme (P8/M8/A2).
 */
class MarketplaceOnlyTest extends TestCase
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

    private function driver(bool $withIban = true, ?string $identity = '10000000146'): User
    {
        $user = User::factory()->driver()->create();
        $profile = DriverProfile::create(['user_id' => $user->id, 'kyc_status' => 'approved', 'identity_number' => $identity, 'premium_until' => now()->addMonth()]);
        DriverVehicle::create(['driver_profile_id' => $profile->id, 'plate' => '06MK'.$user->id, 'vehicle_type' => 'tir', 'is_active' => true]);
        if ($withIban) {
            $this->addIban($user);
        }

        return $user->fresh();
    }

    private function addIban(User $user, string $iban = 'TR330006100519786457841326'): BankAccount
    {
        BankAccount::query()->where('user_id', $user->id)->update(['is_default' => false]);

        return BankAccount::create(['user_id' => $user->id, 'encrypted_iban' => Crypt::encryptString($iban), 'iban_hash' => hash('sha256', $iban.$user->id.uniqid()), 'iban_last4' => substr($iban, -4), 'account_holder' => 'Test Şoför', 'is_default' => true]);
    }

    private function publish(User $ownerUser, float $price = 10000): Load
    {
        $owner = CargoOwnerProfile::query()->firstOrCreate(['user_id' => $ownerUser->id], ['type' => 'individual']);

        return app(LoadService::class)->publish($owner, [
            'pickup_location' => 'İstanbul', 'delivery_location' => 'Ankara', 'pickup_date' => now()->addDay(), 'delivery_date' => now()->addDays(2),
            'vehicle_type' => 'tir', 'goods_type' => 'Paletli Yük', 'weight' => 12000, 'price' => $price,
        ]);
    }

    private function assigned(User $ownerUser, User $driverUser, float $price = 10000): Load
    {
        $load = $this->publish($ownerUser, $price);
        $offer = app(OfferService::class)->submit($driverUser->driverProfile, $load, $price);
        app(OfferService::class)->accept($load, $offer, $ownerUser->id);

        return $load->fresh();
    }

    private function pay(Load $load, User $owner): PaymentOrder
    {
        $order = app(PaymentService::class)->orderFor($load, $owner);
        app(PaymentService::class)->checkout($order, Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '10.0.0.1']));
        $this->post('/odeme/bildirim/fake', ['merchant_oid' => $order->merchant_oid, 'status' => 'success', 'sig' => 'ok'])->assertOk();

        return $order->fresh();
    }

    private function titles(User $user): array
    {
        return UserNotification::query()->where('user_id', $user->id)->pluck('title')->all();
    }

    public function test_live_gateway_without_marketplace_blocks_escrow_orders_but_sandbox_and_marketplace_allow_them(): void
    {
        $owner = User::factory()->create();
        $load = $this->assigned($owner, $this->driver());
        $payments = app(PaymentService::class);

        // Canlı kip + pazaryeri kapalı: ödeme emri bile açılmaz (NavlunIQ para tutmaz), hazırlık listesi kırmızı
        $this->gateway->sandbox = false;
        try {
            $payments->orderFor($load, $owner);
            $this->fail('pazaryeri kapalıyken canlı navlun tahsilatı açılmamalı');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('pazaryeri', mb_strtolower($e->getMessage()));
        }
        $byLabel = collect(PaymentReadiness::checks())->keyBy('label');
        $this->assertFalse($byLabel['Navlun tahsilatı açık (pazaryeri modeli)']['ok']);
        $this->assertSame(0, PaymentOrder::query()->count());

        // Abonelik emri pazaryerinden bağımsız (NavlunIQ'nun kendi cirosu)
        $this->assertSame('created', $payments->orderForSubscription($owner, 900)->status);

        // Pazaryeri açılınca canlıda da açılır; test kipinde zaten açık
        $this->gateway->marketplace = true;
        $this->assertSame('created', $payments->orderFor($load, $owner)->status);
        $this->assertTrue(collect(PaymentReadiness::checks())->keyBy('label')['Navlun tahsilatı açık (pazaryeri modeli)']['ok']);
        $this->gateway->sandbox = true;
        $this->gateway->marketplace = false;
        $this->assertNull(PaymentReadiness::escrowBlocker($this->gateway));
    }

    public function test_readiness_turns_red_when_a_sandbox_gateway_is_active_in_production_and_paytr_is_not_selectable(): void
    {
        $env = $this->app['env'];
        $this->app['env'] = 'production';
        try {
            $byLabel = collect(PaymentReadiness::checks())->keyBy('label');
            $this->assertFalse($byLabel['Canlı ortamda test modu kapalı']['ok']);
            $this->assertStringContainsString('sahte para', $byLabel['Canlı ortamda test modu kapalı']['detail']);
        } finally {
            $this->app['env'] = $env;
        }
        $this->assertTrue(collect(PaymentReadiness::checks())->keyBy('label')['Canlı ortamda test modu kapalı']['ok']);

        // PayTR sınıfı duruyor ama seçilemez: panelde yazılı olsa bile iyzico'ya düşer, etiket listesinde yok
        Settings::set('payment_provider', 'paytr');
        $this->assertSame('iyzico', GatewayManager::selectedId());
        $this->assertArrayNotHasKey('paytr', GatewayManager::LABELS);
        $this->assertSame(['iyzico'], GatewayManager::SELECTABLE);
        $this->assertArrayHasKey('paytr', GatewayManager::REGISTRY, 'eski bildirim adresleri için adaptör kalır');
    }

    public function test_marketplace_accept_requires_iban_and_identity_and_registers_the_submerchant_at_acceptance(): void
    {
        $this->gateway->marketplace = true;
        $owner = User::factory()->create();
        CargoOwnerProfile::create(['user_id' => $owner->id, 'type' => 'individual', 'tc_no' => '10000000146']);
        $offers = app(OfferService::class);

        $noIdentity = $this->driver(withIban: true, identity: null);
        $load = $this->publish($owner);
        $offer = $offers->submit($noIdentity->driverProfile, $load, 10000);
        try {
            $offers->accept($load, $offer, $owner->id);
            $this->fail('kimliksiz şoförün teklifi kabul edilmemeli');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('kimlik', $e->getMessage());
        }
        $this->assertSame([], $this->gateway->registrations, 'sahte kimlikle kayıt denenmez');

        $noIban = $this->driver(withIban: false);
        $offer2 = $offers->submit($noIban->driverProfile, $load, 9900);
        try {
            $offers->accept($load, $offer2, $owner->id);
            $this->fail('IBAN\'sız şoförün teklifi kabul edilmemeli');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('IBAN', $e->getMessage());
        }

        // IBAN + TC tam: kabulde alt üye işyeri kaydı yapılır (ödeme ekranında değil)
        $ready = $this->driver();
        $offer3 = $offers->submit($ready->driverProfile, $load, 9800);
        $offers->accept($load, $offer3, $owner->id);
        $profile = $ready->driverProfile->fresh();
        $this->assertStringStartsWith('SUB-DRV-'.$profile->id.'-', (string) $profile->payout_provider_ref);
        $this->assertSame('fake', $profile->payout_provider);
        $this->assertSame('10000000146', $this->gateway->registrations[0]['identity']);
        $this->assertSame('individual', $this->gateway->registrations[0]['legal_type']);

        // Ödeme ekranı: alıcı kimliği yük sahibinin TC'si, şoför payı emirdeki anlık görüntü
        $order = app(PaymentService::class)->orderFor($load->fresh(), $owner);
        app(PaymentService::class)->checkout($order, Request::create('/'));
        $snapshot = $order->fresh()->request_snapshot;
        $this->assertSame('10000000146', $snapshot['identity_number']);
        $this->assertSame($profile->payout_provider_ref, $snapshot['sub_merchant_ref']);
        $this->assertSame(9310.0, (float) $snapshot['sub_merchant_price']); // 9.800 × 0,95
    }

    public function test_commission_is_frozen_on_the_order_and_shown_to_the_driver(): void
    {
        $owner = User::factory()->create();
        $driver = $this->driver();
        $load = $this->assigned($owner, $driver);
        $order = $this->pay($load, $owner);
        $this->assertSame([5.0, 500.0, 9500.0], [(float) $order->commission_rate, (float) $order->commission_amount, (float) $order->driver_net_amount]);

        // Ayar sonradan değişse de hakediş emirdeki orandan hesaplanır
        Settings::set('commission_standard_driver', '10');
        $shipments = app(ShipmentService::class);
        $shipments->startTransit($load->fresh()->shipment, $driver->driverProfile);
        $shipments->markDelivered($load->fresh()->shipment, $driver->driverProfile, UploadedFile::fake()->image('pod.jpg'));
        $shipments->approveDelivery($load->fresh()->shipment, $owner);
        $payout = Payout::query()->where('load_id', $load->id)->firstOrFail();
        $this->assertSame([500.0, 9500.0], [(float) $payout->commission_amount, (float) $payout->net_amount]);

        // Şoför iş sayfasında "size kalan" aynı rakam; teklif penceresinde oran ve hesap etiketi
        $this->actingAs($driver->fresh());
        Volt::test('driver.jobs.show', ['loadId' => $load->id])->assertSee('Size kalan')->assertSee('9.500,00 ₺')->assertSee('%5,0');
        $this->publish(User::factory()->create(), 7000);
        Volt::test('driver.loads.index')->call('openOffer', Load::query()->latest('id')->value('id'))->assertSee('Size kalan')->assertSee('%10,0 hizmet bedeli');
    }

    public function test_payout_reconcile_creates_missing_payouts_resets_stale_processing_and_retries_with_backoff(): void
    {
        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->gateway->marketplace = true;
        $this->gateway->transferSucceeds = false;
        $owner = User::factory()->create();
        $driver = $this->driver();
        $load = $this->assigned($owner, $driver);
        $this->pay($load, $owner);
        $shipments = app(ShipmentService::class);
        $shipments->startTransit($load->fresh()->shipment, $driver->driverProfile);
        $shipments->markDelivered($load->fresh()->shipment, $driver->driverProfile, UploadedFile::fake()->image('pod.jpg'));
        $shipments->approveDelivery($load->fresh()->shipment, $owner);

        $payout = Payout::query()->where('load_id', $load->id)->firstOrFail();
        $this->assertSame('pending', $payout->status, 'aktarım reddedildi, bekliyor');
        $this->assertSame(1, PayoutAttempt::query()->where('payout_id', $payout->id)->count());
        $this->assertContains('Hakediş aktarımı başarısız', $this->titles($this->admin));

        // Artan bekleme: 10 dk dolmadan yeniden denenmez
        $payouts = app(PayoutService::class);
        $this->assertSame(0, $payouts->reconcile()['retried']);
        $this->assertSame(1, PayoutAttempt::query()->count());
        Carbon::setTestNow('2026-10-05 10:11:00');
        $this->gateway->transferSucceeds = true;
        $this->assertSame(1, $payouts->reconcile()['retried']);
        $this->assertSame(['paid', 'gateway', 'TRF-'.$payout->id], [$payout->fresh()->status, $payout->fresh()->channel, $payout->fresh()->reference_no]);
        $this->assertSame(2, PayoutAttempt::query()->where('payout_id', $payout->id)->count());
        $this->assertSame(Load::ESCROW_RELEASED, $load->fresh()->escrow_status);

        // Hakedişsiz onaylı ilan: mutabakat açar
        $load2 = $this->assigned($owner, $driver);
        $load2->update(['status' => Load::STATUS_COMPLETED, 'escrow_status' => Load::ESCROW_RELEASE_APPROVED]);
        $this->assertSame(1, $payouts->reconcile()['created']);
        $this->assertNotNull(Payout::query()->where('load_id', $load2->id)->first());

        // 15 dakikadan uzun "işlemde" kalan satır: bekleyene döner, finans uyarılır
        $stuck = Payout::query()->where('load_id', $load2->id)->firstOrFail();
        Payout::query()->whereKey($stuck->id)->update(['status' => 'processing', 'updated_at' => now()->subMinutes(20)]);
        $this->assertSame(1, $payouts->reconcile()['reset']);
        $this->assertSame('pending', $stuck->fresh()->status);
        $this->assertContains('Hakediş aktarımı takıldı', $this->titles($this->admin));

        // payouts.load_id benzersiz: ikinci hakediş açılamaz
        $this->expectException(QueryException::class);
        Payout::create(['load_id' => $load2->id, 'user_id' => $driver->id, 'total_amount' => 1, 'net_amount' => 1, 'status' => 'pending']);
    }

    public function test_stale_open_orders_expire_and_a_late_success_is_refunded_with_honest_result_page(): void
    {
        $owner = User::factory()->create();
        $load = $this->assigned($owner, $this->driver());
        $order = app(PaymentService::class)->orderFor($load, $owner);
        PaymentOrder::query()->whereKey($order->id)->update(['updated_at' => now()->subHours(30)]);

        $this->assertSame(1, app(PaymentService::class)->expireStale());
        $this->assertSame('expired', $order->fresh()->status);

        // Gecikmiş "başarılı" bildirimi: para havuza girmez, iade edilir
        $this->post('/odeme/bildirim/fake', ['merchant_oid' => $order->merchant_oid, 'status' => 'success', 'sig' => 'ok'])->assertOk();
        $this->assertSame(['refunded', Load::ESCROW_PENDING], [$order->fresh()->status, $load->fresh()->escrow_status]);
        $this->assertSame(1, $this->gateway->refunds);

        $this->actingAs($owner);
        $this->get(route('payment.result', ['order' => $order->public_id, 'outcome' => 'basarisiz']))
            ->assertOk()->assertSee('Ödeme doğrulanamadı')->assertSee('çekim olduysa tutar iade edilir')->assertDontSee('çekim yapılmadı');
    }

    public function test_wallet_requires_password_and_valid_identity_notifies_on_iban_change_and_holds_auto_transfer(): void
    {
        $this->gateway->marketplace = true;
        Settings::set('bank_change_hold_hours', '24');
        $driver = $this->driver(withIban: false, identity: null);
        $this->actingAs($driver);

        // Şifresiz kaydedilmez; uydurma TC kabul edilmez
        Volt::test('driver.wallet.index')->set(['iban' => 'TR33 0006 1005 1978 6457 8413 26', 'account_holder' => 'Test Şoför', 'identity_number' => '10000000146'])
            ->call('saveBankAccount')->assertHasErrors(['bank_password']);
        Volt::test('driver.wallet.index')->set(['iban' => 'TR330006100519786457841326', 'account_holder' => 'Test Şoför', 'identity_number' => '12345678901', 'bank_password' => 'password'])
            ->call('saveBankAccount')->assertHasErrors(['identity_number']);
        $this->assertSame(0, BankAccount::query()->count());

        Volt::test('driver.wallet.index')->set(['iban' => 'TR330006100519786457841326', 'account_holder' => 'Test Şoför', 'identity_number' => '10000000146', 'bank_password' => 'password'])
            ->call('saveBankAccount')->assertHasNoErrors()->assertSee('Ödeme bilgileriniz kaydedildi');
        $profile = $driver->driverProfile->fresh();
        $this->assertSame('10000000146', $profile->identity_number);
        $this->assertNotNull($profile->bank_account_changed_at);
        $this->assertStringStartsWith('SUB-', (string) $profile->payout_provider_ref, 'IBAN + kimlik tamamlanınca kuruluş kaydı yapılır');
        $this->assertContains('IBAN bilginiz değiştirildi', $this->titles($driver));
        $this->assertSame('10*******46', $profile->maskedPayoutIdentity());

        // Şirket hesabında VKN algoritması; geçerli VKN'de vergi dairesi de zorunlu (iyzico şirket kaydı ister)
        Volt::test('driver.wallet.index')->set(['legal_type' => 'company', 'tax_number' => '1234567899', 'iban' => 'TR330006100519786457841326', 'account_holder' => 'Şoför Ltd', 'bank_password' => 'password'])
            ->call('saveBankAccount')->assertHasErrors(['tax_number']);
        Volt::test('driver.wallet.index')->set(['legal_type' => 'company', 'tax_number' => '6301481858', 'tax_office' => '', 'iban' => 'TR330006100519786457841326', 'account_holder' => 'Şoför Ltd', 'bank_password' => 'password'])
            ->call('saveBankAccount')->assertHasErrors(['tax_office']);
        $this->assertSame('10000000146', $driver->driverProfile->fresh()->identity_number, 'Hatalı kayıt denemesi profili değiştirmez');

        // IBAN yeni değiştiği için otomatik aktarım bekletilir; süre dolunca mutabakat aktarır
        $owner = User::factory()->create();
        $load = $this->assigned($owner, $driver->fresh());
        $this->pay($load, $owner);
        $shipments = app(ShipmentService::class);
        $shipments->startTransit($load->fresh()->shipment, $driver->driverProfile);
        $shipments->markDelivered($load->fresh()->shipment, $driver->driverProfile, UploadedFile::fake()->image('pod.jpg'));
        $shipments->approveDelivery($load->fresh()->shipment, $owner);
        $payout = Payout::query()->where('load_id', $load->id)->firstOrFail();
        $this->assertSame('pending', $payout->status);
        $this->assertStringContainsString('IBAN yeni değişti', (string) $payout->failure_reason);
        $this->assertSame([], $this->gateway->transfers);

        // Finans satırında "IBAN değişti" rozeti
        $this->actingAs($this->admin);
        Volt::test('admin.finance-manager')->assertSee('IBAN değişti');

        $profile->forceFill(['bank_account_changed_at' => now()->subHours(25)])->save();
        $this->assertSame(1, app(PayoutService::class)->reconcile()['retried']);
        $this->assertSame('paid', $payout->fresh()->status);
    }

    public function test_failed_payout_is_shown_as_correction_pending_and_driver_can_resend_after_fixing_iban(): void
    {
        $owner = User::factory()->create();
        $driver = $this->driver();
        $load = $this->assigned($owner, $driver);
        $this->pay($load, $owner);
        $shipments = app(ShipmentService::class);
        $shipments->startTransit($load->fresh()->shipment, $driver->driverProfile);
        $shipments->markDelivered($load->fresh()->shipment, $driver->driverProfile, UploadedFile::fake()->image('pod.jpg'));
        $shipments->approveDelivery($load->fresh()->shipment, $owner);
        $payout = Payout::query()->where('load_id', $load->id)->firstOrFail();

        app(PayoutService::class)->markFailed($payout, $this->admin, 'IBAN hatalı, banka reddetti');
        $this->assertSame('Düzeltme bekleyen', Payout::STATUS_LABELS['failed']);
        $this->assertContains('Ödemeniz yapılamadı', $this->titles($driver));

        $this->actingAs($driver->fresh());
        Volt::test('driver.wallet.index')->assertSee('Düzeltme bekleyen')->assertSee('IBAN hatalı, banka reddetti')
            ->call('retryPayout', $payout->id)->assertSee('yeniden sıraya alındı');
        $this->assertSame('pending', $payout->fresh()->status);
        $this->assertNull($payout->fresh()->failure_reason);
        $this->assertContains('Hakediş yeniden gönderim bekliyor', $this->titles($this->admin));

        // Ödenmiş hakediş "başarısız" yapılamaz
        app(PayoutService::class)->markPaid($payout->fresh(), $this->admin, 'REF-1');
        $this->expectException(RuntimeException::class);
        app(PayoutService::class)->markFailed($payout->fresh(), $this->admin, 'olmaz');
    }

    public function test_transit_is_blocked_without_a_default_iban_and_the_jobs_page_warns(): void
    {
        $owner = User::factory()->create();
        $driver = $this->driver(withIban: false);
        $load = $this->assigned($owner, $driver);
        $this->pay($load, $owner);

        try {
            app(ShipmentService::class)->startTransit($load->fresh()->shipment, $driver->driverProfile);
            $this->fail('IBAN\'sız yola çıkılmamalı');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('IBAN ekleyin', $e->getMessage());
        }
        $this->actingAs($driver->fresh());
        Volt::test('driver.jobs.index')->assertSee('Kayıtlı IBAN')->assertSee('IBAN ekle');
        Volt::test('driver.jobs.show', ['loadId' => $load->id])->assertSee('IBAN ekle')->assertDontSee('Yükü aldım, yola çıktım');

        $this->addIban($driver);
        Volt::test('driver.jobs.show', ['loadId' => $load->id])->assertSee('Yükü aldım, yola çıktım');
        app(ShipmentService::class)->startTransit($load->fresh()->shipment, $driver->driverProfile->fresh());
        $this->assertSame(Load::STATUS_ON_THE_WAY, $load->fresh()->status);
    }

    public function test_subscription_expiry_skips_notice_while_premium_continues_revoke_touches_only_gifts_and_month_does_not_overflow(): void
    {
        $driver = $this->driver();
        $subscriptions = app(SubscriptionService::class);

        // Hediye satırı bitti ama ücretli dönem sürüyor: "sona erdi" maili gitmez
        $driver->driverProfile->update(['premium_until' => now()->addDays(20)]);
        Subscription::create(['user_id' => $driver->id, 'plan_code' => 'premium_gift', 'provider' => 'manual', 'status' => 'active', 'amount' => 0, 'currency' => 'TRY', 'interval' => 'custom', 'current_period_ends_at' => now()->subDay()]);
        $paid = Subscription::create(['user_id' => $driver->id, 'plan_code' => 'premium_monthly', 'provider' => 'fake', 'status' => 'active', 'amount' => 900, 'currency' => 'TRY', 'interval' => 'monthly', 'current_period_ends_at' => now()->addDays(20)]);
        $this->assertSame(1, $subscriptions->expireDue());
        $this->assertNotContains('Premium üyeliğiniz sona erdi', $this->titles($driver));

        // Hediye geri alma ücretli dönemi bozmaz
        $gift = $subscriptions->grantPremium($driver, 30, $this->admin);
        $this->assertTrue($driver->driverProfile->fresh()->premium_until->gt(now()->addDays(45)));
        $subscriptions->revokePremium($driver, $this->admin);
        $this->assertSame('cancelled', $gift->fresh()->status);
        $this->assertSame('active', $paid->fresh()->status);
        $this->assertEqualsWithDelta(now()->addDays(20)->timestamp, $driver->driverProfile->fresh()->premium_until->timestamp, 5);
        $this->assertContains('Hediye premium süreniz kaldırıldı', $this->titles($driver));
        $subscriptions->revokePremium($driver, $this->admin, includePaid: true);
        $this->assertSame('cancelled', $paid->fresh()->status);
        $this->assertFalse($driver->driverProfile->fresh()->isPremium());

        // 31 Ocak + 1 ay = 28 Şubat (3 Mart'a taşmaz)
        Carbon::setTestNow('2027-01-31 12:00:00');
        $driver->driverProfile->update(['premium_until' => null]);
        $order = PaymentOrder::create(['load_id' => null, 'user_id' => $driver->id, 'purpose' => 'subscription', 'provider' => 'fake', 'merchant_oid' => 'NQS-OVF', 'amount' => 900, 'currency' => 'TRY', 'service_fee_amount' => 0, 'status' => 'paid', 'paid_at' => now()]);
        $subscriptions->activate($order);
        $this->assertSame('2027-02-28', $driver->driverProfile->fresh()->premium_until->toDateString());
        Carbon::setTestNow();
    }

    public function test_account_deletion_detaches_payout_bank_link_withdraws_offers_and_is_blocked_by_open_orders(): void
    {
        $owner = User::factory()->create();
        $driver = $this->driver();
        $load = $this->assigned($owner, $driver);
        $this->pay($load, $owner);
        $shipments = app(ShipmentService::class);
        $shipments->startTransit($load->fresh()->shipment, $driver->driverProfile);
        $shipments->markDelivered($load->fresh()->shipment, $driver->driverProfile, UploadedFile::fake()->image('pod.jpg'));
        $shipments->approveDelivery($load->fresh()->shipment, $owner);
        $payout = Payout::query()->where('load_id', $load->id)->firstOrFail();
        app(PayoutService::class)->markPaid($payout, $this->admin, 'REF-OK');

        // Bekleyen teklif ve gruptan alınan açık iş
        $otherOwner = User::factory()->create();
        $open = $this->publish($otherOwner);
        $pendingOffer = app(OfferService::class)->submit($driver->driverProfile, $open, 9000);
        $trip = DriverTrip::create(['driver_profile_id' => $driver->driverProfile->id, 'source' => 'external', 'pickup_location' => 'Bursa', 'delivery_location' => 'İzmir', 'status' => DriverTrip::STATUS_PLANNED]);
        $driver->driverProfile->update(['premium_until' => now()->addMonth()]);

        // İade bekleyen emir varken silinemez (M8)
        $refundPending = PaymentOrder::create(['load_id' => null, 'user_id' => $driver->id, 'purpose' => 'subscription', 'provider' => 'fake', 'merchant_oid' => 'NQS-RP', 'amount' => 900, 'currency' => 'TRY', 'service_fee_amount' => 0, 'status' => 'refund_pending']);
        try {
            app(AccountService::class)->deleteAccount($driver, 'test');
            $this->fail('iade bekleyen emir varken hesap kapatılmamalı');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('bekleyen iade', $e->getMessage());
        }
        $refundPending->update(['status' => 'refunded']);

        app(AccountService::class)->deleteAccount($driver, 'test');

        $payout->refresh();
        $this->assertNull($payout->bank_account_id, 'banka hesabı bağı çözülür, satır silinebilir');
        $this->assertSame(['****1326', 'Test Şoför'], [$payout->iban, $payout->bank_name]);
        $this->assertSame(0, BankAccount::withTrashed()->where('user_id', $driver->id)->count());
        $this->assertSame('withdrawn', $pendingOffer->fresh()->status);
        $this->assertContains('Bir teklif geri çekildi', $this->titles($otherOwner));
        $this->assertSame(DriverTrip::STATUS_CLOSED, $trip->fresh()->status);
        $profile = DriverProfile::query()->find($driver->driverProfile->id);
        $this->assertSame(['unsubmitted', null, null], [$profile->kyc_status, $profile->identity_number, $profile->payout_provider_ref]);
        $this->assertFalse($profile->isPremium());

        // Kapatılmış hesabın şoför profili teklif veremez, kabul edilemez (A2)
        $profile->update(['kyc_status' => 'approved']);
        try {
            app(OfferService::class)->submit($profile, $this->publish($otherOwner), 9000);
            $this->fail('kapatılmış hesap teklif verememeli');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('kapatılmış', $e->getMessage());
        }
        // Silinmeden önce verilmiş, bir şekilde "bekliyor" kalmış teklif: ilan başına tek teklif satırı olduğundan geri çekilen satır kullanılır
        $ghost = $pendingOffer->fresh();
        $ghost->forceFill(['status' => 'pending'])->save();
        try {
            app(OfferService::class)->accept($open->fresh(), $ghost, $otherOwner->id);
            $this->fail('kapatılmış hesabın teklifi kabul edilememeli');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('kapatılmış', $e->getMessage());
        }
    }
}
