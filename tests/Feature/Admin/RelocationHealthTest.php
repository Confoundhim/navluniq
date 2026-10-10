<?php

namespace Tests\Feature\Admin;

use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Models\User;
use App\Services\LoadStandardizer;
use App\Services\SystemWatchdog;
use App\Support\AppVersion;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
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

    /**
     * 2026-10-10 canlı uyarısı "Yeniden konumlama 30 dk'dır ilerlemiyor": tek bir kayıtta çöken ya da takılan çalıştırma her 5 dakikada
     * aynı kayda dönüyordu. Artık kayıt başına hata yakalanır (sayılır, sıradakine geçilir), ilerleme ara kayıtla yazılır ve yarım kalan
     * kaydın izi (önbellek) bir sonraki çalıştırmada o kaydı atlatır; sağlık ekranı ve bekçi hangi kayıt olduğunu söyler.
     */
    public function test_relocation_survives_a_failing_record_and_skips_a_stuck_one(): void
    {
        $source = Scraper::create(['name' => 'Grup B', 'type' => 'notification', 'source_identifier' => 'notif:grup-b', 'is_active' => true]);
        $loads = [];
        foreach (['ORDU', 'SAMSUN', 'TRABZON'] as $i => $city) {
            $loads[] = ScrapedLoad::create(['scraper_id' => $source->id, 'content_hash' => 'h'.$i, 'raw_message' => "İSTANBUL → {$city}\n☎️ 0535 111 22 3{$i}", 'encrypted_sender_phone' => Crypt::encryptString('535111223'.$i),
                'pickup_location' => 'İzmir', 'pickup_province_code' => 35, 'delivery_location' => $city, 'delivery_province_code' => 52, 'status' => 'parsed_success', 'visibility' => 'private']);
        }
        [$oldest, $middle, $newest] = $loads;
        Settings::set('scraper_relocate_force_until', now()->addDays(3)->toDateTimeString());
        Settings::set('scraper_relocate_force_cursor', '0');

        // Önceki çalıştırma en yeni kayıtta takılmış (iz önbellekte, 40 dk önce) → bu kayıt atlanır; ortadaki kayıt hata fırlatır → sayılır, geçilir
        Cache::put('relocate-force:inflight', ['id' => $newest->id, 'at' => now()->subMinutes(40)->toDateTimeString()], 3600);
        $real = app(LoadStandardizer::class);
        $this->partialMock(LoadStandardizer::class, function ($mock) use ($real, $middle): void {
            $mock->shouldReceive('relocateFromRaw')->andReturnUsing(function (ScrapedLoad $load, bool $force = false) use ($real, $middle): bool {
                if ($load->id === $middle->id) {
                    throw new \RuntimeException('deneme: bozuk kayıt');
                }

                return $real->relocateFromRaw($load, $force);
            });
        });

        Artisan::call('scraped-loads:relocate-force', ['--seconds' => 15]);
        $out = Artisan::output();
        $this->assertStringContainsString('bitti', $out);
        $this->assertStringContainsString('hata: 1', $out);
        $this->assertSame(35, (int) $newest->fresh()->pickup_province_code, 'Takılan kayıt atlandı, dokunulmadı');
        $this->assertSame(35, (int) $middle->fresh()->pickup_province_code, 'Hata veren kayıt değişmedi');
        $this->assertSame(34, (int) $oldest->fresh()->pickup_province_code, 'Hatadan sonra sıradaki kayıt işlendi');
        $progress = json_decode(Settings::string('scraper_relocate_force_progress'), true);
        $this->assertSame([2, 1, 1, true], [$progress['done'], $progress['changed'], $progress['failed'], $progress['finished']]);
        $this->assertSame([$newest->id], $progress['skipped']);
        $this->assertStringContainsString('#'.$middle->id.': deneme: bozuk kayıt', $progress['last_error']);
        $this->assertNull(Cache::get('relocate-force:inflight'), 'Düzgün biten çalıştırma izi temizler');
        $this->assertSame('', Settings::string('scraper_relocate_force_until'));

        // Sağlık ekranı hata ve atlanan kaydı gösterir
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['current_role' => 'admin']);
        $admin->syncRoles(['super_admin']);
        $this->actingAs($admin->fresh());
        Volt::test('admin.health-center')->assertSee('hata 1')->assertSee('bozuk kayıt')->assertSee('atlanan kayıt: #'.$newest->id);
    }

    public function test_watchdog_names_the_record_a_stalled_run_is_waiting_on(): void
    {
        Settings::set('scraper_relocate_force_until', now()->addDays(3)->toDateTimeString());
        Settings::set('scraper_relocate_force_progress', json_encode(['done' => 10, 'changed' => 2, 'at' => now()->subMinutes(45)->toDateTimeString(), 'cursor' => 500, 'finished' => false, 'last_error' => '#777: deneme']));
        Cache::put('relocate-force:inflight', ['id' => 499, 'at' => now()->subMinutes(44)->toDateTimeString()], 3600);

        $message = (string) (new \ReflectionMethod(SystemWatchdog::class, 'checkRelocate'))->invoke(app(SystemWatchdog::class));
        $this->assertStringContainsString("30 dk'dır ilerlemiyor", $message);
        $this->assertStringContainsString('#499 kaydında bekliyor', $message);
        $this->assertStringContainsString('Son hata: #777: deneme', $message);
    }
}
