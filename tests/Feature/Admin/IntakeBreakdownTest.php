<?php

namespace Tests\Feature\Admin;

use App\Models\IntakeEvent;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Dış kaynak özeti: "gelen istek" tekrar/elenme/Facebook paketi ayrımıyla, "bugün yayınlanan" kaynak payı ve yeniden paylaşılanlarla okunur. */
class IntakeBreakdownTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_breaks_down_today_requests_and_publications(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['current_role' => 'admin']);
        $admin->syncRoles(['super_admin']);
        $this->actingAs($admin->fresh());
        $wa = Scraper::create(['name' => 'Grup A', 'type' => 'notification', 'source_identifier' => 'notif:grup-a', 'is_active' => true]);
        $fb = Scraper::create(['name' => 'FB Grubu', 'type' => 'facebook', 'source_identifier' => 'fb:grup', 'is_active' => true]);
        foreach (['created', 'duplicate', 'duplicate', 'duplicate', 'screen'] as $status) {
            IntakeEvent::record($status, ['source_name' => 'Grup A', 'title' => 't', 'excerpt' => 'x']);
        }
        IntakeEvent::record('filtered', ['source_name' => 'Grup A', 'title' => 't', 'excerpt' => 'x', 'reason' => 'pickup_missing']);
        IntakeEvent::record('filtered', ['source_name' => 'Grup A', 'title' => 't', 'excerpt' => 'x', 'reason' => 'pickup_missing']);
        IntakeEvent::record('filtered', ['source_name' => 'Grup A', 'title' => 't', 'excerpt' => 'x', 'reason' => 'phone_missing']);
        $base = ['raw_message' => 'm', 'sender_phone' => '05551112233', 'pickup_location' => 'Ankara', 'pickup_province_code' => 6, 'delivery_location' => 'İzmir', 'delivery_province_code' => 35, 'status' => 'parsed_success', 'visibility' => 'public'];
        ScrapedLoad::create($base + ['scraper_id' => $wa->id, 'content_hash' => 'b1', 'published_at' => now()]);
        ScrapedLoad::create($base + ['scraper_id' => $fb->id, 'content_hash' => 'b2', 'published_at' => now()]);
        ScrapedLoad::create($base + ['scraper_id' => $wa->id, 'content_hash' => 'b3', 'published_at' => now()->subDays(3), 'last_seen_at' => now()]); // bugün yeniden paylaşıldı

        Volt::test('admin.scrapers-center')
            ->assertSee('1 kuyruğa · 3 tekrar · 3 elendi · 1 Facebook paketi')
            ->assertSee('kalkış yeri yazmıyor (yalnız varış listesi)')->assertSee('telefon numarası yok')
            ->assertSee('Facebook 1 · WhatsApp 1 · ayrıca 1 eski ilan bugün yeniden paylaşıldı');
    }
}
