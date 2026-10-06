<?php

namespace Tests\Feature\Loads;

use App\Jobs\SendNotificationMail;
use App\Models\CargoOwnerProfile;
use App\Models\DriverProfile;
use App\Models\DriverTrip;
use App\Models\DriverVehicle;
use App\Models\Load;
use App\Models\Offer;
use App\Models\Shipment;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\CargoOwnerVerificationService;
use App\Services\DriverLocationService;
use App\Services\DriverTripService;
use App\Services\NotificationService;
use App\Services\NviService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 2026-10-06 panel ve süreç denetimi (docs/PANEL_DENETIMI_2026-10-06.md) düzeltmeleri ve Osman'ın bildirim kuralları:
 * standart üyeye bildirim yok (panelde görür), dönüş yükü bildirimi yalnız premium, konum uyuşmazlıkta da kaydedilir,
 * genel bakışta teklif verilmiş ilan yok, geçmiş işin ayrıntısı açılır, NVİ kesintisi deneme hakkı yakmaz, e-posta kuyrukta.
 */
class ProcessAuditFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function owner(array $profile = []): User
    {
        $user = User::factory()->create(['current_role' => 'cargo_owner', 'first_name' => 'Ayşe', 'last_name' => 'Yılmaz']);
        $user->syncRoles(['cargo_owner']);
        CargoOwnerProfile::create(array_merge(['user_id' => $user->id, 'type' => 'individual', 'nvi_verified' => true], $profile));

        return $user->fresh();
    }

    private function driver(bool $premium = true): User
    {
        $user = User::factory()->driver()->create();
        $user->syncRoles(['driver']);
        $profile = DriverProfile::create(['user_id' => $user->id, 'kyc_status' => 'approved', 'kyc_verified_at' => now(), 'premium_until' => $premium ? now()->addMonth() : null]);
        DriverVehicle::create(['driver_profile_id' => $profile->id, 'plate' => '34'.random_int(100, 999).'AB'.random_int(10, 99), 'brand' => 'Mercedes', 'model' => 'Actros', 'vehicle_type' => 'tir', 'body_type' => 'tenteli', 'is_active' => true]);

        return $user->fresh();
    }

    private function load(User $owner, array $extra = []): Load
    {
        return Load::create(array_merge([
            'cargo_owner_profile_id' => $owner->cargoOwnerProfile->id, 'source_type' => 'internal', 'visibility' => 'public',
            'pickup_location' => 'Ankara', 'pickup_province_code' => 6, 'delivery_location' => 'İzmir', 'delivery_province_code' => 35,
            'pickup_date' => now()->addDays(3), 'vehicle_type' => 'tir', 'body_types' => ['tenteli'],
            'goods_type' => Load::GOODS_TYPES[0], 'price' => 15000, 'status' => Load::STATUS_ACTIVE, 'escrow_status' => Load::ESCROW_PENDING, 'published_at' => now(),
        ], $extra));
    }

    public function test_location_is_recorded_while_the_shipment_is_disputed_but_not_yet_delivered(): void
    {
        $driver = $this->driver();
        $load = $this->load($this->owner(), ['status' => Load::STATUS_DISPUTED, 'escrow_status' => Load::ESCROW_ON_HOLD, 'driver_profile_id' => $driver->driverProfile->id]);
        $shipment = Shipment::create(['load_id' => $load->id, 'driver_profile_id' => $driver->driverProfile->id, 'status' => Shipment::STATUS_DISPUTED, 'in_transit_at' => now()->subHour()]);

        $svc = app(DriverLocationService::class);
        $this->assertNotNull($svc->record($driver->driverProfile, 39.9, 32.8, shipmentId: $shipment->id), 'Yoldayken açılan uyuşmazlıkta konum kaydedilmeli');

        $shipment->update(['delivered_at' => now()]);
        $this->travel(20)->seconds();
        $this->assertNull($svc->record($driver->driverProfile, 39.91, 32.81, shipmentId: $shipment->id), 'Teslim edilmiş sevkiyata konum yazılmaz');
    }

    public function test_return_load_notifications_go_only_to_premium_drivers(): void
    {
        $owner = $this->owner();
        $free = $this->driver(premium: false);
        $premium = $this->driver();
        foreach ([$free, $premium] as $u) {
            DriverTrip::create(['driver_profile_id' => $u->driverProfile->id, 'source' => 'external', 'pickup_location' => 'Ankara', 'pickup_province_code' => 6,
                'delivery_location' => 'İzmir', 'delivery_province_code' => 35, 'delivery_date' => now()->addDay(), 'status' => DriverTrip::STATUS_PLANNED, 'notify_return' => true, 'created_at' => now()->subMinute()]);
        }
        $this->load($owner, ['pickup_location' => 'İzmir Torbalı', 'pickup_province_code' => 35, 'delivery_location' => 'Ankara', 'delivery_province_code' => 6, 'pickup_date' => now()->addDays(3)]);

        $r = app(DriverTripService::class)->scanReturnLoads();
        $this->assertSame(['trips' => 2, 'notified' => 1], $r);
        $this->assertSame(0, UserNotification::query()->where('user_id', $free->id)->count(), 'Standart üyeye dönüş yükü bildirimi gitmez');
        $this->assertSame(1, UserNotification::query()->where('user_id', $premium->id)->where('type', 'return_load')->count());

        // Standart üye dönüş yüklerini İşlerim'de yine görür
        $trip = DriverTrip::query()->where('driver_profile_id', $free->driverProfile->id)->first();
        $this->assertCount(1, app(DriverTripService::class)->returnLoadsFor($trip)['system']);
    }

    public function test_dashboard_hides_loads_the_driver_already_offered_on_and_past_pickup_dates(): void
    {
        $owner = $this->owner();
        $driver = $this->driver();
        $open = $this->load($owner, ['pickup_location' => 'Konya Karatay']);
        $offered = $this->load($owner, ['pickup_location' => 'Bursa Nilüfer']);
        $this->load($owner, ['pickup_location' => 'Eskişehir Tepebaşı', 'pickup_date' => now()->subDays(2)]);
        Offer::create(['load_id' => $offered->id, 'driver_profile_id' => $driver->driverProfile->id, 'amount' => 14000, 'currency' => 'TRY', 'status' => 'pending', 'expires_at' => now()->addDays(2)]);

        $this->actingAs($driver);
        Volt::test('driver.dashboard')->assertSee('Konya Karatay')->assertDontSee('Bursa Nilüfer')->assertDontSee('Eskişehir Tepebaşı');
        $this->assertSame([$open->id], Load::query()->offerableBy($driver->driverProfile)->pluck('id')->all());
    }

    public function test_job_detail_still_opens_for_a_withdrawn_history_job(): void
    {
        $driver = $this->driver();
        $load = $this->load($this->owner(), ['driver_profile_id' => null]);
        Shipment::create(['load_id' => $load->id, 'driver_profile_id' => $driver->driverProfile->id, 'status' => Shipment::STATUS_CANCELLED]);

        $this->actingAs($driver);
        Volt::test('driver.jobs.show', ['loadId' => $load->id])->assertNoRedirect()->assertSet('loadId', $load->id);

        $other = $this->driver();
        $this->actingAs($other);
        Volt::test('driver.jobs.show', ['loadId' => $load->id])->assertRedirect(route('driver.jobs.index'));
    }

    public function test_free_driver_new_load_counter_sees_loads_released_after_the_premium_window(): void
    {
        $owner = $this->owner();
        $free = $this->driver(premium: false);
        $this->load($owner, ['pickup_location' => 'Samsun İlkadım', 'available_to_free_at' => now()->addMinutes(20)]);

        $this->actingAs($free);
        $c = Volt::test('driver.loads.index')->assertDontSee('Samsun İlkadım');
        $c->call('checkNew')->assertSet('newCount', 0);
        $this->travel(21)->minutes();
        $c->call('checkNew')->assertSet('newCount', 1);
        $c->call('showNew')->assertSee('Samsun İlkadım');
    }

    public function test_nvi_outage_does_not_consume_the_daily_attempts(): void
    {
        $owner = $this->owner(['nvi_verified' => false]);
        $this->mock(NviService::class)->shouldReceive('verify')->once()
            ->andReturn(['success' => false, 'is_match' => false, 'message' => 'Nüfus Müdürlüğü sunucularına şu an ulaşılamıyor. Lütfen daha sonra tekrar deneyin.', 'source' => 'Hata']);
        $error = app(CargoOwnerVerificationService::class)->verifyIdentity($owner, '12345678901', '1990');
        $this->assertStringContainsString('ulaşılamıyor', (string) $error);
        $this->assertSame(0, RateLimiter::attempts('nvi:self:'.$owner->id), 'Servis cevap vermeyince deneme hakkı yanmaz');
        $this->assertNull($owner->cargoOwnerProfile->fresh()->nvi_checked_at);
    }

    public function test_corporate_owner_can_update_company_details_and_admin_confirmation_resets(): void
    {
        $corp = $this->owner(['type' => 'corporate', 'company_title' => 'Deneme Lojistik A.Ş.', 'tax_no' => '1234567890', 'nvi_verified' => false, 'gib_verified' => true, 'gib_verified_at' => now()]);
        $this->actingAs($corp);
        Volt::test('cargo-owner.profile.index')->assertSee('Şirket bilgilerini kaydet')
            ->set('tax_no_input', '1234567891')->call('updateCompany')->assertHasErrors(['tax_no_input']) // mod-10 geçersiz
            ->set('tax_no_input', '1234567890')->set('company_title_input', 'Deneme Nakliyat Ltd. Şti.')->set('tax_office_input', 'Kızılbey')
            ->call('updateCompany')->assertHasNoErrors();
        $profile = $corp->cargoOwnerProfile->fresh();
        $this->assertSame(['Deneme Nakliyat Ltd. Şti.', 'Kızılbey', false], [$profile->company_title, $profile->tax_office, (bool) $profile->gib_verified]);
        $this->assertNull($profile->verificationBlocker(), 'Vergi numarası kayıtlı kurumsal hesap teklif kabul edebilir');
    }

    public function test_notification_mail_is_queued_when_the_worker_is_alive_and_sent_inline_otherwise(): void
    {
        Mail::fake();
        Queue::fake();
        $user = $this->driver();
        $svc = app(NotificationService::class);

        $inline = $svc->notify($user, 'Deneme', ['satır']);
        Queue::assertNothingPushed();
        $this->assertSame(UserNotification::MAIL_SENT, $inline->fresh()->mail_status);

        Cache::put('queue.heartbeat', now()->timestamp, now()->addDay());
        $queued = $svc->notify($user, 'Deneme 2', ['satır']);
        Queue::assertPushed(SendNotificationMail::class, fn (SendNotificationMail $job) => $job->notificationId === $queued->id);
        $this->assertSame(UserNotification::MAIL_PENDING, $queued->fresh()->mail_status);

        (new SendNotificationMail($queued->id))->handle($svc);
        $this->assertSame(UserNotification::MAIL_SENT, $queued->fresh()->mail_status);
    }

    public function test_csp_allows_the_map_tile_servers_used_by_the_pages(): void
    {
        $response = $this->get('/');
        $csp = (string) ($response->headers->get('Content-Security-Policy-Report-Only') ?? $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('basemaps.cartocdn.com', $csp);
        $this->assertStringContainsString('tile.openstreetmap.org', $csp);
    }
}
