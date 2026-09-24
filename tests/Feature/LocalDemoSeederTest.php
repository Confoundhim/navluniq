<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Settings;
use Database\Seeders\LocalDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Deneme hesapları tohumu: tekrar çalıştırılabilir, hesaplar ve sabit giriş kodu hazır. */
class LocalDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_demo_accounts_idempotently(): void
    {
        $this->seed(LocalDemoSeeder::class);
        $this->seed(LocalDemoSeeder::class);

        $driver = User::query()->where('email', 'sofor@test.local')->firstOrFail();
        $this->assertSame('driver', $driver->current_role);
        $this->assertTrue($driver->driverProfile->isPremium());
        $this->assertTrue($driver->driverProfile->isKycApproved());
        $this->assertSame('tenteli', $driver->driverProfile->activeVehicle()->value('body_type'));
        $this->assertSame(1, User::query()->where('email', 'admin@test.local')->count());
        $this->assertTrue(User::query()->where('email', 'admin@test.local')->first()->hasRole('super_admin'));
        $this->assertNotNull(User::query()->where('email', 'yuk@test.local')->first()->cargoOwnerProfile);
        $this->assertSame(LocalDemoSeeder::OTP, Settings::string('review_login_code'));
        $this->assertStringContainsString('sofor@test.local', Settings::string('review_login_emails'));
    }
}
