<?php

namespace Tests\Feature\Security;

use App\Mail\SystemNoticeMail;
use App\Mail\UserOtpMail;
use App\Models\Backup;
use App\Models\CargoOwnerProfile;
use App\Models\DriverLocation;
use App\Models\DriverProfile;
use App\Models\DriverVehicle;
use App\Models\KycDocument;
use App\Models\User;
use App\Models\UserConsent;
use App\Models\UserNotification;
use App\Services\AccountService;
use App\Services\DriverLocationService;
use App\Services\OtpService;
use App\Support\Phone;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Paket B: güven ve KVKK düzeltmeleri (sabit kod, kayıt/giriş sınırları, oturum, e-posta değişimi, hesap silme, yedek, sözleşme sürümü). */
class PackageBTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
        RateLimiter::clear('register:'.hash('sha256', '127.0.0.1'));
    }

    private function admin(string $role = 'super_admin'): User
    {
        $admin = User::factory()->create(['current_role' => 'admin', 'email' => 'yonetici-'.$role.'@example.com']);
        $admin->syncRoles([$role]);

        return $admin->fresh();
    }

    public function test_fixed_review_code_never_applies_to_admins_on_live_and_expires(): void
    {
        $driver = User::factory()->driver()->create(['email' => 'inceleme@example.com']);
        $admin = $this->admin();
        Settings::set('review_login_emails', 'inceleme@example.com, '.$admin->email);
        Settings::set('review_login_code', '246810');

        $this->assertTrue(OtpService::isReviewAccount($driver));
        $this->assertFalse(OtpService::isReviewAccount($admin), 'yönetici canlıda sabit kodla giremez');

        Settings::set('deneme_mode', 1);
        $this->assertTrue(OtpService::isReviewAccount($admin), 'yalıtılmış deneme kopyasında girebilir');
        Settings::set('deneme_mode', 0);

        Settings::set('review_login_until', now()->subMinute()->toDateTimeString());
        $this->assertFalse(OtpService::isReviewAccount($driver), 'süresi dolan sabit kod kapanır');
    }

    public function test_otp_attempts_are_counted_per_user_not_per_ip(): void
    {
        $user = User::factory()->create();
        $otp = app(OtpService::class);
        $user->forceFill(['otp_code' => Hash::make('123456'), 'otp_expires_at' => now()->addMinutes(5)])->save();

        for ($i = 0; $i < 4; $i++) {
            $this->assertSame('Girdiğiniz doğrulama kodu hatalı.', $otp->verify($user->fresh(), '000000', 'login'));
        }
        $this->assertStringContainsString('Çok fazla hatalı', (string) $otp->verify($user->fresh(), '000000', 'login'));
        $this->assertNull($user->fresh()->otp_code, 'beşinci yanlış denemede kod geçersizleşir');
        $this->assertNotNull($otp->verify($user->fresh(), '123456', 'login'), 'doğru kod bile artık kabul edilmez');
    }

    public function test_registration_form_is_not_a_password_oracle(): void
    {
        $existing = User::factory()->create(['email' => 'kayitli@example.com', 'phone' => '05321112233']);
        $form = fn () => Volt::test('frontend.register-driver')->set([
            'firstName' => 'Deneme', 'lastName' => 'Kullanıcı', 'email' => 'kayitli@example.com', 'phone' => '0532 111 22 33',
            'password' => 'yanlis-sifre-123456', 'password_confirmation' => 'yanlis-sifre-123456', 'plate' => '34TST001', 'vehicleType' => 'tir', 'acceptTerms' => true,
        ]);
        for ($i = 0; $i < 5; $i++) {
            $form()->call('registerDriver')->assertHasErrors('password');
        }
        $c = $form()->call('registerDriver');
        $this->assertStringContainsString('Çok fazla deneme', implode(' ', $c->errors()->get('password')));
        $this->assertFalse(DriverProfile::query()->where('user_id', $existing->id)->exists());
    }

    public function test_role_is_added_to_existing_account_only_after_otp(): void
    {
        $existing = User::factory()->create(['email' => 'yuk@example.com', 'phone' => '05321112244', 'password' => Hash::make('UzunSifre-123456')]);
        CargoOwnerProfile::create(['user_id' => $existing->id, 'type' => 'individual']);

        $c = Volt::test('frontend.register-driver')->set([
            'firstName' => 'Deneme', 'lastName' => 'Kullanıcı', 'email' => 'yuk@example.com', 'phone' => '0532 111 22 44',
            'password' => 'UzunSifre-123456', 'password_confirmation' => 'UzunSifre-123456', 'plate' => '34TST002', 'vehicleType' => 'tir', 'acceptTerms' => true,
        ])->call('registerDriver')->assertHasNoErrors()->assertSet('step', 2);

        $this->assertFalse(DriverProfile::query()->where('user_id', $existing->id)->exists(), 'kod doğrulanmadan profil açılmaz');
        $existing->forceFill(['otp_code' => Hash::make('123456'), 'otp_expires_at' => now()->addMinutes(5)])->save();
        $c->set('otp', '123456')->call('verifyOtp')->assertHasNoErrors();
        $this->assertTrue(DriverProfile::query()->where('user_id', $existing->id)->exists());
        $this->assertTrue(DriverVehicle::query()->where('plate', '34TST002')->exists());
    }

    public function test_password_change_ends_other_sessions_and_notifies(): void
    {
        $user = User::factory()->driver()->create();
        DriverProfile::create(['user_id' => $user->id, 'kyc_status' => 'approved']);
        $this->actingAs($user);
        DB::table('sessions')->insert(['id' => 'baska-cihaz', 'user_id' => $user->id, 'ip_address' => '10.0.0.2', 'user_agent' => 'test', 'payload' => '', 'last_activity' => time()]);

        Volt::test('driver.profile.index')->set(['current_password' => 'password', 'new_password' => 'YeniSifre-123456', 'new_password_confirmation' => 'YeniSifre-123456'])
            ->call('updatePassword')->assertHasNoErrors();

        $this->assertDatabaseMissing('sessions', ['id' => 'baska-cihaz']);
        $this->assertTrue(Hash::check('YeniSifre-123456', $user->fresh()->password));
        $this->assertTrue(UserNotification::query()->where('user_id', $user->id)->where('title', 'Şifreniz değiştirildi')->exists());
    }

    public function test_email_change_requires_password_and_code_sent_to_new_address(): void
    {
        $user = User::factory()->create(['email' => 'eski@example.com']);
        CargoOwnerProfile::create(['user_id' => $user->id, 'type' => 'individual']);
        $this->actingAs($user);

        Volt::test('cargo-owner.profile.index')->set('email', 'yeni@example.com')->call('updateProfile')->assertHasErrors('contact_password');
        $this->assertSame('eski@example.com', $user->fresh()->email);

        $c = Volt::test('cargo-owner.profile.index')->set(['email' => 'yeni@example.com', 'contact_password' => 'password'])->call('updateProfile')->assertHasNoErrors();
        $user->refresh();
        $this->assertSame('eski@example.com', $user->email, 'kod doğrulanmadan adres değişmez');
        $this->assertSame('yeni@example.com', $user->pending_email);
        Mail::assertSent(UserOtpMail::class, fn ($m) => $m->hasTo('yeni@example.com'));

        $user->forceFill(['otp_code' => Hash::make('654321'), 'otp_expires_at' => now()->addMinutes(5)])->save();
        $c->set('email_change_otp', '111111')->call('confirmEmailChange')->assertHasErrors('email_change_otp');
        $c->set('email_change_otp', '654321')->call('confirmEmailChange')->assertHasNoErrors();
        $user->refresh();
        $this->assertSame('yeni@example.com', $user->email);
        $this->assertNull($user->pending_email);
        Mail::assertSent(SystemNoticeMail::class, fn ($m) => $m->hasTo('eski@example.com'));
    }

    public function test_phone_change_requires_password(): void
    {
        $user = User::factory()->create(['phone' => '05321112255']);
        CargoOwnerProfile::create(['user_id' => $user->id, 'type' => 'individual']);
        $this->actingAs($user);
        Volt::test('cargo-owner.profile.index')->set('phone', '0533 111 22 66')->call('updateProfile')->assertHasErrors('contact_password');
        $this->assertSame('05321112255', $user->fresh()->phone);
        Volt::test('cargo-owner.profile.index')->set(['phone' => '0533 111 22 66', 'contact_password' => 'password'])->call('updateProfile')->assertHasNoErrors();
        $this->assertSame(Phone::normalize('0533 111 22 66'), $user->fresh()->phone);
    }

    public function test_account_deletion_removes_identity_data_files_and_locations(): void
    {
        Storage::fake('kyc_private');
        Storage::fake('private');
        $user = User::factory()->driver()->create();
        $driver = DriverProfile::create(['user_id' => $user->id, 'kyc_status' => 'approved', 'ocr_data' => ['tc' => '12345678901']]);
        $owner = CargoOwnerProfile::create(['user_id' => $user->id, 'type' => 'individual', 'tc_no' => '12345678901', 'birth_year' => 1980]);
        Storage::disk('kyc_private')->put('users/'.$user->id.'/ehliyet.jpg', 'x');
        $doc = KycDocument::create(['user_id' => $user->id, 'document_type' => 'driver_license', 'storage_disk' => 'kyc_private', 'storage_path' => 'users/'.$user->id.'/ehliyet.jpg', 'sha256' => 'a', 'status' => 'approved']);
        $vehicle = DriverVehicle::create(['driver_profile_id' => $driver->id, 'plate' => '34SIL001', 'vehicle_type' => 'tir', 'is_active' => true]);
        DriverLocation::create(['driver_profile_id' => $driver->id, 'latitude' => 39.9, 'longitude' => 32.8, 'recorded_at' => now()]);

        app(AccountService::class)->deleteAccount($user, 'test');

        Storage::disk('kyc_private')->assertMissing('users/'.$user->id.'/ehliyet.jpg');
        $this->assertNull(KycDocument::withTrashed()->find($doc->id));
        $this->assertNull($owner->fresh()->tc_no);
        $this->assertNull($driver->fresh()->ocr_data);
        $this->assertSame(0, DriverLocation::query()->where('driver_profile_id', $driver->id)->count());
        $this->assertSame('SILINDI-'.$vehicle->id, DriverVehicle::withTrashed()->find($vehicle->id)->plate);
        $this->assertStringContainsString('@hesap.kapatildi', User::withTrashed()->find($user->id)->email);
    }

    public function test_data_export_contains_account_and_consents(): void
    {
        $user = User::factory()->create(['first_name' => 'Deneme']);
        UserConsent::recordRegistration($user);
        $export = app(AccountService::class)->export($user);
        $this->assertSame('Deneme', $export['hesap']['ad']);
        $this->assertCount(2, $export['onaylar']);
    }

    public function test_backup_download_is_super_admin_only(): void
    {
        $backup = Backup::create(['filename' => 'navluniq-test.zip', 'backup_type' => 'full', 'storage_disk' => 'local', 'storage_path' => 'backups/navluniq-test.zip', 'status' => 'completed']);
        $validator = $this->admin('kyc_validator');
        $validator->givePermissionTo('manage settings');
        $this->actingAs($validator->fresh())->get(route('admin.backups.download', $backup))->assertForbidden();
        // Süper yönetici yetki denetimini geçer; dosya olmadığı için 404 (yetki 403 değil).
        $this->actingAs($this->admin())->get(route('admin.backups.download', $backup))->assertNotFound();
    }

    public function test_legal_version_bump_requires_reconsent_in_panel(): void
    {
        $user = User::factory()->driver()->create();
        DriverProfile::create(['user_id' => $user->id, 'kyc_status' => 'approved']);
        Settings::set('legal_document_version', '1.0');
        UserConsent::recordRegistration($user);
        $this->assertTrue(UserConsent::upToDate($user));

        Settings::set('legal_document_version', '1.1');
        $this->assertFalse(UserConsent::upToDate($user->fresh()));
        $this->actingAs($user->fresh())->get(route('driver.dashboard'))->assertOk()->assertSee('Sözleşmeler güncellendi');

        Volt::test('reconsent-modal')->call('approve')->assertHasErrors('accept');
        Volt::test('reconsent-modal')->set('accept', true)->call('approve')->assertHasNoErrors();
        $this->assertTrue(UserConsent::upToDate($user->fresh()));
        $this->actingAs($user->fresh())->get(route('driver.dashboard'))->assertOk()->assertDontSee('Sözleşmeler güncellendi');
    }

    public function test_admin_panel_switch_profile_is_flagged_and_not_notified(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.panel-switch', 'driver'))->assertRedirect(route('driver.dashboard'));
        $profile = DriverProfile::query()->where('user_id', $admin->id)->first();
        $this->assertTrue($profile->is_staff_view);
        $this->assertSame('YONETIM'.$admin->id, $profile->vehicles()->first()->plate);
        $this->actingAs($admin->fresh())->get(route('driver.dashboard'))->assertOk()->assertSee('Yönetici görünümü');
    }

    public function test_location_is_recorded_only_during_transit(): void
    {
        $user = User::factory()->driver()->create();
        $driver = DriverProfile::create(['user_id' => $user->id, 'kyc_status' => 'approved']);
        $this->assertNull(app(DriverLocationService::class)->record($driver, 39.9, 32.8));
        $this->assertSame(0, DriverLocation::query()->count());

        DriverLocation::create(['driver_profile_id' => $driver->id, 'latitude' => 39.9, 'longitude' => 32.8, 'recorded_at' => now()->subDays(100)]);
        $this->assertSame(1, app(DriverLocationService::class)->purgeOld(90));
    }

    public function test_premium_lead_text_follows_setting(): void
    {
        Settings::set('scraper_free_delay_minutes', '45');
        $this->get(route('subscription'))->assertOk()->assertSee('45 dakika')->assertDontSee('20 dakika');
    }

    public function test_otp_mail_subject_has_no_code(): void
    {
        $mail = new UserOtpMail('123456', 'Giriş yapmak', 'Deneme');
        $this->assertStringNotContainsString('123456', $mail->envelope()->subject);
    }
}
