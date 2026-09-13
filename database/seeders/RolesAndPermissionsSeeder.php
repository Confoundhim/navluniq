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

        $permissions = [
            'view users', 'manage users', 'verify kyc',
            'view operations', 'manage operations',
            'manage scrapers', 'manage ai settings',
            'view financials', 'manage payouts',
            'manage disputes', 'manage support tickets',
            'manage cms', 'manage settings',
            'manage staff',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $superAdmin = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $superAdmin->syncPermissions($permissions);

        $kycValidator = Role::firstOrCreate(['name' => 'kyc_validator', 'guard_name' => 'web']);
        $kycValidator->syncPermissions(['view users', 'verify kyc', 'manage support tickets']);

        $financialOfficer = Role::firstOrCreate(['name' => 'financial_officer', 'guard_name' => 'web']);
        $financialOfficer->syncPermissions(['view financials', 'manage payouts', 'manage disputes']);

        Role::firstOrCreate(['name' => 'cargo_owner', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
