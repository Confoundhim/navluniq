<?php

namespace Tests\Feature\Loads;

use App\Models\CargoOwnerProfile;
use App\Models\DriverProfile;
use App\Models\DriverTrip;
use App\Models\DriverVehicle;
use App\Models\Load;
use App\Models\Shipment;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\LoadService;
use App\Services\OfferService;
use App\Services\ReviewService;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;
use RuntimeException;
use Tests\TestCase;

/** Yayın öncesi denetim P7-P8: ödeme süresi / hatırlatma / şoför vazgeçme; yükleme tarihi geçen ilanın kapanması; iade ile kapanan sevkiyat. */
class PaymentDeadlineAndExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Settings::set('scraper_free_delay_minutes', '0');
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
    }

    private function driver(): User
    {
        $user = User::factory()->driver()->create();
        $profile = DriverProfile::create(['user_id' => $user->id, 'kyc_status' => 'approved', 'premium_until' => now()->addMonth()]);
        DriverVehicle::create(['driver_profile_id' => $profile->id, 'plate' => '34ABC'.random_int(100, 999), 'vehicle_type' => 'tir', 'is_active' => true]);

        return $user;
    }

    private function publish(User $ownerUser, array $overrides = []): Load
    {
        $owner = CargoOwnerProfile::query()->firstOrCreate(['user_id' => $ownerUser->id], ['type' => 'individual']);

        return app(LoadService::class)->publish($owner, array_merge([
            'pickup_location' => 'İstanbul', 'delivery_location' => 'Ankara', 'pickup_date' => now()->addDay(), 'delivery_date' => now()->addDays(2),
            'vehicle_type' => 'tir', 'goods_type' => 'Paletli Yük', 'weight' => 12000, 'price' => 10000,
        ], $overrides));
    }

    private function titles(User $user): array
    {
        return UserNotification::query()->where('user_id', $user->id)->pluck('title')->all();
    }

    public function test_unpaid_acceptance_is_reminded_then_released_back_to_the_pool(): void
    {
        Settings::set('offer_payment_hours', '4');
        $owner = User::factory()->create();
        $driver = $this->driver();
        $load = $this->publish($owner);
        $offer = app(OfferService::class)->submit($driver->driverProfile, $load, 10000);
        app(OfferService::class)->accept($load, $offer, $owner->id);
        $load->refresh();
        $this->assertNotNull($load->payment_due_at);
        $this->assertEqualsWithDelta(4 * 60, now()->diffInMinutes($load->payment_due_at), 2);

        // Henüz sürenin yarısı dolmadı: ne hatırlatma ne serbest bırakma
        $this->assertSame(['released' => 0, 'reminded' => 0], app(OfferService::class)->expireUnpaid());

        // Yarısı doldu: yük sahibine tek hatırlatma
        $load->forceFill(['payment_due_at' => now()->addMinutes(90)])->save();
        $this->assertSame(['released' => 0, 'reminded' => 1], app(OfferService::class)->expireUnpaid());
        $this->assertSame(['released' => 0, 'reminded' => 0], app(OfferService::class)->expireUnpaid(), 'hatırlatma bir kez');
        $this->assertContains('Navlun ödemesi için 2 saat kaldı', $this->titles($owner));

        // Süre doldu: ilan havuza döner, teklif düşer, sevkiyat ve sefer kapanır, iki taraf bilgilendirilir
        $load->forceFill(['payment_due_at' => now()->subMinute()])->save();
        Artisan::call('loads:expire-unpaid');
        $load->refresh();
        $this->assertSame([Load::STATUS_ACTIVE, 'public', null, null], [$load->status, $load->visibility, $load->driver_profile_id, $load->payment_due_at]);
        $this->assertSame('expired', $offer->fresh()->status);
        $this->assertSame(Shipment::STATUS_CANCELLED, Shipment::query()->where('load_id', $load->id)->value('status'));
        $this->assertSame(DriverTrip::STATUS_CLOSED, DriverTrip::query()->where('load_id', $load->id)->value('status'));
        $this->assertContains('Şoför ataması kaldırıldı, ilanınız yeniden havuzda', $this->titles($owner));
        $this->assertContains('Ödeme gelmedi, iş kapandı', $this->titles($driver));

        // Aynı şoför yeniden teklif verebilir
        $this->assertSame('pending', app(OfferService::class)->submit($driver->driverProfile, $load, 9500)->status);
    }

    public function test_driver_can_withdraw_from_an_unpaid_job_from_the_jobs_screen(): void
    {
        $owner = User::factory()->create();
        $driver = $this->driver();
        $load = $this->publish($owner);
        $offer = app(OfferService::class)->submit($driver->driverProfile, $load, 10000);
        app(OfferService::class)->accept($load, $offer, $owner->id);
        $trip = DriverTrip::query()->where('load_id', $load->id)->firstOrFail();

        $this->actingAs($driver->fresh());
        Volt::test('driver.jobs.index')->assertSee('Vazgeç')->call('withdrawJob', $trip->id)->assertSee('Vazgeçtiniz');
        $this->assertSame([Load::STATUS_ACTIVE, 'withdrawn', DriverTrip::STATUS_CLOSED], [$load->fresh()->status, $offer->fresh()->status, $trip->fresh()->status]);
        $this->assertContains('Şoför ataması kaldırıldı, ilanınız yeniden havuzda', $this->titles($owner));

        // Ödeme yapılmışsa da yola çıkılmadan vazgeçilebilir (karar 4): ilan havuza döner, vazgeçme şoförün sicilinde sayılır,
        // navlun iadesi ödeme emrinde izlenir (ayrıntı: CancelRefundPolicyTest). Yola çıkılmışsa vazgeçilemez.
        $paid = $this->publish($owner);
        $offer2 = app(OfferService::class)->submit($driver->driverProfile, $paid, 10000);
        app(OfferService::class)->accept($paid, $offer2, $owner->id);
        $paid->update(['escrow_status' => Load::ESCROW_PAID]);
        app(OfferService::class)->withdrawAccepted($offer2->fresh(), $driver->driverProfile);
        $this->assertSame([Load::STATUS_ACTIVE, Load::ESCROW_PENDING, 'withdrawn', 1], [$paid->fresh()->status, $paid->fresh()->escrow_status, $offer2->fresh()->status, $driver->driverProfile->fresh()->withdrawals_after_payment]);

        $moving = $this->publish($owner);
        $offer3 = app(OfferService::class)->submit($driver->driverProfile, $moving, 10000);
        app(OfferService::class)->accept($moving, $offer3, $owner->id);
        $moving->update(['escrow_status' => Load::ESCROW_PAID, 'status' => Load::STATUS_ON_THE_WAY]);
        $this->expectException(RuntimeException::class);
        app(OfferService::class)->withdrawAccepted($offer3->fresh(), $driver->driverProfile);
    }

    public function test_loads_with_past_pickup_dates_expire_and_are_hidden_from_the_pool(): void
    {
        Settings::set('load_expiry_grace_days', '1');
        $owner = User::factory()->create();
        $driver = $this->driver();
        $fresh = $this->publish($owner);
        $stale = $this->publish($owner, ['pickup_location' => 'Bursa Nilüfer Zomzom OSB']);
        $offer = app(OfferService::class)->submit($driver->driverProfile, $stale, 9000);
        Load::query()->whereKey($stale->id)->update(['pickup_date' => now()->subDays(3)]);

        // Havuzda görünmez (teklifi olmayan başka şoför için), teklif verilemez, kabul edilemez
        $other = $this->driver();
        $this->actingAs($other->fresh());
        Volt::test('driver.loads.index')->assertSee('İstanbul')->assertDontSee('Zomzom');
        try {
            app(OfferService::class)->submit($other->driverProfile, $stale->fresh(), 9000);
            $this->fail('geçmiş tarihli ilana teklif reddedilmeli');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('yükleme tarihi geçmiş', $e->getMessage());
        }
        try {
            app(OfferService::class)->accept($stale->fresh(), $offer, $owner->id);
            $this->fail('geçmiş tarihli ilanda kabul reddedilmeli');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('yükleme tarihi geçmiş', $e->getMessage());
        }

        Artisan::call('loads:expire');
        $this->assertSame([Load::STATUS_CANCELLED, 'expired', Load::STATUS_ACTIVE], [$stale->fresh()->status, $offer->fresh()->status, $fresh->fresh()->status]);
        $this->assertStringContainsString('Yükleme tarihi geçti', (string) $stale->fresh()->rejection_reason);
        $this->assertContains('İlanınızın yükleme tarihi geçti', $this->titles($owner));
        $this->assertContains('İlan kapandı, teklifiniz düştü', $this->titles($driver));

        // "Tekrar yayınla" yeni tarihle açar
        $again = app(LoadService::class)->repeat($stale->fresh(), $owner->fresh()->cargoOwnerProfile);
        $this->assertSame(Load::STATUS_ACTIVE, $again->status);
    }

    public function test_refund_closed_shipment_is_labelled_and_cannot_be_reviewed(): void
    {
        $owner = User::factory()->create();
        $driver = $this->driver();
        $load = $this->publish($owner);
        $load->update(['status' => Load::STATUS_COMPLETED, 'escrow_status' => Load::ESCROW_REFUNDED, 'driver_profile_id' => $driver->driverProfile->id]);
        $this->assertSame('İade ile kapandı', $load->fresh()->statusLabel());
        $this->expectException(RuntimeException::class);
        app(ReviewService::class)->submit($load->fresh(), $owner, 5, 'olmadı');
    }

    /** Durum makinesi denetimi V1: ödenmeyen kabul geri açılınca ilan ikinci bir şoföre atanabilmeli (sevkiyat satırı yeniden kullanılır). */
    public function test_load_can_be_reassigned_after_withdrawal_and_after_unpaid_expiry(): void
    {
        $owner = User::factory()->create();
        $driverA = $this->driver();
        $driverB = $this->driver();
        $load = $this->publish($owner);
        $offers = app(OfferService::class);

        $offerA = $offers->submit($driverA->driverProfile, $load, 10000);
        $offers->accept($load, $offerA, $owner->id);
        $offers->withdrawAccepted($offerA->fresh(), $driverA->driverProfile);
        $this->assertSame(Load::STATUS_ACTIVE, $load->fresh()->status);

        $offerB = $offers->submit($driverB->driverProfile, $load->fresh(), 9500);
        $offers->accept($load->fresh(), $offerB, $owner->id);
        $load->refresh();
        $this->assertSame(Load::STATUS_ASSIGNED, $load->status);
        $this->assertSame($driverB->driverProfile->id, $load->driver_profile_id);
        $this->assertSame(1, Shipment::query()->where('load_id', $load->id)->count(), 'ilan başına tek sevkiyat satırı');
        $this->assertSame([Shipment::STATUS_AWAITING_PICKUP, $driverB->driverProfile->id], [$load->shipment->status, $load->shipment->driver_profile_id]);

        // Ödeme süresi dolar, ilan havuza döner, üçüncü kabul de sorunsuz
        $load->forceFill(['payment_due_at' => now()->subMinute()])->save();
        $this->assertSame(1, app(OfferService::class)->expireUnpaid()['released']);
        $offerA2 = $offers->submit($driverA->driverProfile, $load->fresh(), 9000);
        $offers->accept($load->fresh(), $offerA2, $owner->id);
        $this->assertSame($driverA->driverProfile->id, $load->fresh()->driver_profile_id);
        $this->assertSame(1, Shipment::query()->where('load_id', $load->id)->count());
    }

    /** V5: kabul edilmiş teklif geri çekilemez/reddedilemez ve süresi dolmuş sayılmaz (koşullu yazım). */
    public function test_accepted_offer_cannot_be_withdrawn_rejected_or_expired(): void
    {
        $owner = User::factory()->create();
        $driver = $this->driver();
        $load = $this->publish($owner);
        $offers = app(OfferService::class);
        $offer = $offers->submit($driver->driverProfile, $load, 10000);
        $offers->accept($load, $offer, $owner->id);

        foreach ([fn () => $offers->withdraw($offer->fresh(), $driver->driverProfile), fn () => $offers->reject($offer->fresh(), $owner->id)] as $attempt) {
            try {
                $attempt();
                $this->fail('kabul edilmiş teklif değiştirilmemeliydi');
            } catch (RuntimeException) {
            }
        }
        $offer->forceFill(['expires_at' => now()->subHour()])->save();
        $this->assertSame(0, $offers->expireStale());
        $this->assertSame('accepted', $offer->fresh()->status);
    }
}
