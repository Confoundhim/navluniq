<?php

namespace Database\Seeders;

use App\Models\CargoOwnerProfile;
use App\Models\DriverProfile;
use App\Models\DriverVehicle;
use App\Models\Load;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Yerel geliştirme ve ekran doğrulaması için deneme hesapları ve örnek ilanlar.
 * Yalnız APP_ENV=local iken çalışır; canlıda hiçbir şey yapmaz. Tekrar çalıştırmak güvenlidir.
 *
 *   php artisan db:seed --class=LocalDemoSeeder
 *
 * Hesaplar (şifre: Sifre12345!, tek kullanımlık kod: 123456):
 *   admin@test.local (süper yönetici), sofor@test.local (premium, belgeleri onaylı, TIR tenteli 13.60),
 *   yuk@test.local (yük sahibi, belgeleri onaylı).
 */
class LocalDemoSeeder extends Seeder
{
    public const PASSWORD = 'Sifre12345!';

    public const OTP = '123456';

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            $this->command?->warn('LocalDemoSeeder yalnız yerel ortamda çalışır; atlandı.');

            return;
        }
        $this->call(RolesAndPermissionsSeeder::class);

        $admin = $this->user('admin@test.local', 'Ayşe', 'Yönetici', 'admin', '05550000001');
        $admin->syncRoles(['super_admin']);

        $driverUser = $this->user('sofor@test.local', 'Mehmet', 'Şoför', 'driver', '05550000002');
        $driver = DriverProfile::withTrashed()->firstOrNew(['user_id' => $driverUser->id]);
        $driver->forceFill(['kyc_status' => 'approved', 'kyc_verified_at' => now(), 'premium_until' => now()->addYear(), 'deleted_at' => null])->save();
        DriverVehicle::query()->updateOrCreate(
            ['driver_profile_id' => $driver->id, 'plate' => '34TST001'],
            ['vehicle_type' => 'tir', 'body_type' => 'tenteli', 'trailer_length' => 'uzun', 'is_active' => true]
        );

        $ownerUser = $this->user('yuk@test.local', 'Fatma', 'Yük Sahibi', 'cargo_owner', '05550000003');
        $owner = CargoOwnerProfile::query()->firstOrNew(['user_id' => $ownerUser->id]);
        $owner->forceFill(['type' => 'individual', 'kyc_status' => 'approved'])->save();

        // Giriş kodunu e-posta beklemeden sabitle (yalnız bu hesaplar için)
        Settings::set('review_login_emails', 'admin@test.local, sofor@test.local, yuk@test.local');
        Settings::set('review_login_code', self::OTP);

        // Örnek sistem ilanı ve dış kaynak ilanı (uydurma veri)
        if (Load::query()->count() === 0) {
            Load::create([
                'cargo_owner_profile_id' => $owner->id, 'source_type' => 'internal', 'visibility' => 'public',
                'pickup_location' => 'Ankara Ostim', 'pickup_province_code' => 6, 'delivery_location' => 'İzmir Aliağa', 'delivery_province_code' => 35,
                'pickup_date' => now()->addDays(2), 'vehicle_type' => 'tir', 'body_types' => ['tenteli', 'kapali'], 'load_kind' => 'komple',
                'goods_type' => 'Paletli yük', 'weight' => 24000, 'price' => 42000, 'status' => Load::STATUS_ACTIVE, 'escrow_status' => Load::ESCROW_PENDING, 'published_at' => now(),
            ]);
        }
        $scraper = Scraper::query()->firstOrCreate(['source_identifier' => 'notif:deneme-grubu'], ['name' => 'Deneme grubu', 'type' => 'notification', 'is_active' => true]);
        if (ScrapedLoad::query()->count() === 0) {
            ScrapedLoad::create([
                'scraper_id' => $scraper->id, 'content_hash' => 'demo-1', 'raw_message' => 'Deneme ilanı', 'sender_phone' => '05550000099',
                'pickup_location' => 'Bursa Karacabey', 'pickup_province_code' => 16, 'pickup_district' => 'Karacabey', 'delivery_location' => 'Konya', 'delivery_province_code' => 42,
                'vehicle_type' => 'tir', 'vehicle_type_source' => 'keyword', 'body_types' => ['tenteli', 'uzun_dorse'], 'load_kind' => 'komple', 'weight' => 25000, 'price' => 30000,
                'status' => 'parsed_success', 'visibility' => 'public', 'published_at' => now(), 'retention_expires_at' => now()->addDays(14),
            ]);
        }

        $this->command?->info('Deneme hesapları hazır: admin@test.local, sofor@test.local, yuk@test.local · şifre '.self::PASSWORD.' · kod '.self::OTP);
    }

    private function user(string $email, string $first, string $last, string $role, string $phone): User
    {
        $user = User::query()->withTrashed()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'first_name' => $first, 'last_name' => $last, 'phone' => $phone, 'current_role' => $role,
            'password' => Hash::make(self::PASSWORD), 'email_verified_at' => now(), 'is_active' => true, 'deleted_at' => null,
        ])->save();

        return $user;
    }
}
