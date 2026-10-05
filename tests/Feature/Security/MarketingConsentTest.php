<?php

namespace Tests\Feature\Security;

use App\Mail\SystemNoticeMail;
use App\Models\CargoOwnerProfile;
use App\Models\User;
use App\Models\UserConsent;
use App\Services\MarketingConsentService;
use App\Services\NotificationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Ticari elektronik ileti onayı (ETK/İYS): ayrı ve işaretlenmemiş kutu, geri alınabilir, pazarlama iletisinde tek tıklık çıkış. */
class MarketingConsentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
    }

    public function test_registration_records_marketing_consent_only_when_the_separate_box_is_ticked(): void
    {
        $c = Volt::test('frontend.register-cargo-owner')->set([
            'type' => 'individual', 'firstName' => 'Deneme', 'lastName' => 'Kullanıcı', 'email' => 'yeni@example.com', 'phone' => '0532 111 22 33',
            'password' => 'UzunSifre-123456', 'password_confirmation' => 'UzunSifre-123456', 'acceptTerms' => true,
        ])->assertSet('acceptMarketing', false)->set('acceptMarketing', true)->call('register')->assertHasNoErrors();
        $user = User::query()->where('email', 'yeni@example.com')->firstOrFail();
        $this->assertNull($user->marketing_consent_at, 'kod doğrulanmadan onay yazılmaz');
        $user->forceFill(['otp_code' => Hash::make('123456'), 'otp_expires_at' => now()->addMinutes(5)])->save();
        $c->set('otp', '123456')->call('verifyOtp')->assertHasNoErrors();
        $this->assertTrue(MarketingConsentService::hasConsent($user->fresh()));
        $this->assertTrue(UserConsent::query()->where('user_id', $user->id)->where('consent_type', 'marketing')->where('granted', true)->exists());
    }

    public function test_profile_toggle_revokes_and_signed_link_unsubscribes_without_login(): void
    {
        $user = User::factory()->create();
        CargoOwnerProfile::create(['user_id' => $user->id, 'type' => 'individual']);
        app(MarketingConsentService::class)->grant($user);
        $this->assertTrue(MarketingConsentService::hasConsent($user->fresh()));

        $this->actingAs($user);
        Volt::test('cargo-owner.profile.index')->assertSet('marketing_consent', true)->set('marketing_consent', false)->call('updateProfile')->assertHasNoErrors();
        $this->assertFalse(MarketingConsentService::hasConsent($user->fresh()));
        $this->assertTrue(UserConsent::query()->where('user_id', $user->id)->where('consent_type', 'marketing')->where('granted', false)->exists());

        app(MarketingConsentService::class)->grant($user->fresh());
        auth()->logout();
        $url = MarketingConsentService::unsubscribeUrl($user->fresh());
        $this->get(str_replace('signature=', 'signature=x', $url))->assertForbidden();
        $this->get($url)->assertOk()->assertSee('Kampanya e-postaları kapatıldı');
        $this->assertFalse(MarketingConsentService::hasConsent($user->fresh()));
    }

    public function test_marketing_mail_requires_consent_and_carries_unsubscribe_link(): void
    {
        $without = User::factory()->create();
        $with = User::factory()->create();
        app(MarketingConsentService::class)->grant($with);

        app(NotificationService::class)->notify($without, 'Kampanya', ['Bu ay premium indirimli.'], null, null, 'marketing');
        app(NotificationService::class)->notify($with, 'Kampanya', ['Bu ay premium indirimli.'], null, null, 'marketing');
        Mail::assertNotSent(SystemNoticeMail::class, fn ($m) => $m->hasTo($without->email));
        Mail::assertSent(SystemNoticeMail::class, fn ($m) => $m->hasTo($with->email) && str_contains((string) $m->unsubscribeUrl, '/e-posta/abonelikten-cik/'));

        // İşlem bildirimi onaydan bağımsız gider ve çıkış bağlantısı taşımaz
        app(NotificationService::class)->notify($without, 'Teklifiniz kabul edildi', ['…'], null, null, 'offer');
        Mail::assertSent(SystemNoticeMail::class, fn ($m) => $m->hasTo($without->email) && $m->unsubscribeUrl === null);
    }
}
