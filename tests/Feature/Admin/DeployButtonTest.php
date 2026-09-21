<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\DeployService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DeployButtonTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        @unlink(DeployService::lockPath());
        @unlink(DeployService::logPath());
        parent::tearDown();
    }

    public function test_admin_can_start_an_update_from_the_health_page_and_see_its_log(): void
    {
        config()->set('services.deploy.command', 'echo "merhaba guncelleme"');
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['current_role' => 'admin']);
        $admin->syncRoles(['super_admin']);
        $this->actingAs($admin->fresh());

        $c = Volt::test('admin.health-center')->assertSee('Siteyi güncelle')->call('startUpdate')->assertSee('Güncelleme başlatıldı');
        for ($i = 0; $i < 50 && DeployService::isRunning(); $i++) {
            usleep(100000);
        }
        $status = DeployService::status();
        $this->assertFalse($status['running']);
        $this->assertTrue($status['ok'], $status['log']);
        $this->assertStringContainsString('merhaba guncelleme', $status['log']);
        $this->assertStringContainsString('[navluniq-update] TAMAM', $status['log']);

        // Başarısız komut hata olarak görünür; çalışırken ikinci başlatma reddedilir.
        config()->set('services.deploy.command', 'false');
        $this->assertTrue(app(DeployService::class)->start());
        for ($i = 0; $i < 50 && DeployService::isRunning(); $i++) {
            usleep(100000);
        }
        $this->assertFalse(DeployService::status()['ok']);
        file_put_contents(DeployService::lockPath(), (string) time());
        $this->assertFalse(app(DeployService::class)->start(), 'Kilit varken ikinci güncelleme başlamaz');
    }

    public function test_non_admin_cannot_start_an_update(): void
    {
        $driver = User::factory()->create(['current_role' => 'driver']);
        $this->actingAs($driver)->get(route('admin.health'))->assertRedirect();
        $this->assertFalse(DeployService::isRunning());
    }
}
