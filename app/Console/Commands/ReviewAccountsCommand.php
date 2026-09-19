<?php

namespace App\Console\Commands;

use App\Models\CargoOwnerProfile;
use App\Models\DriverProfile;
use App\Models\DriverVehicle;
use App\Models\User;
use App\Models\UserConsent;
use App\Services\LoadService;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Ödeme kuruluşu / mağaza incelemesi için iki hazır hesap (yük sahibi + şoför) oluşturur, belgelerini
 * onaylar, örnek bir ilan açar ve hesapları sabit doğrulama koduyla giriş yapacak şekilde tanımlar.
 * Yeniden çalıştırmak güvenlidir: mevcut hesaplar güncellenir.
 */
class ReviewAccountsCommand extends Command
{
    protected $signature = 'review:accounts
        {--owner-email=iyzico.yuksahibi@navluniq.com}
        {--driver-email=iyzico.sofor@navluniq.com}
        {--password=NavlunIQ-Test-2026!}
        {--code=482913 : 6 haneli sabit doğrulama kodu}
        {--remove : Hesapları ve sabit kodu kaldır}';

    protected $description = 'İnceleme (test) hesaplarını oluşturur ve sabit doğrulama kodunu tanımlar';

    public function handle(LoadService $loads): int
    {
        $ownerEmail = mb_strtolower(trim((string) $this->option('owner-email')));
        $driverEmail = mb_strtolower(trim((string) $this->option('driver-email')));
        $password = (string) $this->option('password');
        $code = (string) $this->option('code');

        if ($this->option('remove')) {
            // Hesaplar pasifleştirilip silinir (soft delete); ilan/teklif kayıtları bütünlük için kalır.
            User::query()->whereIn('email', [$ownerEmail, $driverEmail])->get()->each(function (User $u): void {
                $u->forceFill(['is_active' => false])->save();
                $u->delete();
            });
            Settings::set('review_login_emails', null);
            Settings::set('review_login_code', null);
            $this->info('İnceleme hesapları kapatıldı ve sabit kod kaldırıldı.');

            return self::SUCCESS;
        }

        if (! preg_match('/^\d{6}$/', $code)) {
            $this->error('Kod 6 haneli olmalıdır.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($ownerEmail, $driverEmail, $password, $loads): void {
            foreach (['cargo_owner', 'driver'] as $role) {
                Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
            }

            $owner = $this->upsertUser($ownerEmail, '05000000001', 'İnceleme', 'Yük Sahibi', $password, 'cargo_owner');
            $owner->syncRoles(['cargo_owner']);
            // Biçimsel olarak geçerli deneme TCKN'si; başka profilde kullanılmışsa boş bırakılır (alan benzersiz).
            $tc = '20000000428';
            if (CargoOwnerProfile::query()->where('tc_no', $tc)->where('user_id', '!=', $owner->id)->exists()) {
                $tc = null;
            }
            CargoOwnerProfile::updateOrCreate(['user_id' => $owner->id], [
                'type' => 'individual', 'tc_no' => $tc, 'nvi_verified' => false, 'gib_verified' => false,
                'kyc_status' => 'approved', 'kyc_submitted_at' => now(), 'kyc_verified_at' => now(),
                'kyc_notes' => 'İnceleme hesabı: belgeler komutla onaylandı.',
            ]);

            $driver = $this->upsertUser($driverEmail, '05000000002', 'İnceleme', 'Şoför', $password, 'driver');
            $driver->syncRoles(['driver']);
            $profile = DriverProfile::updateOrCreate(['user_id' => $driver->id], [
                'kyc_status' => 'approved', 'kyc_submitted_at' => now(), 'kyc_verified_at' => now(),
                'kyc_notes' => 'İnceleme hesabı: belgeler komutla onaylandı.',
            ]);
            DriverVehicle::firstOrCreate(['driver_profile_id' => $profile->id, 'plate' => '34INC001'], ['vehicle_type' => 'tir', 'is_active' => true]);

            // Örnek ilan: inceleme ekibi teklif → kabul → ödeme adımını görebilsin.
            $ownerProfile = $owner->cargoOwnerProfile()->first();
            if ($ownerProfile && ! $ownerProfile->loads()->where('status', 'active_seeking')->exists()) {
                $loads->publish($ownerProfile, [
                    'pickup_location' => 'İstanbul', 'delivery_location' => 'Ankara',
                    'pickup_date' => now()->addDays(2), 'delivery_date' => now()->addDays(3),
                    'vehicle_type' => 'tir', 'goods_type' => 'Paletli Yük', 'weight' => 12000, 'price' => 15000,
                ]);
            }
        });

        $existing = array_filter(array_map('trim', explode(',', Settings::string('review_login_emails'))));
        $merged = array_values(array_unique(array_merge($existing, [$ownerEmail, $driverEmail])));
        Settings::set('review_login_emails', implode(', ', $merged));
        Settings::set('review_login_code', $code);

        $this->info('İnceleme hesapları hazır (e-posta gönderilmez; sabit kodla giriş).');
        $this->line('  Giriş sayfası : '.rtrim((string) config('app.url'), '/').'/giris');
        $this->line("  Yük sahibi    : {$ownerEmail} / {$password} / kod {$code}");
        $this->line("  Şoför         : {$driverEmail} / {$password} / kod {$code}");
        $this->line('  Kaldırmak için: php artisan review:accounts --remove');

        return self::SUCCESS;
    }

    private function upsertUser(string $email, string $phone, string $first, string $last, string $password, string $role): User
    {
        $user = User::query()->withTrashed()->where('email', $email)->first();
        if ($user?->trashed()) {
            $user->restore();
        }

        $attributes = [
            'first_name' => $first, 'last_name' => $last, 'phone' => $phone,
            'password' => Hash::make($password), 'current_role' => $role, 'is_active' => true,
            'email_verified_at' => now(), 'phone_verified_at' => now(), 'banned_at' => null, 'ban_reason' => null,
        ];

        if ($user) {
            $user->forceFill($attributes)->save();
        } else {
            $user = User::create($attributes + ['email' => $email]);
        }

        foreach (['terms', 'kvkk'] as $consent) {
            UserConsent::firstOrCreate(['user_id' => $user->id, 'consent_type' => $consent], [
                'document_version' => (string) config('company.legal_document_version', '1.0'), 'granted' => true,
                'recorded_at' => now(), 'ip_address' => '127.0.0.1', 'user_agent' => 'artisan review:accounts',
            ]);
        }

        return $user;
    }
}
