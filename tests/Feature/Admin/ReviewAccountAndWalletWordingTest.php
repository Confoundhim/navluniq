<?php

namespace Tests\Feature\Admin;

use App\Console\Commands\RefreshFaqCommand;
use App\Mail\UserOtpMail;
use App\Models\DriverProfile;
use App\Models\Faq;
use App\Models\User;
use App\Services\OtpService;
use App\Support\Settings;
use Database\Seeders\FaqSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ReviewAccountAndWalletWordingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
    }

    public function test_review_accounts_log_in_with_static_code_without_mail(): void
    {
        $reviewer = User::factory()->driver()->create(['email' => 'iyzico-test@navluniq.com', 'password' => bcrypt('Sifre12345678!')]);
        $other = User::factory()->driver()->create();
        Settings::set('review_login_emails', 'iyzico-test@navluniq.com, ikinci@example.com');
        Settings::set('review_login_code', '246810');

        $otp = app(OtpService::class);
        $this->assertNull($otp->send($reviewer, 'Giriş', 'login'));
        Mail::assertNotSent(UserOtpMail::class);
        $this->assertNotNull($otp->verify($reviewer->fresh(), '111111', 'login'), 'Yanlış kod reddedilmeli');
        $this->assertNull($otp->verify($reviewer->fresh(), '246810', 'login'));

        // Listede olmayan kullanıcı normal akışta: e-posta gider, sabit kod geçersiz.
        $this->assertNull($otp->send($other, 'Giriş', 'login'));
        Mail::assertSent(UserOtpMail::class, fn (UserOtpMail $m) => $m->hasTo($other->email));
        $this->assertNotNull($otp->verify($other->fresh(), '246810', 'login'));

        // Kod tanımsızsa özellik kapalı.
        Settings::set('review_login_code', null);
        $this->assertFalse(OtpService::isReviewAccount($reviewer));
    }

    public function test_wallet_wording_removed_and_old_url_redirects(): void
    {
        $driver = User::factory()->driver()->create();
        DriverProfile::create(['user_id' => $driver->id, 'kyc_status' => 'approved']);
        $this->actingAs($driver);

        $this->get('/panel/sofor/cuzdan')->assertRedirect(route('driver.wallet.index'));
        $this->get(route('driver.wallet.index'))->assertOk()->assertSee('Ödemelerim')->assertDontSee('Cüzdan');
        $this->get('/panel/sofor/dashboard')->assertOk()->assertDontSee('Cüzdan');

        $this->get('/')->assertOk()->assertDontSee('cüzdan')->assertDontSee('Cüzdan');
        $this->get('/abonelik')->assertOk()->assertDontSee('Cüzdan');
    }

    public function test_faq_refresh_reseeds_only_stale_texts(): void
    {
        $this->seed(FaqSeeder::class);
        $this->assertFalse(RefreshFaqCommand::isStale());
        $this->assertSame(0, Faq::query()->where('answer', 'like', '%cüzdan%')->count());

        $faq = Faq::query()->first();
        $faq->update(['answer' => 'Ödemeler güvenli havuz hesabında tutulur; cüzdan ekranından izlenir.']);
        $this->assertTrue(RefreshFaqCommand::isStale());

        $this->artisan('faq:refresh', ['--if-stale' => true])->assertSuccessful();
        $this->assertFalse(RefreshFaqCommand::isStale());
    }
}
