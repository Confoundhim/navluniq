<?php

namespace Tests\Feature\Admin;

use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Models\User;
use App\Support\AppVersion;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Sağlık ekranı: çalışan sürüm ve yeniden konumlama ilerlemesi görünür; "Bir parça şimdi çalıştır" zamanlayıcıyı beklemez. */
class RelocationHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_page_shows_version_and_relocation_progress_and_runs_a_batch_on_demand(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['current_role' => 'admin']);
        $admin->syncRoles(['super_admin']);
        $this->actingAs($admin->fresh());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{7}$/', (string) AppVersion::commit());

        $source = Scraper::create(['name' => 'Grup A', 'type' => 'notification', 'source_identifier' => 'notif:grup-a', 'is_active' => true]);
        // Zehirli sözlükle "İzmir" olmuş İstanbul çıkışlı ilan
        $load = ScrapedLoad::create(['scraper_id' => $source->id, 'content_hash' => 'r1', 'raw_message' => "İSTANBUL → ORDU\n☎️ 0535 111 22 33", 'encrypted_sender_phone' => Crypt::encryptString('5351112233'),
            'pickup_location' => 'İzmir', 'pickup_province_code' => 35, 'delivery_location' => 'Ordu', 'delivery_province_code' => 52, 'status' => 'parsed_success', 'visibility' => 'private']);
        Settings::set('scraper_relocate_force_until', now()->addDays(3)->toDateTimeString());
        Settings::set('scraper_relocate_force_cursor', '0');

        $c = Volt::test('admin.health-center')->assertSee('Çalışan sürüm')->assertSee((string) AppVersion::commit())
            ->assertSee('Yeniden konumlama')->assertSee('kalan 1 ilan')->assertSee('Bir parça şimdi çalıştır');

        $c->call('runRelocateBatch')->assertSee('tamamlandı')->assertSee('bakılan 1, değişen 1')->assertDontSee('Bir parça şimdi çalıştır');
        $this->assertSame([34, 'İstanbul'], [(int) $load->fresh()->pickup_province_code, $load->fresh()->pickup_location]);
        $this->assertSame('', Settings::string('scraper_relocate_force_until'));
    }
}
