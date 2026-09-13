<?php

namespace Database\Seeders;

use App\Models\CargoOwnerProfile;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class ExampleUsersSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || ! config('bootstrap.demo_user.enabled')) {
            return;
        }

        $email = trim((string) config('bootstrap.demo_user.email'));
        $password = (string) config('bootstrap.demo_user.password');
        $phone = trim((string) config('bootstrap.demo_user.phone'));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || $phone === '' || strlen($password) < 12) {
            throw new RuntimeException('Örnek kullanıcı için geçerli e-posta, telefon ve en az 12 karakterli parola zorunludur.');
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'first_name' => 'Örnek',
                'last_name' => 'Kullanıcı',
                'phone' => $phone,
                'password' => Hash::make($password),
                'current_role' => 'cargo_owner',
                'is_active' => true,
            ]
        );

        CargoOwnerProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'type' => 'individual',
                'nvi_verified' => false,
                'gib_verified' => false,
                'kyc_status' => 'unsubmitted',
            ]
        );

        DriverProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'premium_until' => null,
                'kyc_status' => 'unsubmitted',
                'ocr_data' => null,
            ]
        );

        $user->syncRoles(['cargo_owner', 'driver']);
    }
}
