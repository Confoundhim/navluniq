<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Denetim Y21: rotasız "Sayfalar" sekmesi, uygulanmayan kuponlar ve sabit sunucu IP'si panelden kalktı. */
class DeadTabsRemovedTest extends TestCase
{
    use RefreshDatabase;

    public function test_dead_tabs_and_hard_coded_ip_are_gone(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['current_role' => 'admin']);
        $admin->syncRoles(['super_admin']);
        $this->actingAs($admin->fresh());

        $this->get('/adminsystem/cms')->assertOk()->assertSee('Sıkça sorulan sorular')->assertDontSee('Sayfalar')->assertDontSee('herkese açık bir rota bu sürümde tanımlı değildir');
        Volt::test('admin.cms-center')->set('activeTab', 'pages')->assertDontSee('Yeni sayfa');

        $this->get('/adminsystem/crm')->assertOk()->assertDontSee('Kuponlar')->assertSee('Duyuru (hizmet bildirimi)');

        $this->get('/adminsystem/backups')->assertOk()->assertDontSee('185.22.187.140')->assertSee('root@'.parse_url((string) config('app.url'), PHP_URL_HOST).':');

        $this->get('/adminsystem/firewall')->assertOk()->assertSee('kullanıcı başına 60 istek / dakika')->assertDontSee('OTP: 3 gönderim');
    }
}
