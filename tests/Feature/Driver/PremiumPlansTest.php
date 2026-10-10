<?php

namespace Tests\Feature\Driver;

use App\Models\DriverProfile;
use App\Models\DriverVehicle;
use App\Models\PaymentOrder;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\SubscriptionService;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Premium süre seçenekleri: 1 ay tam fiyat; 3/6/12 ay panel ayarlı indirimle; ödeme onayında seçilen ay kadar süre eklenir. */
class PremiumPlansTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
        Settings::set('premium_monthly_price', '900');
        Settings::set('premium_discount_3m', '10');
        Settings::set('premium_discount_6m', '15');
        Settings::set('premium_discount_12m', '25');
    }

    public function test_plan_prices_follow_panel_discounts(): void
    {
        $s = app(SubscriptionService::class);
        $this->assertSame(900.0, $s->priceFor(1));
        $this->assertSame(2430.0, $s->priceFor(3));
        $this->assertSame(4590.0, $s->priceFor(6));
        $this->assertSame(8100.0, $s->priceFor(12));
        $this->assertSame([1, 3, 6, 12], array_column($s->plans(), 'months'));
        $this->assertSame(675.0, $s->plans()[3]['per_month']);
        $this->assertSame(2700.0, $s->plans()[3]['saving']);

        Settings::set('premium_discount_12m', '0');
        $this->assertSame(10800.0, $s->priceFor(12), 'İndirim 0 ise liste fiyatı');
        $this->expectException(\RuntimeException::class);
        $s->priceFor(5);
    }

    public function test_checkout_order_carries_months_and_activation_adds_that_many_months(): void
    {
        Settings::set('payment_provider', 'iyzico');
        Settings::set('iyzico_api_key', 'sandbox-x');
        Settings::set('iyzico_secret_key', 'sandbox-y');
        Settings::set('iyzico_sandbox', '1');
        $driver = $this->driver();

        $order = app(SubscriptionService::class)->startCheckout($driver, 6);
        $this->assertSame(6, (int) $order->subscription_months);
        $this->assertSame('4590.0000', (string) $order->amount);
        $this->assertSame($order->id, app(SubscriptionService::class)->startCheckout($driver, 6)->id, 'Aynı süre için açık emir yeniden kullanılır');
        $this->assertNotSame($order->id, app(SubscriptionService::class)->startCheckout($driver, 3)->id, 'Farklı süre yeni emir açar');

        $order->update(['status' => 'paid', 'paid_at' => now()]);
        $subscription = app(SubscriptionService::class)->activate($order);
        $this->assertSame('6_months', $subscription->interval);
        $this->assertEqualsWithDelta(now()->addMonthsNoOverflow(6)->timestamp, $driver->driverProfile->fresh()->premium_until->timestamp, 5);
        $this->assertStringContainsString('6 aylık premium üyeliğiniz', (string) json_encode(UserNotification::query()->where('user_id', $driver->id)->latest('id')->first()?->toArray() ?? [], JSON_UNESCAPED_UNICODE));
    }

    public function test_premium_page_lists_four_plans_and_checkout_reads_the_choice(): void
    {
        Settings::set('payment_provider', 'iyzico');
        Settings::set('iyzico_api_key', 'sandbox-x');
        Settings::set('iyzico_secret_key', 'sandbox-y');
        Settings::set('iyzico_sandbox', '1');
        Http::fake(['sandbox-api.iyzipay.com/*' => Http::response(['status' => 'failure', 'errorMessage' => 'deneme'])]);
        $driver = $this->driver();
        $this->actingAs($driver);

        $this->get(route('driver.premium.index'))->assertOk()
            ->assertSee('Süre seçin')->assertSee('12 ay')->assertSee('%25 indirim')->assertSee('8.100 ₺')->assertSee('2.700 ₺ kazanç')
            ->assertSee(route('driver.premium.checkout', ['sure' => 12]));

        // Özet seçilen süreyi, indirimi ve yeni bitişi gösterir; sipariş yalnız onaydan sonra açılır
        $this->get(route('driver.premium.checkout', ['sure' => 12]))->assertOk()
            ->assertSee('12 ay')->assertSee('10.800,00 ₺')->assertSee('8.100,00 ₺')->assertSee('Süre indirimi')->assertSee('Yeni bitiş')
            ->assertSee(now()->addMonthsNoOverflow(12)->format('d.m.Y'))->assertDontSee('Premium üyeliğiniz zaten aktif');
        $this->assertSame(0, PaymentOrder::query()->where('user_id', $driver->id)->count());
        $this->get(route('driver.premium.checkout', ['sure' => 7]))->assertOk()->assertSee('1 ay')->assertSee('900,00 ₺');

        $c = Volt::test('driver.premium.checkout', ['sure' => 12])->call('pay')->assertHasErrors(['accepted']);
        $this->assertSame(0, PaymentOrder::query()->where('user_id', $driver->id)->count(), 'Onaysız sipariş açılmaz');
        $c->set('accepted', true)->call('pay')->assertSee('deneme'); // sahte iyzico reddi hata kutusunda kodla birlikte görünür
        $this->assertSame(12, (int) PaymentOrder::query()->where('user_id', $driver->id)->latest('id')->value('subscription_months'));
    }

    public function test_checkout_asks_identity_once_when_profile_has_none(): void
    {
        Settings::set('payment_provider', 'iyzico');
        Settings::set('iyzico_api_key', 'sandbox-x');
        Settings::set('iyzico_secret_key', 'sandbox-y');
        Settings::set('iyzico_sandbox', '1');
        Http::fake(['sandbox-api.iyzipay.com/*' => Http::response(['status' => 'failure', 'errorMessage' => 'deneme'])]);
        $driver = $this->driver();
        $driver->driverProfile->update(['identity_number' => null]);
        $this->actingAs($driver->fresh());

        // Kimliği olmayan şoför: TC alanı görünür, boş ya da geçersiz TC ile sipariş açılmaz
        $this->get(route('driver.premium.checkout', ['sure' => 3]))->assertOk()
            ->assertSee('T.C. kimlik numarası')->assertSee('fatura için ister');
        $c = Volt::test('driver.premium.checkout', ['sure' => 3])->set('accepted', true)->call('pay')->assertHasErrors(['identityNumber']);
        $c->set('identityNumber', '12345678901')->call('pay')->assertHasErrors(['identityNumber']);
        $this->assertSame(0, PaymentOrder::query()->where('user_id', $driver->id)->count(), 'Kimliksiz sipariş açılmaz');
        $this->assertNull($driver->driverProfile->fresh()->identity_number);

        // Geçerli TC: profile yazılır, sipariş açılır
        $c->set('identityNumber', '100 000 001 46')->call('pay')->assertHasNoErrors();
        $this->assertSame('10000000146', $driver->driverProfile->fresh()->identity_number);
        $this->assertSame(1, PaymentOrder::query()->where('user_id', $driver->id)->count());

        // Kimliği olan şoför alanı görmez
        $this->get(route('driver.premium.checkout', ['sure' => 3]))->assertOk()->assertDontSee('fatura için ister');
    }

    public function test_active_premium_sees_extension_warning_and_new_end_date(): void
    {
        Settings::set('payment_provider', 'iyzico');
        Settings::set('iyzico_api_key', 'sandbox-x');
        Settings::set('iyzico_secret_key', 'sandbox-y');
        Settings::set('iyzico_sandbox', '1');
        $driver = $this->driver();
        $driver->driverProfile->update(['premium_until' => now()->addDays(20)]);
        $this->actingAs($driver->fresh());

        $this->get(route('driver.premium.index'))->assertOk()->assertSee('Üyeliği uzatın: süre seçin');
        $this->get(route('driver.premium.checkout', ['sure' => 3]))->assertOk()
            ->assertSee('Premium üyeliğiniz zaten aktif')
            ->assertSee('Bu ödeme süreyi kısaltmaz')
            ->assertSee(now()->addDays(20)->addMonthsNoOverflow(3)->format('d.m.Y'))
            ->assertSee('Süreyi uzat');
    }

    public function test_public_plan_card_mentions_long_term_discount(): void
    {
        $this->get(route('subscription'))->assertOk()->assertSee("%25'e varan indirim");
        Settings::set('premium_discount_3m', '0');
        Settings::set('premium_discount_6m', '0');
        Settings::set('premium_discount_12m', '0');
        $this->get(route('subscription'))->assertOk()->assertDontSee('varan indirim');
    }

    private function driver(): User
    {
        $user = User::factory()->driver()->create();
        $user->syncRoles(['driver']);
        $profile = DriverProfile::create(['user_id' => $user->id, 'kyc_status' => 'approved', 'kyc_verified_at' => now(), 'identity_number' => '10000000146', 'trial_started_at' => now()]);
        DriverVehicle::create(['driver_profile_id' => $profile->id, 'plate' => '06'.random_int(100, 999).'PL'.random_int(10, 99), 'brand' => 'Mercedes', 'model' => 'Actros', 'vehicle_type' => 'tir', 'is_active' => true]);

        return $user->fresh();
    }
}
