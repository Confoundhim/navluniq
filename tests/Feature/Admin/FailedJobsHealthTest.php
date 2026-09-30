<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Sağlık ekranı: başarısız işin adı ve nedeni görünür; "Temizle" siler, satır yeşile döner. */
class FailedJobsHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_jobs_show_their_reason_and_can_be_flushed_from_the_health_page(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['current_role' => 'admin']);
        $admin->syncRoles(['super_admin']);
        $this->actingAs($admin->fresh());
        DB::table('failed_jobs')->insert([
            'uuid' => 'test-uuid-1', 'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\ProcessNotificationMessage', 'job' => 'x', 'data' => []]),
            'exception' => "RuntimeException: Yapay zeka sağlayıcısına ulaşılamadı\n#0 stack", 'failed_at' => now(),
        ]);

        $c = Volt::test('admin.health-center')
            ->assertSee('1 başarısız iş bekliyor')->assertSee('ProcessNotificationMessage')->assertSee('Yapay zeka sağlayıcısına ulaşılamadı')
            ->assertSee('Yeniden dene')->assertSee('Temizle');

        $c->call('flushFailedJobs')->assertSee('0 başarısız iş')->assertDontSee('Temizle');
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }
}
