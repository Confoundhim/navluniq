<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IsolateTestCopyCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_isolation_disables_outbound_channels_and_enables_code_login_for_admins(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['email' => 'Yonetici@Example.com']);
        $admin->syncRoles(['super_admin']);
        User::factory()->driver()->create(['email' => 'sofor@example.com']);
        Settings::set('mail_password', 'gizli');
        Settings::set('mail_host', 'smtp.example.com');
        Settings::set('telegram_post_enabled', 1);
        Settings::set('telegram_bot_token', '123:abc');
        Settings::set('payment_provider', 'iyzico');
        Settings::set('iyzico_sandbox', 0);

        $this->artisan('deneme:izole')->assertSuccessful();

        $this->assertSame('', Settings::string('mail_password'));
        $this->assertFalse(Settings::bool('telegram_post_enabled'));
        $this->assertSame('', Settings::string('telegram_bot_token'));
        $this->assertSame('', Settings::string('payment_provider'));
        $this->assertTrue(Settings::bool('iyzico_sandbox'));
        $this->assertSame('yonetici@example.com', Settings::string('review_login_emails'));
        $this->assertSame('123456', Settings::string('review_login_code'));

        // Açık e-posta listesi ve özel kod verilebilir; kod 6 haneli olmalı.
        $this->artisan('deneme:izole', ['--email' => 'a@example.com, B@example.com', '--code' => '654321'])->assertSuccessful();
        $this->assertSame('a@example.com, b@example.com', Settings::string('review_login_emails'));
        $this->assertSame('654321', Settings::string('review_login_code'));
        $this->artisan('deneme:izole', ['--code' => '12'])->assertFailed();
    }
}
