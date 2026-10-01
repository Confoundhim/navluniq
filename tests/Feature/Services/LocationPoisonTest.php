<?php

namespace Tests\Feature\Services;

use App\Models\AiLexicon;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Services\LearningService;
use App\Services\LoadIntakeService;
use App\Support\Lexicon;
use App\Support\Settings;
use App\Support\TurkishLocations;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 2026-10-01 kök neden: öğrenilmiş konum takma adı ("ankara" → "İzmir Torbalı") kataloğu eziyordu; otomatik yayından
 * öğrenme bunu kendi kendine besliyordu; her gün yeniden paylaşılan ilan tazelenmeyip 7 günde düşüyordu.
 */
class LocationPoisonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Lexicon::flush();
        Http::preventStrayRequests();
        Settings::set('ai_parse_mode', 'off');
    }

    public function test_catalog_always_beats_a_learned_alias_for_known_names(): void
    {
        AiLexicon::create(['kind' => 'location', 'term' => 'ankara', 'canonical' => 'İzmir Torbalı', 'status' => 'active', 'source' => 'learned']);
        AiLexicon::create(['kind' => 'location', 'term' => 'yükkent', 'canonical' => 'Kocaeli Gebze', 'status' => 'active', 'source' => 'admin']);
        Lexicon::flush();
        $this->assertSame('Ankara', TurkishLocations::resolve('Ankara')['province'], 'bilinen il adı takma adla başka yere gidemez');
        $this->assertSame(['Kocaeli', 'Gebze'], [TurkishLocations::resolve('yükkent')['province'], TurkishLocations::resolve('yükkent')['district']], 'katalogda olmayan jargon sözlükten çözülür');

        Scraper::create(['name' => 'Grup A', 'type' => 'notification', 'source_identifier' => 'notif:grup-a', 'is_active' => true]);
        $r = app(LoadIntakeService::class)->intake(['group_name' => 'Grup A', 'raw_message' => "Ankara > Kayseri\nSabah yükler\n40 ayak\n05411112233", 'message_id' => 'p1', 'source_jid' => 'notif:grup-a']);
        $load = ScrapedLoad::find($r['scraped_load_id']);
        $this->assertSame(['Ankara', 'Kayseri'], [$load->pickup_location, $load->delivery_location]);
    }

    public function test_known_names_are_never_learned_and_auto_approval_does_not_teach_locations(): void
    {
        $learning = app(LearningService::class);
        $learning->learnLocations('Ankara - İzmir Torbalı 20 ton tır 0532 111 22 33', 'İzmir Torbalı', 'Ankara');
        $this->assertSame(0, AiLexicon::query()->where('kind', 'location')->count(), 'katalogda çözülen yazım sözlüğe girmez');
        $learning->learnLocations('Yükkent - Bursa 20 ton tır 0532 111 22 33', 'Kocaeli Gebze', 'Bursa');
        $this->assertSame(['yukkent' => 'Kocaeli Gebze'], AiLexicon::query()->where('kind', 'location')->pluck('canonical', 'term')->all(), 'yalnız katalogda olmayan jargon öğrenilir');

        Scraper::create(['name' => 'Grup A', 'type' => 'notification', 'source_identifier' => 'notif:grup-a', 'is_active' => true]);
        $r = app(LoadIntakeService::class)->intake(['group_name' => 'Grup A', 'raw_message' => 'Kavaklıdere - Bursa 20 ton tır 0532 111 22 44', 'message_id' => 'p2', 'source_jid' => 'notif:grup-a']);
        $load = ScrapedLoad::find($r['scraped_load_id']);
        $load->forceFill(['pickup_location' => 'Manisa Soma', 'pickup_province_code' => 45])->save();
        $learning->onApproved($load->fresh(), byAdmin: false);
        $this->assertNull(AiLexicon::query()->where('kind', 'location')->where('term', 'kavaklıdere')->first(), 'otomatik yayın konum öğretmez (kendi kendini besleyen döngü)');
    }

    public function test_migration_cleanup_removes_aliases_that_shadow_the_catalog_and_arms_forced_relocation(): void
    {
        AiLexicon::create(['kind' => 'location', 'term' => 'ankara', 'canonical' => 'İzmir Torbalı', 'status' => 'active', 'source' => 'learned']);
        AiLexicon::create(['kind' => 'location', 'term' => 'torbalı', 'canonical' => 'Elazığ', 'status' => 'active', 'source' => 'admin']);
        AiLexicon::create(['kind' => 'location', 'term' => 'yükkent', 'canonical' => 'Kocaeli Gebze', 'status' => 'active', 'source' => 'admin']);
        Settings::set('scraper_relocate_force_until', '');
        $migration = require base_path('database/migrations/0001_01_32_000000_add_last_seen_to_scraped_loads_and_clean_location_aliases.php');
        $migration->up(); // yeniden çalıştırmak güvenli
        $this->assertSame(['yükkent'], AiLexicon::query()->where('kind', 'location')->pluck('term')->all());
        $this->assertNotSame('', Settings::string('scraper_relocate_force_until'));

        // Zorunlu yeniden konumlama: yapay zeka çözümü de ham mesaja göre düzelir, yönetici düzenlemesi korunur.
        Scraper::create(['name' => 'Grup A', 'type' => 'notification', 'source_identifier' => 'notif:grup-a', 'is_active' => true]);
        $svc = app(LoadIntakeService::class);
        $wrong = ScrapedLoad::find($svc->intake(['group_name' => 'Grup A', 'raw_message' => "Ankara Adana\nKapalı Tır\n05411112244", 'message_id' => 'p3', 'source_jid' => 'notif:grup-a'])['scraped_load_id']);
        $wrong->forceFill(['pickup_location' => 'İzmir Torbalı', 'pickup_province_code' => 35, 'pickup_district' => 'Torbalı', 'delivery_location' => 'İzmir', 'delivery_province_code' => 35, 'ai_status' => 'done', 'parse_confidence' => 0.9])->save();
        $edited = ScrapedLoad::find($svc->intake(['group_name' => 'Grup A', 'raw_message' => "Ankara Konya\nTenteli tır\n05411112255", 'message_id' => 'p4', 'source_jid' => 'notif:grup-a'])['scraped_load_id']);
        $edited->forceFill(['pickup_location' => 'Eskişehir', 'pickup_province_code' => 26, 'parse_metadata' => ['admin_edited' => true]])->save();
        Artisan::call('scraped-loads:relocate-force');
        $this->assertSame(['Ankara', 'Adana'], [$wrong->fresh()->pickup_location, $wrong->fresh()->delivery_location]);
        $this->assertSame('Eskişehir', $edited->fresh()->pickup_location, 'yönetici düzenlemesi korunur');
        $this->assertSame('', Settings::string('scraper_relocate_force_until'), 'iş bitince ayar silinir');
    }

    public function test_reposted_ad_is_refreshed_instead_of_aging_out(): void
    {
        Scraper::create(['name' => 'Grup A', 'type' => 'notification', 'source_identifier' => 'notif:grup-a', 'is_active' => true]);
        $svc = app(LoadIntakeService::class);
        $msg = 'İstanbul Hadımköy yükleme Ankara teslim 20 ton palet tenteli tır 0532 111 22 33';
        $first = $svc->intake(['group_name' => 'Grup A', 'raw_message' => $msg, 'message_id' => 'r1', 'source_jid' => 'notif:grup-a']);
        $load = ScrapedLoad::find($first['scraped_load_id']);
        $this->assertNotNull($load->last_seen_at);
        $load->forceFill(['created_at' => now()->subDays(5), 'last_seen_at' => now()->subDays(5), 'published_at' => now()->subDays(5), 'visibility' => 'public', 'status' => 'parsed_success', 'retention_expires_at' => now()->addDays(2)])->save();

        $again = $svc->intake(['group_name' => 'Grup B', 'raw_message' => $msg, 'message_id' => 'r2', 'source_jid' => 'notif:grup-b']);
        $this->assertSame(['duplicate', $load->id], [$again['status'], $again['scraped_load_id']]);
        $fresh = $load->fresh();
        $this->assertSame(2, $fresh->sighting_count);
        $this->assertTrue($fresh->last_seen_at->gt(now()->subMinute()), 'yeniden paylaşım son görülmeyi ileri alır');
        $this->assertTrue(Carbon::parse($fresh->retention_expires_at)->gt(now()->addDays(5)), 'yayındaki ilanın saklama süresi uzar');
        $this->assertSame(1, ScrapedLoad::query()->count(), 'ikinci kayıt açılmaz');

        // Aynı numara + rota 48 saat içinde yeniden gelirse de tek kayıt (son görülmeye göre)
        $load->forceFill(['created_at' => now()->subDays(6), 'last_seen_at' => now()->subHours(3)])->save();
        $route = $svc->intake(['group_name' => 'Grup A', 'raw_message' => 'Hadımköy çıkışlı Ankara teslim tenteli tır lazım 0532 111 22 33', 'message_id' => 'r3', 'source_jid' => 'notif:grup-a']);
        $this->assertSame('duplicate', $route['status']);
        $this->assertSame(3, $load->fresh()->sighting_count);
    }

    public function test_equals_series_format_splits_into_district_level_destinations(): void
    {
        Scraper::create(['name' => 'Grup A', 'type' => 'notification', 'source_identifier' => 'notif:grup-a', 'is_active' => true]);
        $msg = "PRESLİ SAMAN YÜKLEME KAPALI TENTE ARAÇLAR YÜKLER.\nİRT: 0532 111 22 33\n\nKIZILTEPE = ADAPAZARI DİLOVASI\nKIZILTEPE = OSMANİYE KADİRLİ\nKIZILTEPE = MARAŞ MERKEZ\nKIZILTEPE = MARAŞ GÖKSUN\nKIZILTEPE = MANİSA KULA\nKIZILTEPE = MANİSA MERKEZ\nKIZILTEPE = MERSİN MUT";
        $r = app(LoadIntakeService::class)->intake(['group_name' => 'Grup A', 'raw_message' => $msg, 'message_id' => 's1', 'source_jid' => 'notif:grup-a']);
        $this->assertSame('created', $r['status']);
        $loads = ScrapedLoad::query()->orderBy('id')->get();
        $this->assertCount(7, $loads, 'her "KIZILTEPE = X" satırı ayrı ilan; aynı ilin ilçeleri ayrı kalır');
        $this->assertSame(['Mardin Kızıltepe'], array_values(array_unique($loads->pluck('pickup_location')->all())));
        $this->assertContains('Kahramanmaraş Göksun', $loads->pluck('delivery_location')->all());
        $this->assertSame(['tir'], array_values(array_unique($loads->pluck('vehicle_type')->all())), 'başlıktaki kasa/araç notu her noktaya taşınır');
    }
}
