<?php

namespace Tests\Feature\Admin;

use App\Models\CargoOwnerProfile;
use App\Models\DriverProfile;
use App\Models\Load;
use App\Models\Offer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoResetCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_purges_matching_accounts_with_their_data_and_creates_dual_role_demo(): void
    {
        $old = User::create(['first_name' => 'Osman', 'last_name' => 'Eski', 'email' => 'osman@example.test', 'phone' => '5376429671', 'password' => bcrypt('GucluParola12345'), 'current_role' => 'cargo_owner']);
        $owner = CargoOwnerProfile::create(['user_id' => $old->id, 'type' => 'individual']);
        $other = User::create(['first_name' => 'Başka', 'last_name' => 'Şoför', 'email' => 'baska@example.test', 'phone' => '5321111111', 'password' => bcrypt('GucluParola12345'), 'current_role' => 'driver']);
        $otherDriver = DriverProfile::create(['user_id' => $other->id, 'kyc_status' => 'approved']);
        $load = Load::create([
            'cargo_owner_profile_id' => $owner->id, 'source_type' => 'internal', 'visibility' => 'public', 'pickup_location' => 'Ankara', 'delivery_location' => 'İzmir',
            'pickup_date' => now()->addDay(), 'vehicle_type' => 'tir', 'goods_type' => 'palet', 'price' => 15000, 'status' => Load::STATUS_ACTIVE, 'escrow_status' => Load::ESCROW_PENDING,
        ]);
        Offer::create(['load_id' => $load->id, 'driver_profile_id' => $otherDriver->id, 'amount' => 14000, 'status' => 'pending']);

        // Ön izleme: --force yokken hiçbir şey silinmez
        $this->artisan('demo:reset', ['--email' => ['osman@example.test'], '--demo-email' => 'demo@example.test', '--demo-phone' => '05376429671'])
            ->expectsOutputToContain('Yazma yapılmadı')->assertSuccessful();
        $this->assertDatabaseHas('users', ['email' => 'osman@example.test']);

        $this->artisan('demo:reset', ['--email' => ['osman@example.test'], '--demo-email' => 'demo@example.test', '--demo-phone' => '05376429671', '--demo-name' => 'Mehmet Demo', '--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('users', ['email' => 'osman@example.test']);
        $this->assertDatabaseMissing('loads', ['id' => $load->id]);
        $this->assertSame(0, Offer::count(), 'Silinen ilana verilen teklif de gitmeli');
        $this->assertDatabaseHas('users', ['email' => 'baska@example.test']);

        $demo = User::where('email', 'demo@example.test')->firstOrFail();
        $this->assertSame('5376429671', $demo->phone);
        $this->assertTrue($demo->is_active);
        $this->assertNotNull($demo->email_verified_at);
        $this->assertTrue($demo->hasRole('driver') && $demo->hasRole('cargo_owner'));
        $this->assertTrue($demo->driverProfile->isKycApproved());
        $this->assertSame('approved', $demo->cargoOwnerProfile->kyc_status);
        $this->assertSame('tir', $demo->driverProfile->activeVehicle->vehicle_type);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('Password123!', $demo->password));
    }
}
