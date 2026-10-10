<?php

namespace Tests\Feature\Loads;

use App\Console\Commands\RefreshFaqCommand;
use App\Models\CargoOwnerProfile;
use App\Models\CmsContent;
use App\Models\Dispute;
use App\Models\DriverProfile;
use App\Models\DriverVehicle;
use App\Models\Faq;
use App\Models\Load;
use App\Models\Payout;
use App\Models\Shipment;
use App\Models\User;
use App\Models\UserNotification;
use App\Payments\GatewayManager;
use App\Services\DisputeService;
use App\Services\LoadService;
use App\Services\OfferService;
use App\Services\PaymentService;
use App\Services\ShipmentService;
use App\Support\Company;
use App\Support\FreightPayment;
use App\Support\PaymentReadiness;
use App\Support\Settings;
use Database\Seeders\CmsContractSeeder;
use Database\Seeders\FaqSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\Feature\Payments\RefundableFakeGateway;
use Tests\TestCase;

/**
 * Navlun doğrudan ödeme kipi (Osman, 2026-10-10: pazaryeri yokken navlun taraflar arasında ödenir): ödeme adımı yok, şoför iletişim
 * bilgilerini hemen görür ve IBAN'sız yola çıkar, teslimat onayı sevkiyatı kapatır (hakediş yok), uyuşmazlık kararı iade/hakediş
 * yapmaz, iptal yola çıkılana kadar serbest; sözleşmeler ve hazırlık listesi kipi söyler. Platform kipi eski testlerde sınanır.
 */
class DirectFreightPaymentTest extends TestCase
{
    use RefreshDatabase;

    private RefundableFakeGateway $gateway;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Settings::set('freight_payment_mode', 'direct'); // TestCase platform kipine sabitler; burada doğrudan kip
        Settings::set('scraper_free_delay_minutes', '0');
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
        Storage::fake('private');
        config(['services.payment.provider' => 'fake']);
        $this->gateway = new RefundableFakeGateway;
        $this->gateway->sandbox = false; // canlı kuruluş, pazaryeri kapalı: platform kipinde navlun tahsilatı kapalı olurdu
        app(GatewayManager::class)->swap('fake', $this->gateway);
        $this->admin = User::factory()->create(['current_role' => 'admin']);
        $this->admin->syncRoles(['super_admin']);
    }

    public function test_acceptance_skips_payment_shows_contact_details_and_delivery_closes_without_payout(): void
    {
        $ownerUser = User::factory()->create();
        $driverUser = $this->driver(); // IBAN yok, TC yok: doğrudan kipte şart değil
        $load = $this->accepted($ownerUser, $driverUser);

        $this->assertSame(Load::STATUS_ASSIGNED, $load->status);
        $this->assertSame(Load::ESCROW_DIRECT, $load->escrow_status);
        $this->assertTrue($load->isDirectPayment());
        $this->assertNull($load->payment_due_at, 'Ödeme süresi yok');
        $this->assertSame('Taraflar arasında ödenir', $load->escrowLabel());
        $this->assertTrue($load->canSeePrivateDetails($driverUser), 'Şoför açık adresi ve yetkiliyi ödeme beklemeden görür');
        $this->assertSame(['pickup' => 'Depo 3, Sanayi Mah.', 'delivery' => null], $load->privateAddressFor($driverUser));
        $this->assertStringContainsString('doğrudan ödenir', $this->lastBody($driverUser));
        $this->assertStringContainsString('NavlunIQ tahsilat yapmaz', $this->lastBody($ownerUser));
        $this->assertNull(ShipmentService::startBlocker($driverUser->driverProfile, $load), 'IBAN uyarısı yok');

        // Ödeme emri açılmaz; ödeme sayfası yerine açıklama (platform kipinde burada emir açılırdı)
        try {
            app(PaymentService::class)->orderFor($load, $ownerUser);
            $this->fail('Doğrudan kipte navlun ödeme emri açılmamalı');
        } catch (\RuntimeException) {
        }

        // Yola çık (ödeme ve IBAN beklenmez), teslim et, onayla: hakediş yok, sevkiyat tamamlandı
        $shipment = $load->shipment;
        app(ShipmentService::class)->startTransit($shipment, $driverUser->driverProfile);
        $this->assertSame(Load::STATUS_ON_THE_WAY, $load->fresh()->status);
        app(ShipmentService::class)->markDelivered($shipment->fresh(), $driverUser->driverProfile, UploadedFile::fake()->image('pod.jpg'));
        $this->assertStringContainsString('sevkiyat otomatik olarak onaylanır', $this->lastBody($ownerUser));
        app(ShipmentService::class)->approveDelivery($shipment->fresh(), $ownerUser);

        $load->refresh();
        $this->assertSame([Load::STATUS_COMPLETED, Load::ESCROW_DIRECT, Shipment::STATUS_COMPLETED], [$load->status, $load->escrow_status, $load->shipment->fresh()->status]);
        $this->assertSame(0, Payout::query()->count(), 'Hakediş açılmaz');
        $this->assertStringContainsString('sevkiyat tamamlandı', mb_strtolower((string) UserNotification::query()->where('user_id', $driverUser->id)->latest('id')->value('title')));
        $this->assertStringContainsString('NavlunIQ bu ödemeye taraf değildir', $this->lastBody($driverUser));
        $this->assertStringContainsString('şoförle aranızda ödersiniz', $this->lastBody($ownerUser));

        // Platform kipinde açılmış eski ilan kendi kipinde yürür: doğrudan sayılmaz
        Settings::set('freight_payment_mode', 'platform');
        $this->assertFalse(FreightPayment::direct());
        $this->assertTrue($load->fresh()->isDirectPayment(), 'İlan kabul anındaki kipi taşır');
    }

    public function test_owner_cancels_before_transit_and_driver_can_withdraw_without_refunds(): void
    {
        $ownerUser = User::factory()->create();
        $driverUser = $this->driver();
        $load = $this->accepted($ownerUser, $driverUser);

        // Şoför vazgeçer: ilan havuza döner, iade yok, vazgeçme sicilde
        $offer = $load->offers()->where('status', 'accepted')->firstOrFail();
        app(OfferService::class)->withdrawAccepted($offer, $driverUser->driverProfile);
        $load->refresh();
        $this->assertSame([Load::STATUS_ACTIVE, Load::ESCROW_PENDING, 'public'], [$load->status, $load->escrow_status, $load->visibility]);
        $this->assertSame(1, (int) $driverUser->driverProfile->fresh()->withdrawals_after_payment);
        $this->assertSame(0, $this->gateway->refunds);
        $this->assertStringContainsString('yola çıkmadan vazgeçti', $this->lastBody($ownerUser));
        $this->assertStringNotContainsString('iade', $this->lastBody($ownerUser));

        // Yeniden kabul, yük sahibi yola çıkılmadan iptal eder ("İptal et ve iade al" değil, düz iptal)
        $driver2 = $this->driver();
        $offer2 = app(OfferService::class)->submit($driver2->driverProfile, $load, 9000);
        app(OfferService::class)->accept($load, $offer2, $ownerUser->id);
        $this->assertSame(Load::ESCROW_DIRECT, $load->fresh()->escrow_status);
        app(LoadService::class)->cancel($load->fresh(), $ownerUser->cargoOwnerProfile, 'Yük iptal oldu');
        $this->assertSame(Load::STATUS_CANCELLED, $load->fresh()->status);
        $this->assertSame(Shipment::STATUS_CANCELLED, $load->fresh()->shipment->status);
        $this->assertSame(0, $this->gateway->refunds);

        // Ödeme süresi görevi doğrudan ilanlara dokunmaz
        $this->assertSame(['released' => 0, 'reminded' => 0], array_intersect_key(app(OfferService::class)->expireUnpaid(), ['released' => 1, 'reminded' => 1]));
    }

    public function test_dispute_in_direct_mode_only_decides_the_shipment_outcome(): void
    {
        $ownerUser = User::factory()->create();
        $driverUser = $this->driver();
        $load = $this->accepted($ownerUser, $driverUser);
        app(ShipmentService::class)->startTransit($load->shipment, $driverUser->driverProfile);

        $dispute = app(DisputeService::class)->open($load->fresh(), $ownerUser, 'Yük yanlış yere gidiyor');
        $load->refresh();
        $this->assertSame([Load::STATUS_DISPUTED, Load::ESCROW_DIRECT], [$load->status, $load->escrow_status]);
        $options = DisputeService::allowedResolutions($dispute->fresh());
        $this->assertStringContainsString('bedel taraflar arasında', $options[Dispute::RESOLUTION_OWNER_REFUNDED]);
        $this->assertStringNotContainsString('iade', mb_strtolower($options[Dispute::RESOLUTION_OWNER_REFUNDED]));

        // Geri çek: yola döner, havuz durumu "taraflar arasında" kalır
        app(DisputeService::class)->withdraw($dispute->fresh(), $ownerUser);
        $this->assertSame([Load::STATUS_ON_THE_WAY, Load::ESCROW_DIRECT], [$load->fresh()->status, $load->fresh()->escrow_status]);

        // Yeniden aç, hakem "iptal" der: iade yok, hakediş yok
        $dispute = app(DisputeService::class)->open($load->fresh(), $ownerUser, 'Yük hasarlı');
        app(DisputeService::class)->resolve($dispute, $this->admin, Dispute::RESOLUTION_OWNER_REFUNDED, 'Fotoğraflar hasarı gösteriyor');
        $load->refresh();
        $this->assertSame([Load::STATUS_CANCELLED, Load::ESCROW_DIRECT], [$load->status, $load->escrow_status]);
        $this->assertSame(0, $this->gateway->refunds);
        $this->assertSame(0, Payout::query()->count());
        $this->assertStringContainsString('iade işlemi yoktur', $this->lastBody($ownerUser));
        $this->assertSame('Hakem kararı: sevkiyat yolda iptal edildi.', $load->rejection_reason);
    }

    public function test_readiness_health_and_contracts_describe_the_mode(): void
    {
        $byLabel = collect(PaymentReadiness::checks())->keyBy('label');
        $this->assertTrue($byLabel['Navlun ödeme yolu']['ok']);
        $this->assertStringContainsString('Doğrudan', $byLabel['Navlun ödeme yolu']['detail']);
        $this->assertArrayNotHasKey('Navlun tahsilatı açık (platform kipi, pazaryeri modeli)', $byLabel->all());

        $this->actingAs($this->admin->fresh());
        Volt::test('admin.health-center')->assertSee('navlun doğrudan taraflar arasında');

        (new CmsContractSeeder)->run();
        $terms = (string) CmsContent::getVal('contract_terms');
        $this->assertStringContainsString('5.0 Navlun Ödeme Yolu', $terms);
        $this->assertStringContainsString('{{FREIGHT_PAYMENT_STATUS}}', $terms);
        $filled = Company::fillTokens($terms);
        $this->assertStringContainsString('teslimat onaylı ödeme hizmeti kapalıdır', $filled);
        $this->assertStringContainsString('komisyon almaz', $filled);
        $this->assertStringContainsString('doğrudan ödeme kipinde', (string) CmsContent::getVal('contract_cancellation'));
        $this->assertStringContainsString('yalnız teslimat onaylı ödeme hizmeti açıkken', (string) CmsContent::getVal('contract_distance_sale'));
        $this->assertSame(0, preg_match('/havuz|bloke|escrow/iu', $terms), 'hazırlık listesi sözleşmede bu sözcükleri istemez');

        Settings::set('freight_payment_mode', 'platform');
        $this->assertStringContainsString('teslimat onaylı ödeme hizmeti açıktır', Company::fillTokens($terms));
        $byLabel = collect(PaymentReadiness::checks())->keyBy('label');
        $this->assertFalse($byLabel['Navlun tahsilatı açık (platform kipi, pazaryeri modeli)']['ok'], 'Platform seçili ama pazaryeri kapalı: uyarı');
    }

    public function test_faq_follows_the_mode_and_is_refreshed_when_stale(): void
    {
        Settings::set('freight_payment_mode', 'platform');
        (new FaqSeeder)->run();
        $this->assertFalse(RefreshFaqCommand::isStale(), 'Platform kipinde üretilen SSS platform kipinde güncel');
        $this->assertStringContainsString('IBAN', Faq::query()->get()->pluck('answer')->implode(' '));

        Settings::set('freight_payment_mode', 'direct');
        $this->assertTrue(RefreshFaqCommand::isStale(), 'Kip değişince eski ödeme kuruluşu / IBAN cümleleri eski sayılır');
        $this->artisan('faq:refresh', ['--if-stale' => true])->assertSuccessful();
        $this->assertFalse(RefreshFaqCommand::isStale());
        $all = Faq::query()->get()->pluck('answer')->implode(' ');
        $this->assertStringContainsString('doğrudan', $all);
        $this->assertStringNotContainsString('kayıtlı IBAN', $all);

        Settings::set('freight_payment_mode', 'platform');
        $this->assertTrue(RefreshFaqCommand::isStale(), 'Doğrudan kip metni platform kipinde eski sayılır');
    }

    public function test_pages_speak_direct_mode_and_hide_payment_buttons(): void
    {
        $ownerUser = User::factory()->create(['current_role' => 'cargo_owner']);
        $ownerUser->syncRoles(['cargo_owner']);
        $driverUser = $this->driver();
        $driverUser->syncRoles(['driver']);
        $load = $this->accepted($ownerUser, $driverUser);

        // Yük sahibi: sevkiyat sayfasında düz "İptal et", iade sözü ve ödeme düğmesi yok, şoför telefonu hemen görünür
        $this->actingAs($ownerUser->fresh())->get(route('cargo-owner.shipments.show', $load->id))->assertOk()
            ->assertSee('İptal et')->assertDontSee('İptal et ve iade al')->assertDontSee('Ödeme yap')->assertSee('doğrudan');
        $this->actingAs($ownerUser->fresh())->get(route('cargo-owner.finance.payment', $load->id))->assertOk()
            ->assertSee('şoförle doğrudan ödenir')->assertDontSee('iyzico ile Öde');
        $this->get(route('cargo-owner.finance.index'))->assertOk()->assertSee('doğrudan');

        // Şoför: iş sayfasında "Yola çıktım" ödeme beklemeden, IBAN uyarısı yok; Ödemelerim doğrudan kipi anlatır
        $this->actingAs($driverUser->fresh())->get(route('driver.jobs.show', $load->id))->assertOk()
            ->assertSee('Yola çıktım')->assertDontSee('ödeme alındığında burada görünür')->assertDontSee('Kayıtlı IBAN\'ınız yok');
        $this->get(route('driver.wallet.index'))->assertOk()->assertSee('doğrudan')->assertDontSee('iyzico pazaryeri');
        $this->get(route('driver.dashboard'))->assertOk();

        // Tanıtım: ana sayfa ve üyelik sayfası komisyon/ödeme kuruluşu iddiası taşımaz
        auth()->logout();
        $this->get('/')->assertOk()->assertDontSee('Teslimat onaylı güvenli ödeme')->assertSee('komisyon');
        $this->get(route('subscription'))->assertOk()->assertDontSee('hizmet bedeli')->assertSee('Komisyon');
        (new CmsContractSeeder)->run();
        $this->get(route('contracts', 'kullanici-sozlesmesi'))->assertOk()->assertSee('5.0 Navlun Ödeme Yolu');

        // Platform kipinde eski metinler geri gelir (genel sayfa)
        Settings::set('freight_payment_mode', 'platform');
        $this->get('/')->assertOk()->assertSee('Teslimat onaylı güvenli ödeme');
    }

    /** Doğrudan kipte kabul edilmiş ilan (şoförde IBAN ve kimlik yok). */
    private function accepted(User $ownerUser, User $driverUser): Load
    {
        $owner = CargoOwnerProfile::query()->firstOrCreate(['user_id' => $ownerUser->id], ['type' => 'individual']);
        $load = app(LoadService::class)->publish($owner, [
            'pickup_location' => 'İstanbul', 'delivery_location' => 'Ankara', 'pickup_date' => now()->addDay(), 'delivery_date' => now()->addDays(2),
            'vehicle_type' => 'tir', 'goods_type' => 'Paletli Yük', 'weight' => 12000, 'price' => 10000,
            'pickup_address_private' => 'Depo 3, Sanayi Mah.',
        ]);
        $offer = app(OfferService::class)->submit($driverUser->driverProfile, $load, 10000);
        app(OfferService::class)->accept($load, $offer, $ownerUser->id);

        return $load->fresh();
    }

    private function driver(): User
    {
        $user = User::factory()->driver()->create();
        $profile = DriverProfile::create(['user_id' => $user->id, 'kyc_status' => 'approved']);
        DriverVehicle::create(['driver_profile_id' => $profile->id, 'plate' => '41DP'.$user->id, 'vehicle_type' => 'tir', 'is_active' => true]);

        return $user->fresh();
    }

    private function lastBody(User $user): string
    {
        $n = UserNotification::query()->where('user_id', $user->id)->latest('id')->first();

        return (string) json_encode([$n?->title, $n?->lines], JSON_UNESCAPED_UNICODE);
    }
}
