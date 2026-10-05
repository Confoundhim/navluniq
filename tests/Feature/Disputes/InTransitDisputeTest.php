<?php

namespace Tests\Feature\Disputes;

use App\Models\BankAccount;
use App\Models\CargoOwnerProfile;
use App\Models\CmsContent;
use App\Models\DriverProfile;
use App\Models\DriverTrip;
use App\Models\DriverVehicle;
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
use App\Services\ReviewService;
use App\Services\ShipmentService;
use App\Support\Company;
use App\Support\Settings;
use Database\Seeders\CmsContractSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use RuntimeException;
use Tests\Feature\Payments\RefundableFakeGateway;
use Tests\TestCase;

/**
 * Yolda açılan uyuşmazlık (K1): karar "sevkiyat devam eder" ya da "iptal + iade"; "şoföre öde" yalnız teslim edildiyse.
 * Yük sahibi uyuşmazlığı geri çeker (M4), şoför uyuşmazlık açıkken kanıt yükler (A1), puan yalnız tamamlanmış sevkiyata (M2/A19),
 * sözleşme metinleri süreleri ayardan okur (P9).
 */
class InTransitDisputeTest extends TestCase
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
        DriverVehicle::create(['driver_profile_id' => $profile->id, 'plate' => '41DS'.$user->id, 'vehicle_type' => 'tir', 'is_active' => true]);
        $iban = 'TR330006100519786457841326';
        BankAccount::create(['user_id' => $user->id, 'encrypted_iban' => Crypt::encryptString($iban), 'iban_hash' => hash('sha256', $iban.$user->id), 'iban_last4' => '1326', 'account_holder' => 'Test Şoför', 'is_default' => true]);

        return $user->fresh();
    }

    /** Ödenmiş ve yola çıkmış sevkiyat. */
    private function inTransit(User $ownerUser, User $driverUser): Load
    {
        $owner = CargoOwnerProfile::query()->firstOrCreate(['user_id' => $ownerUser->id], ['type' => 'individual']);
        $load = app(LoadService::class)->publish($owner, [
            'pickup_location' => 'İstanbul', 'delivery_location' => 'Ankara', 'pickup_date' => now()->addDay(), 'delivery_date' => now()->addDays(2),
            'vehicle_type' => 'tir', 'goods_type' => 'Paletli Yük', 'weight' => 12000, 'price' => 10000,
        ]);
        $offer = app(OfferService::class)->submit($driverUser->driverProfile, $load, 10000);
        app(OfferService::class)->accept($load, $offer, $ownerUser->id);
        $order = app(PaymentService::class)->orderFor($load->fresh(), $ownerUser);
        app(PaymentService::class)->checkout($order, Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '10.0.0.1']));
        $this->post('/odeme/bildirim/fake', ['merchant_oid' => $order->merchant_oid, 'status' => 'success', 'sig' => 'ok'])->assertOk();
        app(ShipmentService::class)->startTransit($load->fresh()->shipment, $driverUser->driverProfile);

        return $load->fresh();
    }

    private function titles(User $user): array
    {
        return UserNotification::query()->where('user_id', $user->id)->pluck('title')->all();
    }

    public function test_in_transit_dispute_offers_continue_or_cancel_and_continue_puts_the_load_back_on_the_road(): void
    {
        $owner = User::factory()->create();
        $driver = $this->driver();
        $load = $this->inTransit($owner, $driver);
        $dispute = app(DisputeService::class)->open($load, $owner, 'Şoför telefona çıkmıyor, yük nerede bilmiyorum');
        $this->assertSame(['continue', 'owner_refunded'], array_keys(DisputeService::allowedResolutions($dispute)));

        try {
            app(DisputeService::class)->resolve($dispute, $this->admin, 'driver_paid', 'Kanıt yeterli görünüyor.');
            $this->fail('yük kamyondayken hakediş ödenmemeli');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('henüz teslim edilmedi', $e->getMessage());
        }

        // Hakem ekranı duruma göre seçenek gösterir
        $this->actingAs($this->admin);
        Volt::test('admin.disputes-center')->call('select', $dispute->id)
            ->assertSee('Sevkiyat devam eder')->assertSee('İptal + iade')->assertDontSee('Şoför haklı: hakediş ödenir')
            ->assertSet('decision', 'continue')
            ->set('decisionNotes', 'Şoför konum paylaştı, yük yolda; taraflar görüştü.')->call('resolve')->assertHasNoErrors();

        $load->refresh();
        $dispute->refresh();
        $this->assertSame(['dismissed', 'continue'], [$dispute->status, $dispute->resolution]);
        $this->assertSame([Load::STATUS_ON_THE_WAY, Load::ESCROW_PAID], [$load->status, $load->escrow_status]);
        $this->assertSame(Shipment::STATUS_IN_TRANSIT, $load->shipment->status);
        $this->assertSame(DriverTrip::STATUS_ON_THE_WAY, DriverTrip::query()->where('load_id', $load->id)->value('status'));
        $this->assertSame(0, Payout::query()->count());
        $this->assertSame(0, $this->gateway->refunds);
        foreach ([$owner, $driver] as $user) {
            $n = UserNotification::query()->where('user_id', $user->id)->where('title', 'Uyuşmazlık karara bağlandı')->first();
            $this->assertStringContainsString('sevkiyat devam eder', implode(' ', (array) $n->lines));
        }

        // Sevkiyat olağan akışla biter: kanıt → onay → hakediş
        $shipments = app(ShipmentService::class);
        $shipments->markDelivered($load->shipment, $driver->driverProfile, UploadedFile::fake()->image('pod.jpg'));
        $shipments->approveDelivery($load->fresh()->shipment, $owner);
        $this->assertSame(1, Payout::query()->where('load_id', $load->id)->count());
    }

    public function test_in_transit_cancel_and_refund_closes_the_shipment_and_refunds_the_owner(): void
    {
        $owner = User::factory()->create();
        $driver = $this->driver();
        $load = $this->inTransit($owner, $driver);
        $dispute = app(DisputeService::class)->open($load, $owner, 'Yük yanlış adrese gidiyor, iptal istiyorum');

        app(DisputeService::class)->resolve($dispute, $this->admin, 'owner_refunded', 'Şoför rotadan saptı, yük geri alındı.');

        $load->refresh();
        $this->assertSame([Load::STATUS_CANCELLED, Load::ESCROW_REFUNDED], [$load->status, $load->escrow_status]);
        $this->assertSame(Shipment::STATUS_CANCELLED, $load->shipment->status);
        $this->assertSame(DriverTrip::STATUS_CLOSED, DriverTrip::query()->where('load_id', $load->id)->value('status'));
        $this->assertSame('rejected', $load->offers()->first()->status);
        $this->assertSame([10000.0], $this->gateway->refundedAmounts);
        $this->assertSame('İade ile kapandı', $load->statusLabel());
        $this->assertSame('resolved_owner_refunded', $dispute->fresh()->status);
        $n = UserNotification::query()->where('user_id', $driver->id)->where('title', 'Uyuşmazlık karara bağlandı')->first();
        $this->assertStringContainsString('sevkiyat iptal edildi', implode(' ', (array) $n->lines));
        $this->expectException(RuntimeException::class);
        app(ReviewService::class)->submit($load, $owner, 1, 'kötü');
    }

    public function test_delivered_dispute_offers_pay_or_refund_and_driver_can_upload_proof_while_disputed_in_transit(): void
    {
        $owner = User::factory()->create();
        $driver = $this->driver();
        $load = $this->inTransit($owner, $driver);
        $dispute = app(DisputeService::class)->open($load, $owner, 'Teslimat gecikti, yük hasarlı olabilir');

        // A1: şoför ekranında uyuşmazlık açıkken kanıt formu ve konum bloğu görünür
        $this->actingAs($driver->fresh());
        Volt::test('driver.jobs.show', ['loadId' => $load->id])->assertSee('Uyuşmazlık açık; kanıt hakeme gider')->assertSee('Teslim ettim, kanıtı yükle')->assertSee('Canlı konum paylaşımı');
        Volt::test('driver.jobs.index')->assertSee('Teslim ettim');
        app(ShipmentService::class)->markDelivered($load->fresh()->shipment, $driver->driverProfile, UploadedFile::fake()->image('pod.jpg'), 'Teslim tutanağı');
        $this->assertNotNull($load->fresh()->shipment->delivered_at);
        Volt::test('driver.jobs.show', ['loadId' => $load->id])->assertDontSee('Teslim ettim, kanıtı yükle');

        // Teslim edildikten sonra karar seçenekleri değişir
        $this->assertSame(['driver_paid', 'owner_refunded'], array_keys(DisputeService::allowedResolutions($dispute->fresh())));
        $this->actingAs($this->admin);
        Volt::test('admin.disputes-center')->call('select', $dispute->id)->assertSee('Şoför haklı: hakediş ödenir')->assertDontSee('Sevkiyat devam eder')->assertSet('decision', 'driver_paid');
        try {
            app(DisputeService::class)->resolve($dispute->fresh(), $this->admin, 'continue', 'Devam etsin.');
            $this->fail('teslim edilmiş sevkiyatta "devam" kararı olmaz');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Teslim edilmiş', $e->getMessage());
        }
        app(DisputeService::class)->resolve($dispute->fresh(), $this->admin, 'driver_paid', 'Tutanak imzalı, hasar yok.');
        $this->assertSame([Load::STATUS_COMPLETED, Load::ESCROW_RELEASE_APPROVED], [$load->fresh()->status, $load->fresh()->escrow_status]);
        $this->assertSame(1, Payout::query()->where('load_id', $load->id)->count());
    }

    public function test_owner_withdraws_a_dispute_and_the_previous_state_is_restored(): void
    {
        Settings::set('delivery_auto_approval_hours', '48');
        $owner = User::factory()->create();
        $driver = $this->driver();

        // Yoldaki uyuşmazlık geri çekilir → yolda
        $load = $this->inTransit($owner, $driver);
        $dispute = app(DisputeService::class)->open($load, $owner, 'Yanlış anlaşılma oldu sanırım');
        $this->actingAs($owner->fresh());
        Volt::test('cargo-owner.disputes.index')->assertSee('Uyuşmazlığı geri çek')->call('withdrawDispute', $dispute->id)->assertSee('geri çekildi');
        $this->assertSame('cancelled', $dispute->fresh()->status);
        $this->assertSame([Load::STATUS_ON_THE_WAY, Load::ESCROW_PAID, Shipment::STATUS_IN_TRANSIT], [$load->fresh()->status, $load->fresh()->escrow_status, $load->fresh()->shipment->status]);
        $this->assertSame(DriverTrip::STATUS_ON_THE_WAY, DriverTrip::query()->where('load_id', $load->id)->value('status'));
        $this->assertContains('Uyuşmazlık geri çekildi', $this->titles($driver));
        $this->assertContains('Uyuşmazlık geri çekildi', $this->titles($this->admin));

        // Teslim edilmiş sevkiyatın uyuşmazlığı geri çekilir → teslim edildi, otomatik onay süresi yeniden başlar
        app(ShipmentService::class)->markDelivered($load->fresh()->shipment, $driver->driverProfile, UploadedFile::fake()->image('pod.jpg'));
        $load->fresh()->shipment->update(['auto_approval_due_at' => now()->addHour()]);
        $dispute2 = app(DisputeService::class)->open($load->fresh(), $owner, 'Koli sayısı eksik gibi görünüyor');
        Volt::test('cargo-owner.shipments.show', ['loadId' => $load->id])->assertSee('Uyuşmazlığı geri çek')->call('withdrawDispute')->assertSee('geri çekildi');
        $shipment = $load->fresh()->shipment;
        $this->assertSame([Load::STATUS_DELIVERED, Load::ESCROW_PAID, Shipment::STATUS_DELIVERED, 'cancelled'], [$load->fresh()->status, $load->fresh()->escrow_status, $shipment->status, $dispute2->fresh()->status]);
        $this->assertEqualsWithDelta(now()->addHours(48)->timestamp, $shipment->auto_approval_due_at->timestamp, 5, 'onay süresi taze');
        $this->assertSame(DriverTrip::STATUS_DELIVERED, DriverTrip::query()->where('load_id', $load->id)->value('status'));

        // Başkasının uyuşmazlığı geri çekilemez
        $stranger = User::factory()->create();
        $dispute3 = app(DisputeService::class)->open($load->fresh(), $owner, 'Tekrar sorun var, kontrol edilsin');
        $this->expectException(RuntimeException::class);
        app(DisputeService::class)->withdraw($dispute3, $stranger);
    }

    public function test_reviews_are_allowed_only_for_completed_shipments(): void
    {
        $owner = User::factory()->create();
        $driver = $this->driver();
        $load = $this->inTransit($owner, $driver);
        app(ShipmentService::class)->markDelivered($load->shipment, $driver->driverProfile, UploadedFile::fake()->image('pod.jpg'));
        $this->assertSame(Load::STATUS_DELIVERED, $load->fresh()->status);
        $this->assertFalse(ReviewService::canReview($load->fresh()));
        try {
            app(ReviewService::class)->submit($load->fresh(), $owner, 5, 'erken');
            $this->fail('onay bekleyen teslimat puanlanamaz');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('tamamlanmış', $e->getMessage());
        }
        $this->actingAs($owner->fresh());
        Volt::test('cargo-owner.shipments.show', ['loadId' => $load->id])->assertDontSee('Şoförü değerlendirin');

        app(ShipmentService::class)->approveDelivery($load->fresh()->shipment, $owner);
        $this->assertTrue(ReviewService::canReview($load->fresh()));
        Volt::test('cargo-owner.shipments.show', ['loadId' => $load->id])->assertSee('Şoförü değerlendirin');
        $this->assertSame(5, app(ReviewService::class)->submit($load->fresh(), $owner, 5, 'Sorunsuz')->rating);
    }

    public function test_legal_texts_read_durations_from_settings_and_state_the_refund_rules(): void
    {
        Settings::set('delivery_auto_approval_hours', '72');
        Settings::set('offer_payment_hours', '36');
        (new CmsContractSeeder)->run();

        $terms = (string) CmsContent::getVal('contract_terms');
        $cancellation = (string) CmsContent::getVal('contract_cancellation');
        $distance = (string) CmsContent::getVal('contract_distance_sale');
        $this->assertStringContainsString('{{AUTO_APPROVAL_HOURS}}', $terms);
        $this->assertStringContainsString('{{OFFER_PAYMENT_HOURS}}', $cancellation);
        $this->assertStringNotContainsString('24 saat', $terms);
        $this->assertStringNotContainsString('tek tıkla', $terms.$cancellation);
        $this->assertStringContainsString('otomatik yenilenmez', $distance);
        $this->assertStringContainsString('iade edilmez', $distance);
        $this->assertStringContainsString('dönemin sonuna kadar', $cancellation);
        $this->assertStringContainsString('yalnız uyuşmazlık', $cancellation);
        $this->assertSame(0, preg_match('/havuz|bloke|escrow/iu', $terms.$cancellation.$distance), 'hazırlık listesi sözleşmede bu sözcükleri istemez');

        $filled = Company::fillTokens($terms);
        $this->assertStringContainsString('72 saat', $filled);
        $this->assertStringContainsString('36 saat', $filled);
        $this->assertStringNotContainsString('{{', $filled);
        $this->assertStringContainsString('72 saat', Company::fillTokens(Settings::string('delivery_auto_approval_hours').' → {{AUTO_APPROVAL_HOURS}} saat'));
    }
}
