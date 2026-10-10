<?php

namespace Tests\Feature\Driver;

use App\Models\CmsContent;
use App\Models\DriverProfile;
use App\Models\DriverVehicle;
use App\Models\PaymentOrder;
use App\Models\StoredCard;
use App\Models\Subscription;
use App\Models\SubscriptionCycle;
use App\Models\User;
use App\Models\UserNotification;
use App\Payments\GatewayManager;
use App\Services\SubscriptionService;
use App\Support\Settings;
use Database\Seeders\CmsContractSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;
use Tests\Feature\Payments\StoredCardFakeGateway;
use Tests\TestCase;

/**
 * Otomatik yenilenen premium: satın almada seçilir, kart kuruluşta saklanır (NavlunIQ'da yalnız anahtar), dönem bitimine 72 saat
 * kala güncel fiyattan çekilir, başarısızlıkta 24 saat arayla 3 deneme, sonra kapanır; şoför tek dokunuşla kapatır / kartı siler.
 * Kart saklama kapalıyken hiçbir ekranda otomatik yenileme görünmez ve abonelik tek seferliktir.
 */
class PremiumAutoRenewTest extends TestCase
{
    use RefreshDatabase;

    private StoredCardFakeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
        Settings::set('premium_monthly_price', '900');
        Settings::set('premium_discount_3m', '10');
        config(['services.payment.provider' => 'fake', 'services.payment.vat_rate' => 20]);
        $this->gateway = new StoredCardFakeGateway;
        app(GatewayManager::class)->swap('fake', $this->gateway);
    }

    public function test_checkout_offers_auto_renew_and_saved_card_turns_it_on(): void
    {
        $driver = $this->driver();
        $this->actingAs($driver);

        $this->get(route('driver.premium.checkout', ['sure' => 3]))->assertOk()->assertSee('Otomatik yenile')->assertDontSee('Üyelik otomatik yenilenmez');

        // Onay + otomatik yenileme işaretli → emir tercihi taşır, kuruluşa kart saklama izni gider
        Volt::test('driver.premium.checkout', ['sure' => 3])->set('accepted', true)->call('pay');
        $order = PaymentOrder::query()->where('user_id', $driver->id)->latest('id')->firstOrFail();
        $this->assertTrue((bool) $order->auto_renew);
        $this->assertSame(3, (int) $order->subscription_months);
        $this->assertTrue((bool) ($this->gateway->lastContext['save_card'] ?? false));
        $this->assertNull($this->gateway->lastContext['card_user_key'] ?? null, 'İlk ödemede kayıtlı kart yok');

        // Kullanıcı kuruluş sayfasında kartını kaydetti: bildirimde kart anahtarı gelir → kart saklanır, yenileme açılır
        $this->post('/odeme/bildirim/fake', ['merchant_oid' => $order->merchant_oid, 'status' => 'success', 'sig' => 'ok', 'card_token' => 'tok-abc', 'last_four' => '4242'])->assertOk();
        $card = StoredCard::query()->where('user_id', $driver->id)->firstOrFail();
        $this->assertSame('tok-abc', $card->card_token);
        $this->assertSame('Visa •••• 4242', $card->label());
        $this->assertNotSame('tok-abc', \DB::table('stored_cards')->value('card_token'), 'Kart anahtarı şifreli durur');

        $subscription = Subscription::query()->where('user_id', $driver->id)->where('plan_code', SubscriptionService::PLAN_PREMIUM_MONTHLY)->firstOrFail();
        $this->assertTrue($subscription->auto_renew);
        $this->assertSame(3, $subscription->renew_months);
        $this->assertSame($card->id, $subscription->stored_card_id);
        $this->assertTrue(app(SubscriptionService::class)->willAutoRenew($subscription));
        $this->assertStringContainsString('Otomatik yenileme açık', $this->lastNotification($driver));

        // Premium sayfası durumu gösterir; ikinci ödemede kuruluşa kayıtlı kart anahtarı gider
        $this->get(route('driver.premium.index'))->assertOk()->assertSee('Otomatik yenileme')->assertSee('Açık')->assertSee('Visa •••• 4242')->assertSee('Yenilemeyi kapat');
        Volt::test('driver.premium.checkout', ['sure' => 1])->set('accepted', true)->call('pay');
        $this->assertSame('cuk-'.$order->merchant_oid, $this->gateway->lastContext['card_user_key'] ?? null);
    }

    public function test_auto_renew_requested_but_card_not_saved_leaves_subscription_one_off(): void
    {
        $driver = $this->driver();
        $order = app(SubscriptionService::class)->startCheckout($driver, 1, true);
        $this->post('/odeme/bildirim/fake', ['merchant_oid' => $order->merchant_oid, 'status' => 'success', 'sig' => 'ok'])->assertOk();

        $subscription = Subscription::query()->where('user_id', $driver->id)->firstOrFail();
        $this->assertFalse($subscription->auto_renew);
        $this->assertNull($subscription->stored_card_id);
        $this->assertStringContainsString('kart kaydedilmediği için otomatik yenileme açılmadı', $this->lastNotification($driver));
    }

    public function test_renew_due_charges_stored_card_three_days_before_end_and_extends_period(): void
    {
        $driver = $this->driver();
        [$subscription, $card] = $this->renewingSubscription($driver, 3, now()->addDays(10));

        $this->assertSame(0, app(SubscriptionService::class)->renewDue(), '10 gün varken çekim yok');
        $subscription->update(['current_period_ends_at' => now()->addHours(60)]);
        $driver->driverProfile->update(['premium_until' => now()->addHours(60)]);

        Settings::set('premium_monthly_price', '1000'); // yenileme güncel fiyattan: 3 ay × 1000 × %90
        $this->assertSame(1, app(SubscriptionService::class)->renewDue());
        $this->assertCount(1, $this->gateway->charges);
        $this->assertSame(2700.0, $this->gateway->charges[0]['amount']);
        $this->assertSame($card->id, $this->gateway->charges[0]['card']);

        $renewal = PaymentOrder::query()->where('user_id', $driver->id)->where('stored_card_id', $card->id)->firstOrFail();
        $this->assertSame('paid', $renewal->status);
        $this->assertSame('fake-renew-'.$renewal->id, $renewal->provider_reference);
        $this->assertSame(1, $renewal->events()->where('event_type', 'renewal')->count());

        $subscription->refresh();
        $this->assertTrue($subscription->auto_renew, 'Yenileme açık kalır');
        $this->assertSame(0, $subscription->renewal_failures);
        $this->assertNull($subscription->next_renewal_attempt_at);
        $this->assertEqualsWithDelta(now()->addHours(60)->addMonthsNoOverflow(3)->timestamp, $subscription->current_period_ends_at->timestamp, 5, 'Yeni dönem eskisinin bitimine eklenir');
        $this->assertEqualsWithDelta($subscription->current_period_ends_at->timestamp, $driver->driverProfile->fresh()->premium_until->timestamp, 5);
        $this->assertSame(2, SubscriptionCycle::query()->where('subscription_id', $subscription->id)->count());
        $this->assertStringContainsString('Premium üyeliğiniz yenilendi', $this->lastNotification($driver));
        $this->assertStringContainsString('2.700,00 ₺', $this->lastNotification($driver));

        // Aynı saat içinde ikinci çalıştırma yeniden çekmez (dönem artık uzakta)
        $this->assertSame(0, app(SubscriptionService::class)->renewDue());
        $this->assertCount(1, $this->gateway->charges);
    }

    public function test_failed_charges_retry_daily_then_stop_after_third_attempt(): void
    {
        $driver = $this->driver();
        [$subscription] = $this->renewingSubscription($driver, 1, now()->addHours(70));
        $this->gateway->chargeSucceeds = false;

        $this->assertSame(0, app(SubscriptionService::class)->renewDue());
        $subscription->refresh();
        $this->assertSame(1, $subscription->renewal_failures);
        $this->assertStringContainsString('Yetersiz bakiye', (string) $subscription->last_renewal_error);
        $this->assertEqualsWithDelta(now()->addHours(24)->timestamp, $subscription->next_renewal_attempt_at->timestamp, 5);
        $this->assertStringContainsString('yeniden denenecek (1', $this->lastNotification($driver));
        $this->assertSame('failed', PaymentOrder::query()->where('user_id', $driver->id)->latest('id')->value('status'));

        $this->assertSame(0, app(SubscriptionService::class)->renewDue(), 'Tekrar saati gelmeden denenmez');
        $this->assertCount(1, $this->gateway->charges);

        $this->travel(25)->hours();
        app(SubscriptionService::class)->renewDue();
        $this->travel(25)->hours();
        app(SubscriptionService::class)->renewDue();
        $this->assertCount(3, $this->gateway->charges);
        $subscription->refresh();
        $this->assertFalse($subscription->auto_renew, 'Üçüncü başarısızlıkta yenileme kapanır');
        $this->assertSame(3, $subscription->renewal_failures);
        $this->assertStringContainsString('Otomatik yenileme kapatıldı', $this->lastNotification($driver));
        $this->assertTrue($driver->driverProfile->fresh()->isPremium(), 'Dönem sonuna kadar premium sürer');

        $this->travel(26)->hours();
        $this->assertSame(0, app(SubscriptionService::class)->renewDue());
        $this->assertCount(3, $this->gateway->charges);
    }

    public function test_invalid_card_stops_renewal_immediately(): void
    {
        $driver = $this->driver();
        [$subscription] = $this->renewingSubscription($driver, 1, now()->addHours(70));
        $this->gateway->chargeSucceeds = false;
        $this->gateway->cardInvalid = true;

        app(SubscriptionService::class)->renewDue();
        $this->assertFalse($subscription->fresh()->auto_renew);
        $this->assertStringContainsString('Kartın süresi dolmuş', $this->lastNotification($driver));
    }

    public function test_driver_toggles_auto_renew_and_deletes_card_from_premium_page(): void
    {
        $driver = $this->driver();
        [$subscription, $card] = $this->renewingSubscription($driver, 1, now()->addDays(20));
        $this->actingAs($driver);

        Volt::test('driver.premium.index')->call('setAutoRenew', false);
        $this->assertFalse($subscription->fresh()->auto_renew);
        $this->assertTrue($driver->driverProfile->fresh()->isPremium(), 'Kapatma dönem sonuna kadar hakları etkilemez');
        $this->get(route('driver.premium.index'))->assertOk()->assertSee('Kapalı')->assertSee('Yenilemeyi aç');

        Volt::test('driver.premium.index')->call('setAutoRenew', true);
        $this->assertTrue($subscription->fresh()->auto_renew);
        $this->assertSame($card->id, $subscription->fresh()->stored_card_id);

        Volt::test('driver.premium.index')->call('deleteCard', $card->id);
        $this->assertSame([$card->id], $this->gateway->deletedCards);
        $this->assertNull(StoredCard::find($card->id));
        $this->assertFalse($subscription->fresh()->auto_renew);
        $this->assertNull($subscription->fresh()->stored_card_id);
        $this->assertSame(0, app(SubscriptionService::class)->renewDue(), 'Kartsız abonelik çekilmez');

        // Başkasının kartı silinemez
        $other = $this->driver();
        $otherCard = StoredCard::create(['user_id' => $other->id, 'provider' => 'fake', 'card_user_key' => 'cuk-o', 'card_token' => 'tok-o', 'last_four' => '9999']);
        Volt::test('driver.premium.index')->call('deleteCard', $otherCard->id);
        $this->assertNotNull(StoredCard::find($otherCard->id));
    }

    public function test_reminder_before_renewal_states_the_amount(): void
    {
        $driver = $this->driver();
        [$subscription] = $this->renewingSubscription($driver, 3, now()->addDays(4)->setTime(12, 0));

        $this->assertSame(1, app(SubscriptionService::class)->remindExpiring(3), 'Yenilenecek abonelik 5 gün kala hatırlatılır (çekimden önce)');
        $text = $this->lastNotification($driver);
        $this->assertStringContainsString('Otomatik yenileme açık', $text);
        $this->assertStringContainsString('2.430,00 ₺', $text);
        $this->assertStringContainsString('3 ay uzatılır', $text);

        // Yenilenmeyen abonelikte pencere eskisi gibi 3 gün
        $subscription->update(['auto_renew' => false]);
        UserNotification::query()->delete();
        $this->assertSame(0, app(SubscriptionService::class)->remindExpiring(3));
    }

    public function test_without_card_storage_everything_stays_one_off(): void
    {
        $this->gateway->storedCards = false;
        $driver = $this->driver();
        $this->actingAs($driver);

        $this->get(route('driver.premium.checkout', ['sure' => 1]))->assertOk()->assertSee('Üyelik otomatik yenilenmez')->assertDontSee('Otomatik yenile ');
        $this->get(route('subscription'))->assertOk()->assertSee('Otomatik yenilenmez')->assertDontSee('Yenileme sizin seçiminiz');

        $order = app(SubscriptionService::class)->startCheckout($driver, 1, true);
        $this->assertFalse((bool) $order->auto_renew, 'Kart saklama kapalıyken tercih kaydedilmez');
        $this->post('/odeme/bildirim/fake', ['merchant_oid' => $order->merchant_oid, 'status' => 'success', 'sig' => 'ok', 'card_token' => 'tok-x'])->assertOk();
        $this->assertFalse(Subscription::query()->where('user_id', $driver->id)->firstOrFail()->auto_renew);
        $this->assertSame(0, app(SubscriptionService::class)->renewDue());

        $this->gateway->storedCards = true;
        $this->get(route('subscription'))->assertOk()->assertSee('Yenileme sizin seçiminiz');
    }

    public function test_contract_texts_cover_opt_in_renewal(): void
    {
        (new CmsContractSeeder)->run();
        $distance = (string) CmsContent::getVal('contract_distance_sale');
        $cancellation = (string) CmsContent::getVal('contract_cancellation');
        $this->assertStringContainsString('otomatik yenilenmez', $distance, 'Seçilmediği sürece yenilenmez');
        $this->assertStringContainsString('işaretlemediği sürece', $distance);
        $this->assertStringContainsString('tek işlemle kapatabilir', $distance);
        $this->assertStringContainsString('en çok üç kez', $distance);
        $this->assertStringContainsString('seçilmediği sürece', $cancellation);
        $this->assertStringContainsString('kayıtlı karttan tahsil edilir', $cancellation);
    }

    /** Otomatik yenilemesi açık, kartı kayıtlı, dönemi verilen tarihte biten ücretli abonelik. */
    private function renewingSubscription(User $driver, int $months, Carbon $endsAt): array
    {
        $card = StoredCard::create(['user_id' => $driver->id, 'provider' => 'fake', 'card_user_key' => 'cuk-'.$driver->id, 'card_token' => 'tok-'.$driver->id, 'last_four' => '4242', 'card_association' => 'VISA']);
        $subscription = Subscription::create([
            'user_id' => $driver->id, 'plan_code' => SubscriptionService::PLAN_PREMIUM_MONTHLY, 'provider' => 'fake', 'status' => 'active',
            'amount' => 900 * $months, 'currency' => 'TRY', 'interval' => $months === 1 ? 'monthly' : $months.'_months',
            'auto_renew' => true, 'renew_months' => $months, 'stored_card_id' => $card->id,
            'current_period_starts_at' => $endsAt->copy()->subMonthsNoOverflow($months), 'current_period_ends_at' => $endsAt,
        ]);
        SubscriptionCycle::create(['subscription_id' => $subscription->id, 'period_start' => $subscription->current_period_starts_at, 'period_end' => $endsAt, 'amount' => 900 * $months, 'currency' => 'TRY', 'status' => 'paid', 'paid_at' => now()]);
        $driver->driverProfile->update(['premium_until' => $endsAt]);

        return [$subscription, $card];
    }

    private function lastNotification(User $user): string
    {
        return (string) json_encode(UserNotification::query()->where('user_id', $user->id)->latest('id')->first()?->toArray() ?? [], JSON_UNESCAPED_UNICODE);
    }

    private function driver(): User
    {
        $user = User::factory()->driver()->create();
        $user->syncRoles(['driver']);
        $profile = DriverProfile::create(['user_id' => $user->id, 'kyc_status' => 'approved', 'kyc_verified_at' => now(), 'identity_number' => '10000000146', 'trial_started_at' => now()]);
        DriverVehicle::create(['driver_profile_id' => $profile->id, 'plate' => '06'.random_int(100, 999).'AR'.random_int(10, 99), 'brand' => 'Mercedes', 'model' => 'Actros', 'vehicle_type' => 'tir', 'is_active' => true]);

        return $user->fresh();
    }
}
