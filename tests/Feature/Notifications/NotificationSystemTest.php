<?php

namespace Tests\Feature\Notifications;

use App\Mail\PasswordResetMail;
use App\Mail\SystemNoticeMail;
use App\Mail\UserOtpMail;
use App\Models\CargoOwnerProfile;
use App\Models\DriverProfile;
use App\Models\DriverVehicle;
use App\Models\Load;
use App\Models\Subscription;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\LoadService;
use App\Services\NotificationService;
use App\Services\OfferService;
use App\Services\OtpService;
use App\Services\SubscriptionService;
use App\Support\Company;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Livewire\Volt\Volt;
use Tests\TestCase;

class NotificationSystemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
    }

    private static int $plateSeq = 100;

    private function driver(): User
    {
        $user = User::factory()->driver()->create();
        $profile = DriverProfile::create(['user_id' => $user->id, 'kyc_status' => 'approved']);
        DriverVehicle::create(['driver_profile_id' => $profile->id, 'plate' => '34TEST'.(self::$plateSeq++), 'vehicle_type' => 'tir', 'is_active' => true]);

        return $user->fresh();
    }

    private function owner(): User
    {
        $user = User::factory()->create(['current_role' => 'cargo_owner']);
        $user->syncRoles(['cargo_owner']);
        CargoOwnerProfile::create(['user_id' => $user->id, 'type' => 'individual', 'kyc_status' => 'approved']);

        return $user->fresh();
    }

    private function publish(User $owner, string $to = 'İzmir'): Load
    {
        return app(LoadService::class)->publish($owner->cargoOwnerProfile, [
            'pickup_location' => 'Ankara', 'delivery_location' => $to, 'pickup_date' => now()->addDay(), 'delivery_date' => now()->addDays(2),
            'vehicle_type' => 'tir', 'goods_type' => 'Paletli Yük', 'weight' => 12000, 'price' => 20000,
        ]);
    }

    public function test_notify_writes_in_app_record_and_sends_branded_mail(): void
    {
        $user = $this->driver();
        $n = app(NotificationService::class)->notify($user, 'Deneme başlığı', ['Satır 1', 'Satır 2'], 'https://navluniq.com/panel', 'Panele git', 'offer');

        $this->assertSame('sent', $n->fresh()->mail_status);
        $this->assertNotNull($n->fresh()->mail_sent_at);
        $this->assertSame(1, $user->unreadNotificationCount());
        Mail::assertSent(SystemNoticeMail::class, function (SystemNoticeMail $mail) use ($user) {
            $html = $mail->render();

            return $mail->hasTo($user->email)
                && $mail->recipientName === $user->first_name
                && str_contains($html, '/images/logo-dark.png')
                && str_contains($html, 'NavlunIQ Ekibi')
                && str_contains($html, 'Deneme başlığı')
                && str_contains($html, Company::get('name'));
        });
    }

    public function test_in_app_only_notification_skips_mail_and_deleted_accounts_get_no_mail(): void
    {
        $user = $this->driver();
        $n = app(NotificationService::class)->notify($user, 'Sessiz', ['x'], null, null, 'offer', sendMail: false);
        $this->assertSame('skipped', $n->mail_status);

        $user->forceFill(['email' => 'silinmis+9@hesap.kapatildi'])->save();
        $n2 = app(NotificationService::class)->notify($user->fresh(), 'Kapalı hesap', ['x']);
        $this->assertSame('skipped', $n2->fresh()->mail_status);
        Mail::assertNothingSent();
    }

    public function test_failed_mail_is_recorded_and_retried_by_command(): void
    {
        $user = $this->driver();
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SMTP bağlantısı reddedildi'));
        $n = app(NotificationService::class)->notify($user, 'Hata', ['x']);
        $this->assertSame('failed', $n->fresh()->mail_status);
        $this->assertSame(1, $n->fresh()->mail_attempts);
        $this->assertStringContainsString('SMTP', $n->fresh()->mail_error);

        $delivered = [];
        Mail::shouldReceive('to')->once()->andReturnUsing(function () use (&$delivered) {
            return new class($delivered)
            {
                public function __construct(private array &$box) {}

                public function send($mailable): void
                {
                    $this->box[] = $mailable;
                }
            };
        });
        UserNotification::query()->whereKey($n->id)->update(['updated_at' => now()->subMinutes(10)]);
        $this->artisan('notifications:retry-mail')->assertSuccessful();
        $this->assertSame('sent', $n->fresh()->mail_status);
        $this->assertSame(2, $n->fresh()->mail_attempts);
        $this->assertCount(1, $delivered);
        $this->assertInstanceOf(SystemNoticeMail::class, $delivered[0]);
    }

    public function test_notify_admins_targets_permission_holders_only(): void
    {
        $super = User::factory()->create(['current_role' => 'admin']);
        $super->syncRoles(['super_admin']);
        $kyc = User::factory()->create(['current_role' => 'admin']);
        $kyc->syncRoles(['kyc_validator']);
        $finance = User::factory()->create(['current_role' => 'admin']);
        $finance->syncRoles(['financial_officer']);
        $this->driver();

        $count = app(NotificationService::class)->notifyAdmins('verify kyc', 'Yeni belge', ['x'], null, null, 'admin');

        $this->assertSame(2, $count);
        $this->assertSame(1, $super->unreadNotificationCount());
        $this->assertSame(1, $kyc->unreadNotificationCount());
        $this->assertSame(0, $finance->unreadNotificationCount());
    }

    public function test_otp_and_password_reset_use_branded_mailables(): void
    {
        $user = $this->driver();
        $this->assertNull(app(OtpService::class)->send($user, 'Hesabınızı doğrulamak', 'test'));
        Mail::assertSent(UserOtpMail::class, fn (UserOtpMail $m) => $m->hasTo($user->email) && str_contains($m->render(), 'logo-dark.png') && str_contains($m->render(), $m->otpCode));

        Password::sendResetLink(['email' => $user->email]);
        Mail::assertSent(PasswordResetMail::class, fn (PasswordResetMail $m) => $m->hasTo($user->email) && str_contains($m->url, '/sifre-sifirla/') && str_contains($m->render(), 'Yeni şifre belirle'));
    }

    public function test_offer_reject_and_load_cancel_notify_drivers(): void
    {
        $owner = $this->owner();
        $driverA = $this->driver();
        $driverB = $this->driver();
        $load = $this->publish($owner);
        $offers = app(OfferService::class);
        $a = $offers->submit($driverA->driverProfile, $load, 19000);
        $b = $offers->submit($driverB->driverProfile, $load, 18500);
        $this->assertSame(2, $owner->unreadNotificationCount(), 'Her teklif yük sahibine bildirilir');

        $offers->reject($a, $owner->id);
        $this->assertTrue($driverA->userNotifications()->where('title', 'Teklifiniz kabul edilmedi')->exists());

        app(LoadService::class)->cancel($load->fresh(), $owner->cargoOwnerProfile, 'Yük başka yolla gitti');
        $this->assertTrue($driverB->userNotifications()->where('title', 'İlan iptal edildi')->exists());
        $this->assertFalse($driverA->userNotifications()->where('title', 'İlan iptal edildi')->exists(), 'Reddedilmiş teklif sahibi iptalden haberdar edilmez');
    }

    public function test_offer_accept_notifies_losing_drivers(): void
    {
        $owner = $this->owner();
        $driverA = $this->driver();
        $driverB = $this->driver();
        $load = $this->publish($owner, 'Bursa');
        $offers = app(OfferService::class);
        $a = $offers->submit($driverA->driverProfile, $load, 14000);
        $offers->submit($driverB->driverProfile, $load, 14500);

        $offers->accept($load->fresh(), $a, $owner->id);

        $this->assertTrue($driverA->userNotifications()->where('title', 'Teklifiniz kabul edildi')->exists());
        $this->assertTrue($driverB->userNotifications()->where('title', 'İlan başka bir şoföre verildi')->exists());
    }

    public function test_support_ticket_notifies_submitter_and_support_team(): void
    {
        $agent = User::factory()->create(['current_role' => 'admin']);
        $agent->syncRoles(['support_agent']);

        Volt::test('frontend.contact')
            ->set('name', 'Ziyaretçi Kişi')->set('email', 'ziyaretci@example.com')->set('phone', '05321234567')
            ->set('role', 'guest')->set('category', 'load')->set('message', 'İlanımı bulamıyorum, yardım eder misiniz lütfen?')
            ->call('submitTicket')->assertHasNoErrors();

        $this->assertSame(1, SupportTicket::query()->count());
        Mail::assertSent(SystemNoticeMail::class, fn (SystemNoticeMail $m) => $m->hasTo('ziyaretci@example.com') && $m->subjectLine === 'Destek talebiniz alındı');
        $this->assertTrue($agent->userNotifications()->where('title', 'Yeni destek talebi')->exists());
    }

    public function test_subscription_reminder_and_expiry_notify_once(): void
    {
        $driver = $this->driver();
        Subscription::create(['user_id' => $driver->id, 'plan_code' => 'premium_monthly', 'provider' => 'fake', 'status' => 'active', 'amount' => 900, 'currency' => 'TRY', 'interval' => 'monthly', 'current_period_starts_at' => now()->subDays(28), 'current_period_ends_at' => now()->addDays(2)]);

        $service = app(SubscriptionService::class);
        $this->assertSame(1, $service->remindExpiring(3));
        $this->assertSame(0, $service->remindExpiring(3), 'Aynı dönem için ikinci hatırlatma gönderilmez');

        Subscription::query()->update(['current_period_ends_at' => now()->subHour()]);
        $this->assertSame(1, $service->expireDue());
        $this->assertTrue($driver->userNotifications()->where('title', 'Premium üyeliğiniz sona erdi')->exists());
    }

    public function test_bell_and_notifications_page_mark_read(): void
    {
        $driver = $this->driver();
        $svc = app(NotificationService::class);
        $svc->notify($driver, 'Birinci', ['a'], route('driver.loads.index'), 'Git', 'offer');
        $svc->notify($driver, 'İkinci', ['b'], null, null, 'kyc');
        $this->actingAs($driver);

        Volt::test('notifications.bell')->assertSee('2')->assertSee('Birinci')->call('markAllRead');
        $this->assertSame(0, $driver->unreadNotificationCount());

        $third = $svc->notify($driver, 'Üçüncü', ['c'], route('driver.loads.index'), 'Git', 'offer');
        $this->get(route('driver.notifications.index'))->assertOk()->assertSee('Üçüncü')->assertSee('Bildirimler');
        Volt::test('driver.notifications.index')->call('open', $third->id)->assertRedirect(route('driver.loads.index'));
        $this->assertNotNull($third->fresh()->read_at);
    }

    public function test_admin_mail_tab_sends_test_mail(): void
    {
        $admin = User::factory()->create(['current_role' => 'admin']);
        $admin->syncRoles(['super_admin']);
        $this->actingAs($admin);

        Volt::test('admin.settings-center')->set('activeTab', 'mail')->assertSee('Gönderim altyapısı')
            ->set('testEmail', 'test@navluniq.com')->call('sendTestMail')->assertHasNoErrors();
        Mail::assertSent(SystemNoticeMail::class, fn (SystemNoticeMail $m) => $m->hasTo('test@navluniq.com') && $m->subjectLine === 'E-posta ayarları doğrulandı');
    }
}
