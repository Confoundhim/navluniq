<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = trim((string) config('bootstrap.admin.email'));
        $password = (string) config('bootstrap.admin.password');
        $phone = trim((string) config('bootstrap.admin.phone'));

        if ($email === '' && $password === '' && $phone === '') {
            $this->command?->warn('Admin oluşturulmadı: ADMIN_INIT_* değerleri boş.');
            return;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || $phone === '' || strlen($password) < 12) {
            throw new RuntimeException('Admin seed için geçerli e-posta, telefon ve en az 12 karakterli parola zorunludur.');
        }

        $admin = User::updateOrCreate(
            ['email' => $email],
            [
                'first_name' => 'NavlunIQ',
                'last_name' => 'Admin',
                'phone' => $phone,
                'password' => Hash::make($password),
                'current_role' => 'admin',
                'is_active' => true,
            ]
        );

        $admin->syncRoles(['super_admin']);
    }
}
