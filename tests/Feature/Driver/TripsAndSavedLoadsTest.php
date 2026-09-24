<?php

namespace Tests\Feature\Driver;

use App\Models\CargoOwnerProfile;
use App\Models\DriverProfile;
use App\Models\DriverSavedLoad;
use App\Models\DriverTrip;
use App\Models\DriverVehicle;
use App\Models\Load;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Models\Shipment;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\DisputeService;
use App\Services\DriverTripService;
use App\Services\LoadService;
use App\Services\OfferService;
use App\Services\ShipmentService;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Paket D: kaydet (yıldız), "Bu işi aldım" seferi, dönüş yükü taraması ve bildirimleri. */
class TripsAndSavedLoadsTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
        Scraper::create(['name' => 'Grup', 'type' => 'notification', 'source_identifier' => 'notif:grup', 'is_active' => true]);
    }

    private function driver(bool $premium = true, string $body = 'tenteli'): User
    {
        $user = User::factory()->driver()->create();
        $profile = DriverProfile::create(['user_id' => $user->id, 'kyc_status' => 'approved', 'premium_until' => $premium ? now()->addMonth() : null]);
        DriverVehicle::create(['driver_profile_id' => $profile->id, 'plate' => '35TR'.(++self::$seq), 'vehicle_type' => 'tir', 'body_type' => $body, 'trailer_length' => 'uzun', 'is_active' => true]);

        return $user->fresh();
    }

    private function owner(): CargoOwnerProfile
    {
        $user = User::factory()->create(['current_role' => 'cargo_owner']);

        return CargoOwnerProfile::create(['user_id' => $user->id, 'type' => 'individual', 'kyc_status' => 'approved']);
    }

    private function load(CargoOwnerProfile $owner, array $o = []): Load
    {
        return Load::create(array_merge([
            'cargo_owner_profile_id' => $owner->id, 'source_type' => 'internal', 'visibility' => 'public',
            'pickup_location' => 'İzmir Aliağa', 'pickup_province_code' => 35, 'delivery_location' => 'Ankara', 'delivery_province_code' => 6,
            'pickup_date' => now()->addDays(3), 'vehicle_type' => 'tir', 'body_types' => ['tenteli'], 'goods_type' => 'Paletli yük', 'weight' => 24000, 'price' => 45000,
            'status' => Load::STATUS_ACTIVE, 'escrow_status' => Load::ESCROW_PENDING, 'published_at' => now(),
        ], $o));
    }

    private function scraped(array $o = []): ScrapedLoad
    {
        return ScrapedLoad::create(array_merge([
            'scraper_id' => 1, 'content_hash' => 'h'.(++self::$seq), 'raw_message' => 'm', 'sender_phone' => '05551112233',
            'pickup_location' => 'Ankara Kazan', 'pickup_province_code' => 6, 'delivery_location' => 'İzmir', 'delivery_province_code' => 35,
            'vehicle_type' => 'tir', 'vehicle_type_source' => 'keyword', 'body_types' => ['tenteli', 'uzun_dorse'],
            'status' => 'parsed_success', 'visibility' => 'public', 'retention_expires_at' => now()->addDays(30),
        ], $o));
    }

    public function test_driver_can_star_and_unstar_system_and_external_loads(): void
    {
        $driver = $this->driver();
        $load = $this->load($this->owner());
        $ext = $this->scraped();
        $this->actingAs($driver);

        Volt::test('driver.loads.index')->call('toggleSave', 'system', $load->id)->call('setTab', 'external')->call('toggleSave', 'external', $ext->id);
        $this->assertSame(2, DriverSavedLoad::query()->count());

        Volt::test('driver.loads.index')->call('setTab', 'saved')
            ->assertSee('İzmir Aliağa')->assertSee('Ankara Kazan')->assertSee('Bu işi aldım')->assertSee('Teklif ver')
            ->call('toggleSave', 'system', $load->id)->assertDontSee('İzmir Aliağa');
        $this->assertSame(1, DriverSavedLoad::query()->count());

        // Premium olmayan şoför dış kaynak ilanını kaydedemez
        $free = $this->driver(premium: false);
        $this->actingAs($free);
        Volt::test('driver.loads.index')->call('toggleSave', 'external', $ext->id);
        $this->assertSame(0, DriverSavedLoad::query()->where('driver_profile_id', $free->driverProfile->id)->count());
    }

    public function test_taking_an_external_load_opens_a_trip_once(): void
    {
        $driver = $this->driver();
        $ext = $this->scraped();
        $this->actingAs($driver);

        Volt::test('driver.loads.index')->call('setTab', 'external')->assertSee('Bu işi aldım')
            ->call('openTake', $ext->id)->assertSet('takeModalOpen', true)
            ->set('takePickupDate', now()->toDateString())->set('takeDeliveryDate', now()->addDays(2)->toDateString())
            ->call('submitTake')->assertHasNoErrors()->assertSet('takeModalOpen', false)
            ->assertSee('İşlerimde');

        $trip = DriverTrip::query()->first();
        $this->assertNotNull($trip);
        $this->assertSame('external', $trip->source);
        $this->assertSame(35, (int) $trip->delivery_province_code);
        $this->assertSame(now()->addDays(2)->toDateString(), $trip->delivery_date->toDateString());
        $this->assertTrue($trip->notify_return);

        // Aynı ilan için ikinci kez "aldım" yeni sefer açmaz
        $again = app(DriverTripService::class)->takeExternal($driver->driverProfile, $ext, now(), now()->addDay());
        $this->assertSame($trip->id, $again->id);

        // Teslim yüklemeden önce olamaz
        Volt::test('driver.loads.index')->call('openTake', $ext->id)->set('takePickupDate', now()->addDays(3)->toDateString())->set('takeDeliveryDate', now()->toDateString())
            ->call('submitTake')->assertHasErrors(['takeDeliveryDate']);

        // Premium olmayan şoför sefer açamaz
        $this->expectException(\RuntimeException::class);
        app(DriverTripService::class)->takeExternal($this->driver(premium: false)->driverProfile, $ext, now(), now()->addDay());
    }

    public function test_return_load_scan_notifies_new_matching_loads_near_destination_once(): void
    {
        $driver = $this->driver();
        $owner = $this->owner();
        $trip = app(DriverTripService::class)->takeExternal($driver->driverProfile, $this->scraped(), now(), now()->addDays(2));
        $trip->forceFill(['created_at' => now()->subMinute()])->save();

        $izmir = $this->load($owner, ['pickup_location' => 'İzmir Torbalı']);                                                        // aynı il
        $manisa = $this->load($owner, ['pickup_location' => 'Manisa', 'pickup_province_code' => 45, 'pickup_lat' => 38.61, 'pickup_lng' => 27.43]); // 150 km içinde
        $this->load($owner, ['pickup_location' => 'Ankara Ostim', 'pickup_province_code' => 6]);                                        // uzak
        $this->load($owner, ['pickup_location' => 'İzmir Damper', 'body_types' => ['damperli']]);                                       // kasa uymaz
        $this->load($owner, ['pickup_location' => 'İzmir Erken', 'pickup_date' => now()]);                                              // teslimden önce yükleniyor
        $this->scraped(['pickup_location' => 'İzmir Kemalpaşa', 'pickup_province_code' => 35, 'delivery_location' => 'Bursa', 'delivery_province_code' => 16]);

        $r = app(DriverTripService::class)->scanReturnLoads();
        $this->assertSame(['trips' => 1, 'notified' => 1], $r);

        $n = UserNotification::query()->where('user_id', $driver->id)->where('type', 'return_load')->first();
        $this->assertNotNull($n);
        $this->assertStringContainsString('3 yeni ilan', $n->title);
        $text = implode("\n", $n->lines);
        $this->assertStringContainsString('İzmir Torbalı', $text);
        $this->assertStringContainsString('Manisa', $text);
        $this->assertStringContainsString('İzmir Kemalpaşa', $text);
        $this->assertStringNotContainsString('Ankara Ostim', $text);
        $this->assertStringNotContainsString('İzmir Damper', $text);
        $this->assertStringNotContainsString('İzmir Erken', $text);
        $this->assertNotSame(UserNotification::MAIL_SKIPPED, $n->mail_status, 'İlk bildirim e-posta ile de gider');
        $this->assertSame(3, $trip->fresh()->match_count);
        $this->assertSame(3, $trip->matches()->count());

        // İkinci tarama: yeni ilan yoksa bildirim yok
        $this->assertSame(0, app(DriverTripService::class)->scanReturnLoads()['notified']);
        $this->assertSame(1, UserNotification::query()->where('type', 'return_load')->count());

        // Yeni ilan gelince okunmamış bildirimin üstüne yazılır (yığılmaz): sayı artar, yeni ilan en üstte
        $this->load($owner, ['pickup_location' => 'İzmir Bornova']);
        $this->assertSame(1, app(DriverTripService::class)->scanReturnLoads()['notified']);
        $this->assertSame(1, UserNotification::query()->where('type', 'return_load')->count(), 'Aynı sefer için ikinci bildirim açılmaz');
        $second = UserNotification::query()->where('type', 'return_load')->latest('id')->first();
        $this->assertStringContainsString('4 yeni ilan', $second->title);
        $this->assertStringContainsString('İzmir Bornova', $second->lines[0]);
        $this->assertStringContainsString('Torbalı', implode("\n", $second->lines));
        $this->assertSame(4, $trip->fresh()->match_count);

        // Bildirim okunduysa bir sonraki yeni ilan yeni bildirim açar
        $second->markRead();
        $this->load($owner, ['pickup_location' => 'İzmir Gaziemir']);
        app(DriverTripService::class)->scanReturnLoads();
        $this->assertSame(2, UserNotification::query()->where('type', 'return_load')->count());
        $this->assertStringContainsString('1 yeni ilan', UserNotification::query()->where('type', 'return_load')->latest('id')->first()->title);

        // Bildirim kapalıysa taranmaz; kapalı sefer taranmaz
        $trip->forceFill(['notify_return' => false])->save();
        $this->load($owner, ['pickup_location' => 'İzmir Çiğli']);
        $this->assertSame(['trips' => 0, 'notified' => 0], app(DriverTripService::class)->scanReturnLoads());

        // Sayfada canlı liste (bildirimden bağımsız) ve premium olmayanda dış kaynak yok
        $found = app(DriverTripService::class)->returnLoadsFor($trip->fresh());
        $this->assertCount(5, $found['system']);
        $this->assertCount(1, $found['external']);
        $driver->driverProfile->forceFill(['premium_until' => null])->save();
        $this->assertCount(0, app(DriverTripService::class)->returnLoadsFor($trip->fresh())['external']);
    }

    public function test_accepted_offer_opens_a_system_trip_and_dashboard_shows_it(): void
    {
        $driver = $this->driver();
        $owner = $this->owner();
        $load = $this->load($owner, ['pickup_location' => 'Bursa', 'pickup_province_code' => 16, 'delivery_location' => 'İzmir', 'delivery_province_code' => 35, 'delivery_date' => now()->addDays(4)]);
        $offers = app(OfferService::class);
        $offer = $offers->submit($driver->driverProfile, $load, 40000);
        $offers->accept($load->fresh(), $offer, $owner->user_id);

        $trip = DriverTrip::query()->where('driver_profile_id', $driver->driverProfile->id)->first();
        $this->assertNotNull($trip);
        $this->assertSame('system', $trip->source);
        $this->assertSame($load->id, (int) $trip->load_id);
        $this->assertSame(35, (int) $trip->delivery_province_code);
        $this->assertSame(now()->addDays(4)->toDateString(), $trip->delivery_date->toDateString());

        $this->load($owner, ['pickup_location' => 'İzmir Menemen', 'pickup_date' => now()->addDays(5)]);
        $this->actingAs($driver);
        Volt::test('driver.dashboard')->assertSee('Açık işlerim')->assertSee('Bursa')->assertSee('Dönüş yüklerini gizle')->assertSee('İzmir Menemen')->assertDontSee('Aktif sevkiyat');

        // Ödeme alınmadan yola çıkılamaz; NavlunIQ işi elle kapatılamaz, "Kapat" düğmesi de yoktur
        Volt::test('driver.jobs.index')->assertSee('NavlunIQ ilanı')->assertSee('Ödeme bekleniyor')->assertSee('Ayrıntı ve teslimat')->assertDontSee('Yola çıktım')->assertDontSee('Kapat')
            ->call('setStatus', $trip->id, 'on_the_way')->assertSee('ödemesini yapmadan')
            ->call('setStatus', $trip->id, 'closed')->assertSee('kendiliğinden kapanır');
        $this->assertSame('planned', $trip->fresh()->status);

        // Ödeme alınınca kart üzerinden "Yola çıktım" sevkiyatı da yola çıkarır
        $load->fresh()->update(['escrow_status' => Load::ESCROW_PAID]);
        Volt::test('driver.jobs.index')->assertSee('Yüklemeye hazır')->assertSee('Yola çıktım')->call('setStatus', $trip->id, 'on_the_way')->assertSee('Yolda')->assertSee('Teslim ettim');
        $this->assertSame('on_the_way', $trip->fresh()->status);
        $this->assertSame(Load::STATUS_ON_THE_WAY, $load->fresh()->status);

    }

    public function test_cancelled_load_closes_its_job(): void
    {
        $driver = $this->driver();
        $owner = $this->owner();
        $load = $this->load($owner);
        $offers = app(OfferService::class);
        $offers->accept($load->fresh(), $offers->submit($driver->driverProfile, $load, 40000), $owner->user_id);
        $trip = DriverTrip::query()->where('load_id', $load->id)->firstOrFail();

        app(LoadService::class)->cancel($load->fresh(), $owner, 'vazgeçildi');
        $this->assertSame('closed', $trip->fresh()->status);
        $this->actingAs($driver);
        Volt::test('driver.jobs.index')->assertSee('Açık işiniz yok')->call('setTab', 'past')->assertSee('İptal edildi')->assertSee('Ayrıntı');
    }

    public function test_reconcile_creates_missing_jobs_and_closes_finished_ones(): void
    {
        $driver = $this->driver();
        $owner = $this->owner();
        $load = $this->load($owner);
        $offers = app(OfferService::class);
        $shipment = $offers->accept($load->fresh(), $offers->submit($driver->driverProfile, $load, 40000), $owner->user_id);
        // Özellik eklenmeden önce kabul edilmiş teklif: sefer kaydı yok, sevkiyat yolda
        DriverTrip::query()->where('shipment_id', $shipment->id)->delete();
        $shipment->update(['status' => Shipment::STATUS_IN_TRANSIT]);
        $load->fresh()->update(['status' => Load::STATUS_ON_THE_WAY, 'escrow_status' => Load::ESCROW_PAID]);

        $this->assertSame(1, app(DriverTripService::class)->reconcile($driver->driverProfile));
        $trip = DriverTrip::query()->where('shipment_id', $shipment->id)->firstOrFail();
        $this->assertSame('on_the_way', $trip->status);
        $this->assertSame(0, app(DriverTripService::class)->reconcile($driver->driverProfile), 'Tekrar çalıştırmak bir şey değiştirmez');

        // Sevkiyat tamamlandı ama sefer açık kaldı: sayfa açılınca kapanır
        $shipment->update(['status' => Shipment::STATUS_COMPLETED]);
        $load->fresh()->update(['status' => Load::STATUS_COMPLETED]);
        $this->actingAs($driver);
        Volt::test('driver.jobs.index')->assertSee('Açık işiniz yok');
        $this->assertSame('closed', $trip->fresh()->status);
        $this->assertNotNull($trip->fresh()->closed_at);
    }

    public function test_dispute_resolution_closes_the_job_and_auto_close_skips_system_jobs(): void
    {
        $driver = $this->driver();
        $owner = $this->owner();
        $load = $this->load($owner);
        $offers = app(OfferService::class);
        $shipment = $offers->accept($load->fresh(), $offers->submit($driver->driverProfile, $load, 40000), $owner->user_id);
        $trip = DriverTrip::query()->where('shipment_id', $shipment->id)->firstOrFail();
        $load = $load->fresh();
        $load->update(['escrow_status' => Load::ESCROW_PAID]);
        app(ShipmentService::class)->startTransit($shipment->fresh(), $driver->driverProfile);
        $dispute = app(DisputeService::class)->open($load->fresh(), $owner->user, 'Yük hasarlı geldi');

        $this->actingAs($driver);
        Volt::test('driver.jobs.index')->assertSee('Uyuşmazlık')->assertSee('Ayrıntı ve teslimat');
        $this->assertSame('on_the_way', $trip->fresh()->status);

        // Uyuşmazlık sürerken 14 gün geçse de NavlunIQ işi kendiliğinden kapanmaz
        $trip->forceFill(['delivery_date' => now()->subDays(20)->toDateString()])->save();
        $this->assertSame(0, app(DriverTripService::class)->autoClose());
        $this->assertSame('on_the_way', $trip->fresh()->status);

        $admin = User::factory()->create();
        app(DisputeService::class)->resolve($dispute, $admin, 'driver_paid', 'Kanıt yeterli');
        $this->assertSame('closed', $trip->fresh()->status);
        Volt::test('driver.jobs.index')->call('setTab', 'past')->assertSee('Tamamlandı');
    }

    public function test_old_addresses_redirect_to_jobs(): void
    {
        $driver = $this->driver();
        $this->actingAs($driver);
        $this->get('/panel/sofor/sevkiyatlarim')->assertRedirect(route('driver.jobs.index'));
        $this->get('/panel/sofor/seferlerim?sefer=5&sekme=past')->assertRedirect(route('driver.jobs.index', ['is' => 5, 'sekme' => 'past']));
        $this->get('/panel/sofor/sevkiyat/7')->assertRedirect(route('driver.jobs.show', 7));
        $this->get(route('driver.jobs.index'))->assertOk()->assertSee('İşlerim')->assertDontSee('Sevkiyatlarım')->assertDontSee('Seferlerim');
    }

    public function test_trip_status_buttons_notify_toggle_and_auto_close(): void
    {
        $driver = $this->driver();
        $trip = app(DriverTripService::class)->takeExternal($driver->driverProfile, $this->scraped(), now(), now()->addDays(3));
        $this->actingAs($driver);

        Volt::test('driver.jobs.index')->assertSee('Yola çıktım')->assertSee('Dönüş yükü çıkınca bildir')
            ->call('setStatus', $trip->id, 'on_the_way')->assertSee('Yolda')
            ->call('toggleNotify', $trip->id)
            ->call('setStatus', $trip->id, 'delivered')->assertSee('Teslim edildi');
        $trip->refresh();
        $this->assertFalse($trip->notify_return);
        $this->assertSame(now()->toDateString(), $trip->delivery_date->toDateString(), 'Erken teslimde teslim tarihi bugüne çekilir');

        Settings::set('trip_auto_close_days', '3');
        $this->assertSame(0, app(DriverTripService::class)->autoClose());
        $trip->forceFill(['delivery_date' => now()->subDays(4)->toDateString()])->save();
        $this->assertSame(1, app(DriverTripService::class)->autoClose());
        $this->assertSame('closed', $trip->fresh()->status);
        Volt::test('driver.jobs.index')->assertSee('Açık işiniz yok')->call('setTab', 'past')->assertSee('Kapandı');
    }
}
