<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // "manage system" (2026-10-05): güncelleme, yedek alma/silme, çöpten kalıcı silme, güvenlik duvarı, yeniden konumlama,
        // sabit kodla giriş ayarları gibi sunucuyu/kodu etkileyen işler. Yalnız süper yöneticiye verilir; "manage settings" artık tam yetki değildir.
        $permissions = [
            'view users', 'manage users', 'verify kyc',
            'view operations', 'manage operations',
            'manage scrapers',
            'view financials', 'manage payouts',
            'manage disputes', 'manage support tickets',
            'manage cms', 'manage marketing', 'manage settings',
            'manage staff', 'manage system',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        // Ölü izin: hiçbir ekran bakmıyordu (yapay zeka ayarları "manage settings" altında).
        Permission::query()->where('name', 'manage ai settings')->where('guard_name', 'web')->delete();

        $superAdmin = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $superAdmin->syncPermissions($permissions);

        $kycValidator = Role::firstOrCreate(['name' => 'kyc_validator', 'guard_name' => 'web']);
        $kycValidator->syncPermissions(['view users', 'verify kyc', 'manage support tickets']);

        $financialOfficer = Role::firstOrCreate(['name' => 'financial_officer', 'guard_name' => 'web']);
        $financialOfficer->syncPermissions(['view financials', 'manage payouts', 'manage disputes']);

        $supportAgent = Role::firstOrCreate(['name' => 'support_agent', 'guard_name' => 'web']);
        $supportAgent->syncPermissions(['manage support tickets', 'view users']);

        Role::firstOrCreate(['name' => 'cargo_owner', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
