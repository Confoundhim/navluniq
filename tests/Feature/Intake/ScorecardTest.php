<?php

namespace Tests\Feature\Intake;

use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** 2026-10-04 denetimi, 6. paket: dış kaynak özetinde hat karnesi (bekleyen dağılımı, yaşla ret, açılış→yayın medyan, sağlayıcı durumu). */
class ScorecardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_summary_shows_the_pipeline_scorecard(): void
    {
        Cache::flush();
        Http::fake();
        Settings::set('ai_parse_mode', 'always');
        Settings::set('ai_gemini_key', 'AIza-test');
        $source = Scraper::create(['name' => 'Grup A', 'type' => 'notification', 'source_identifier' => 'notif:grup-a', 'is_active' => true]);
        $mk = fn (array $o) => ScrapedLoad::create(array_merge([
            'scraper_id' => $source->id, 'content_hash' => hash('sha256', uniqid('', true)), 'raw_message' => 'Ankara İzmir 24 ton 0532 111 22 33',
            'encrypted_sender_phone' => Crypt::encryptString('5321112233'), 'pickup_location' => 'Ankara', 'pickup_province_code' => 6,
            'delivery_location' => 'İzmir', 'delivery_province_code' => 35, 'status' => 'parsed_success', 'visibility' => 'private',
        ], $o));
        $mk(['ai_status' => 'pending']);
        $mk(['ai_status' => 'skipped', 'delivery_province_code' => null]);
        $mk(['ai_status' => 'skipped']);
        $published = $mk(['ai_status' => 'skipped', 'visibility' => 'public', 'published_at' => now()]);
        ScrapedLoad::whereKey($published->id)->update(['created_at' => now()->subMinutes(42)]);
        $mk(['status' => 'rejected', 'parse_metadata' => ['auto_rejected' => ['reason' => 'kuyrukta 48 saatten uzun bekledi', 'at' => now()->toDateTimeString()]]]);

        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['current_role' => 'admin']);
        $admin->syncRoles(['super_admin']);
        $this->actingAs($admin->fresh());

        Volt::test('admin.scrapers-center')
            ->assertSee('Bekleyen:')
            ->assertSee('yapay zeka bekliyor')
            ->assertSee('il/rota çözülemedi')
            ->assertSee('puan / elle kontrol')
            ->assertSee('Bugün yaşla reddedilen')
            ->assertSee('42 dk')
            ->assertSee('Google Gemini')
            ->assertSee('çalışıyor');
    }
}
