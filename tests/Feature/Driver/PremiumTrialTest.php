<?php

namespace Tests\Feature\Driver;

use App\Models\CmsContent;
use App\Models\DriverProfile;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\KycService;
use App\Services\SubscriptionService;
use App\Support\Company;
use App\Support\Settings;
use Database\Seeders\CmsContractSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Ücretsiz premium deneme: belgeleri onaylanan her şoföre bir kez, kart gerekmez, süre sonunda ücret alınmaz.
 * Tanıtım metinleri (üyelik sayfası, ana sayfa, SSS) kuralla tutarlı kalır.
 */
class PremiumTrialTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
        $this->admin = User::factory()->create(['current_role' => 'admin']);
        $this->admin->syncRoles(['super_admin']);
    }

    public function test_trial_starts_once_when_driver_documents_are_approved(): void
    {
        $driver = $this->driver('pending');

        app(KycService::class)->approveProfile($driver, 'driver', $this->admin);

        $profile = $driver->driverProfile->fresh();
        $this->assertTrue($profile->isPremium());
        $this->assertNotNull($profile->trial_started_at);
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $profile->premium_until->timestamp, 5);
        $this->assertSame(1, Subscription::query()->where('user_id', $driver->id)->where('plan_code', SubscriptionService::PLAN_PREMIUM_TRIAL)->count());
        $this->assertTrue(UserNotification::query()->where('user_id', $driver->id)->where('title', 'like', '%deneme süreniz başladı%')->exists());

        // Onay geri alınıp tekrar verilse de ikinci deneme yok; düğmeyle de başlatılamaz.
        app(KycService::class)->resetProfile($driver, 'driver', $this->admin);
        app(KycService::class)->approveProfile($driver->fresh(), 'driver', $this->admin);
        $this->assertSame(1, Subscription::query()->where('user_id', $driver->id)->count());
        $this->assertFalse(app(SubscriptionService::class)->trialEligible($driver->driverProfile->fresh()));
        $this->assertNull(app(SubscriptionService::class)->startTrial($driver->fresh()));
    }

    public function test_setting_zero_disables_the_trial_and_hides_the_promise(): void
    {
        Settings::set('premium_trial_days', '0');
        $driver = $this->driver('pending');

        app(KycService::class)->approveProfile($driver, 'driver', $this->admin);

        $this->assertFalse($driver->driverProfile->fresh()->isPremium());
        $this->assertNull($driver->driverProfile->fresh()->trial_started_at);
        $this->get(route('subscription'))->assertOk()->assertDontSee('gün ücretsiz');
    }

    public function test_approved_driver_can_start_the_trial_from_the_premium_page_and_sees_its_status(): void
    {
        $driver = $this->driver('approved');
        $this->actingAs($driver);

        $this->get(route('driver.premium.index'))->assertOk()->assertSee('7 gün ücretsiz deneyin')->assertSee('7 günlük denemeyi başlat');

        Volt::test('driver.premium.index')->call('startTrial')->assertRedirect(route('driver.loads.index', ['tab' => 'external']));

        $profile = $driver->driverProfile->fresh();
        $this->assertTrue($profile->isPremium());
        $this->assertNotNull(app(SubscriptionService::class)->activeTrialEndsAt($driver));
        $this->actingAs($driver->fresh()); // actingAs nesnesi ilişkiyi önbellekler (CLAUDE.md §9)
        $this->get(route('driver.premium.index'))->assertOk()->assertSee('ücretsiz deneme')->assertDontSee('7 günlük denemeyi başlat');
        $this->get(route('driver.dashboard'))->assertOk()->assertSee('Ücretsiz deneme sürüyor');
    }

    public function test_trial_end_sends_no_charge_message_and_drops_to_standard(): void
    {
        $driver = $this->driver('approved');
        app(SubscriptionService::class)->startTrial($driver);
        Subscription::query()->where('user_id', $driver->id)->update(['current_period_ends_at' => now()->subMinute()]);
        $driver->driverProfile->update(['premium_until' => now()->subMinute()]);

        $this->assertSame(1, app(SubscriptionService::class)->expireDue());

        $this->assertFalse($driver->driverProfile->fresh()->isPremium());
        $this->assertTrue(UserNotification::query()->where('user_id', $driver->id)->where('title', 'Ücretsiz deneme süreniz bitti')->exists());
        $this->assertFalse(app(SubscriptionService::class)->trialEligible($driver->driverProfile->fresh()), 'Deneme bir kez verilir');
    }

    public function test_trial_reminder_speaks_of_the_trial_not_of_extending(): void
    {
        $driver = $this->driver('approved');
        app(SubscriptionService::class)->startTrial($driver);
        Subscription::query()->where('user_id', $driver->id)->update(['current_period_ends_at' => now()->addDays(2)]);

        $this->assertSame(1, app(SubscriptionService::class)->remindExpiring(3));

        $note = UserNotification::query()->where('user_id', $driver->id)->where('title', 'Premium üyeliğiniz yakında sona eriyor')->first();
        $this->assertNotNull($note);
        $this->assertStringContainsString('deneme', mb_strtolower(json_encode($note->toArray(), JSON_UNESCAPED_UNICODE)));
    }

    public function test_public_pages_promise_the_trial_and_state_the_access_rules(): void
    {
        $this->get(route('subscription'))->assertOk()
            ->assertSee('7 gün ücretsiz dene')
            ->assertSee('Kart gerekmez')
            ->assertSee('Gruplardan derlenen ilanlar yalnız Premium')
            ->assertSee('Yeni ilanlar panelinize düşer, bildirim gelmez')
            ->assertDontSee('Tüm ilanları görün')
            ->assertSee('Günde ≈ 30 ₺');
        $this->get(route('home'))->assertOk()->assertSee('7 gün ücretsiz dene')->assertDontSee('Tüm ilanları görün');
    }

    public function test_contract_text_carries_the_trial_days_token(): void
    {
        $this->seed(CmsContractSeeder::class);
        $html = Company::fillTokens(CmsContent::query()->where('key', 'contract_distance_sale')->value('value') ?? '');
        $this->assertStringContainsString('7 gün</strong> ücretsiz deneme', $html);
        $terms = Company::fillTokens(CmsContent::query()->where('key', 'contract_cancellation')->value('value') ?? '');
        $this->assertStringContainsString('7 günlük</strong> ücretsiz deneme', $terms);
        $this->assertStringNotContainsString('{{PREMIUM_TRIAL_DAYS}}', $html);
    }

    private function driver(string $kyc): User
    {
        $user = User::factory()->driver()->create();
        $user->syncRoles(['driver']);
        DriverProfile::create(['user_id' => $user->id, 'kyc_status' => $kyc, 'kyc_verified_at' => $kyc === 'approved' ? now() : null]);

        return $user->fresh();
    }
}
