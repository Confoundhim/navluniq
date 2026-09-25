<?php

namespace Tests\Feature\Services;

use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Models\User;
use App\Services\LoadIntakeService;
use App\Services\ScrapedLoadService;
use App\Services\TelegramPublisher;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ScrapedLoadAutomationTest extends TestCase
{
    use RefreshDatabase;

    private function source(bool $active = true): Scraper
    {
        return Scraper::create(['name' => 'Grup A', 'type' => 'notification', 'source_identifier' => 'notif:grup-a', 'is_active' => $active]);
    }

    private function candidate(Scraper $source, array $overrides = []): ScrapedLoad
    {
        return ScrapedLoad::create(array_merge([
            'scraper_id' => $source->id,
            'content_hash' => hash('sha256', uniqid('', true)),
            'raw_message' => 'Ankara İzmir 24 ton 0532 123 45 67',
            'encrypted_sender_phone' => Crypt::encryptString('5321234567'),
            'pickup_location' => 'Ankara',
            'delivery_location' => 'İzmir',
            'weight' => 24000,
            'price' => null,
            'status' => 'parsed_success',
            'parsed_by_llm' => 'regex_verified',
            'visibility' => 'private',
            'duplicate_count' => 1,
            'seen_sources' => ['Grup A'],
        ], $overrides));
    }

    public function test_auto_approval_scan_rechecks_a_candidate_only_when_changed_stale_or_settings_changed(): void
    {
        $source = $this->source();
        $service = app(ScrapedLoadService::class);
        Settings::set('scraper_auto_approve', '1');
        Settings::set('scraper_auto_approve_require_price', '1');
        $load = $this->candidate($source);
        $this->travel(1)->minutes(); // zamanlayıcı ayar değişiminden sonra çalışır

        $this->assertSame(0, $service->autoApproveDue(), 'Fiyat zorunlu: engelli');
        $this->assertNotNull($load->fresh()->auto_checked_at, 'Değerlendirme zamanı yazılır');
        $checkedAt = $load->fresh()->auto_checked_at;

        // Hiçbir şey değişmedi: bir dakika sonra yeniden hesaplanmaz (zaman damgası aynı kalır)
        $this->travel(1)->minutes();
        $this->assertSame(0, $service->autoApproveDue());
        $this->assertTrue($load->fresh()->auto_checked_at->equalTo($checkedAt));

        // Aday değişti (yapay zeka sonucu, düzenleme): hemen yeniden bakılır
        ScrapedLoad::whereKey($load->id)->update(['updated_at' => now()->addSecond()]);
        $this->assertSame(0, $service->autoApproveDue());
        $this->assertTrue($load->fresh()->auto_checked_at->gt($checkedAt));

        // Ayar değişti: bekleyen aday ilk taramada yeniden değerlendirilir ve yayınlanır
        $this->travel(1)->minutes();
        Settings::set('scraper_auto_approve_require_price', '0');
        $this->assertSame(1, $service->autoApproveDue());
        $this->assertSame('public', $load->fresh()->visibility);

        // Değişmeyen engelli aday 10 dakika sonra kendiliğinden yeniden bakılır
        Settings::set('scraper_auto_approve_require_price', '1');
        $stale = $this->candidate($source, ['raw_message' => 'Bursa İzmir 10 ton 0532 123 45 67']);
        $this->travel(1)->minutes();
        $this->assertSame(0, $service->autoApproveDue());
        $t = $stale->fresh()->auto_checked_at;
        $this->travel(11)->minutes();
        $this->assertSame(0, $service->autoApproveDue());
        $this->assertTrue($stale->fresh()->auto_checked_at->gt($t));
    }

    public function test_auto_approval_respects_setting_and_criteria(): void
    {
        $source = $this->source();
        $load = $this->candidate($source);
        $service = app(ScrapedLoadService::class);

        $this->assertSame(0, $service->autoApproveDue(), 'Ayar kapalıyken onaylanmamalı');

        Settings::set('scraper_auto_approve', '1');
        Settings::set('scraper_auto_approve_require_price', '1');
        $this->assertSame(0, $service->autoApproveDue(), 'Fiyat zorunluyken fiyatsız aday onaylanmamalı');

        Settings::set('scraper_auto_approve_require_price', '0');
        Settings::set('scraper_free_delay_minutes', '30');
        $this->assertSame(1, $service->autoApproveDue());

        $load->refresh();
        $this->assertSame('public', $load->visibility);
        $this->assertNotNull($load->auto_approved_at);
        $this->assertNull($load->available_to_free_at, 'Dış kaynak ilanı herkese açılmaz; yalnız premium görür');

        $inactive = Scraper::create(['name' => 'Grup B', 'type' => 'notification', 'source_identifier' => 'notif:grup-b', 'is_active' => false]);
        $this->candidate($inactive);
        $this->assertSame(0, $service->autoApproveDue(), 'Pasif kaynağın adayı otomatik onaylanmamalı');
    }

    public function test_auto_approval_scans_past_blocked_candidates_and_records_failures(): void
    {
        Settings::set('scraper_auto_approve', '1');
        Settings::set('scraper_auto_approve_require_price', '1');
        $source = $this->source();
        $service = app(ScrapedLoadService::class);

        // 250 eski aday fiyatsız (engelli); daha yeni tek aday uygun → eskiden ilk 200'de kalıp hiç sıraya gelmezdi.
        for ($i = 0; $i < 250; $i++) {
            $this->candidate($source, ['price' => null]);
        }
        $eligible = $this->candidate($source, ['price' => 45000]);
        $this->assertSame(1, $service->autoApproveDue());
        $this->assertSame('public', $eligible->fresh()->visibility);

        // Kuyrukta 48 saatten uzun bekleyen engelli aday kendiliğinden reddedilir (ilan güncelliğini yitirdi).
        $stale = $this->candidate($source, ['price' => null]);
        $stale->forceFill(['created_at' => now()->subHours(50)])->save();
        $this->assertSame(0, $service->autoApproveDue());
        $this->assertSame('rejected', $stale->fresh()->status);
        $this->assertStringContainsString('48 saatten uzun', $stale->fresh()->meta('auto_rejected')['reason']);
        $this->assertSame(0, Settings::int('ai_local_docs_other'), 'Otomatik ret sınıflandırıcıya "ilan değil" diye öğretilmez');

        // Onay sırasında hata çıkarsa aday "uygun" görünmez; neden kayda yazılır, başarılı onay temizler.
        $broken = $this->candidate($source, ['price' => 45000]);
        $mock = \Mockery::mock(ScrapedLoadService::class)->makePartial();
        $mock->shouldReceive('approve')->once()->andThrow(new \RuntimeException('deneme hatası'));
        $this->assertSame(0, $mock->autoApproveDue());
        $broken->refresh();
        $this->assertSame('deneme hatası', $broken->meta('auto_approve_error')['message']);
        $this->assertSame('onay hatası: deneme hatası', $service->autoApprovalBlocker($broken));
        $service->approve($broken, null, true);
        $this->assertNull($broken->fresh()->meta('auto_approve_error'));
    }

    public function test_queue_can_be_filtered_by_auto_approval_eligibility(): void
    {
        Settings::set('scraper_auto_approve', '1');
        Settings::set('scraper_auto_approve_require_price', '1');
        $source = $this->source();
        $ok = $this->candidate($source, ['price' => 45000, 'raw_message' => 'UYGUN-ILAN Ankara İzmir 0532 123 45 67']);
        $blocked = $this->candidate($source, ['price' => null, 'raw_message' => 'ENGELLI-ILAN Ankara İzmir 0532 123 45 67']);
        $admin = User::factory()->create(['current_role' => 'admin']);
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin->syncRoles(['super_admin']);
        $this->actingAs($admin->fresh());

        $c = Volt::test('admin.scrapers-center')->set('activeTab', 'queue');
        $c->set('flag', 'auto_ok')->assertSee('UYGUN-ILAN')->assertDontSee('ENGELLI-ILAN');
        $c->set('flag', 'auto_blocked')->assertSee('ENGELLI-ILAN')->assertDontSee('UYGUN-ILAN')->assertSee('Fiyat yok');
    }

    public function test_duplicates_from_other_groups_increase_counter_instead_of_creating_rows(): void
    {
        Cache::flush();
        Http::fake();
        $intake = app(LoadIntakeService::class);
        $text = "Ankara'dan İzmir'e 24 ton palet 0532 123 45 67";
        foreach (['Grup A', 'Grup B', 'Grup C'] as $i => $group) {
            Scraper::create(['name' => $group, 'type' => 'notification', 'source_identifier' => 'notif:'.$i, 'is_active' => true]);
            $intake->intake(['group_name' => $group, 'source_jid' => 'notif:'.$i, 'message_id' => "m{$i}", 'raw_message' => $text]);
            // aynı gruptan tekrar teslim sayaca yazılmaz
            $intake->intake(['group_name' => $group, 'source_jid' => 'notif:'.$i, 'message_id' => "m{$i}-again", 'raw_message' => $text.' 🚛']);
        }

        $this->assertSame(1, ScrapedLoad::count());
        $load = ScrapedLoad::first();
        $this->assertSame(3, $load->duplicate_count);
        $this->assertSame(['Grup A', 'Grup B', 'Grup C'], $load->seen_sources);
    }

    public function test_publishing_a_candidate_whose_twin_is_already_public_marks_it_duplicate(): void
    {
        $source = $this->source();
        $other = Scraper::create(['name' => 'Grup B', 'type' => 'notification', 'source_identifier' => 'notif:grup-b', 'is_active' => true]);
        $hash = hash('sha256', 'ayni-metin');
        $published = $this->candidate($source, ['visibility' => 'public', 'normalized_hash' => $hash, 'route_key' => '5321234567|ankara|izmir']);
        $twin = $this->candidate($other, ['normalized_hash' => $hash, 'route_key' => '5321234567|ankara|izmir', 'seen_sources' => ['Grup B']]);
        $service = app(ScrapedLoadService::class);

        // Aynı anda gelen iki grup mesajı iki aday açtıysa ikincisi yayına alınmaz; yayındakinin sayacına yazılır.
        $this->assertSame("tekrar (#{$published->id} yayında)", $service->autoApprovalBlocker($twin));
        $this->assertSame(['rejected', 'private', $published->id], [$twin->fresh()->status, $twin->fresh()->visibility, $twin->fresh()->meta('duplicate_of')]);
        $this->assertSame(['Grup A', 'Grup B'], $published->fresh()->seen_sources);
        $this->assertSame(2, $published->fresh()->duplicate_count);

        // Elle "Yayınla" da aynı numara + rota 48 saat içinde yayındaysa reddedilir.
        $sameRoute = $this->candidate($other, ['normalized_hash' => hash('sha256', 'baska-metin'), 'route_key' => '5321234567|ankara|izmir', 'raw_message' => 'Ankaradan İzmire 24 ton yük var 0532 123 45 67']);
        try {
            $service->approve($sameRoute, null);
            $this->fail('Tekrar yayınlanmamalıydı');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("zaten yayında (#{$published->id})", $e->getMessage());
        }
        $this->assertSame('rejected', $sameRoute->fresh()->status);
        $this->assertSame(1, ScrapedLoad::where('visibility', 'public')->count());
    }

    public function test_intake_holds_a_per_message_lock_and_releases_it(): void
    {
        Cache::flush();
        Http::fake();
        Scraper::create(['name' => 'Grup A', 'type' => 'notification', 'source_identifier' => 'notif:a', 'is_active' => true]);
        $text = "Ankara'dan İzmir'e 24 ton palet 0532 123 45 67";
        $key = 'intake:lock:'.hash('sha256', LoadIntakeService::normalizeText($text));

        $r = app(LoadIntakeService::class)->intake(['group_name' => 'Grup A', 'source_jid' => 'notif:a', 'message_id' => 'm1', 'raw_message' => $text]);
        $this->assertSame('created', $r['status']);
        $lock = Cache::lock($key, 5);
        $this->assertTrue($lock->get(), 'işlem bitince kilit bırakılmalı');
        $lock->release();
    }

    public function test_external_loads_are_never_posted_to_telegram(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 5]])]);
        Settings::set('telegram_post_enabled', '1');
        Settings::set('telegram_bot_token', '123456:ABCDEF');
        Settings::set('telegram_channel_id', '@navluniq');
        $source = $this->source();
        $due = $this->candidate($source, ['visibility' => 'public', 'available_to_free_at' => now()->subMinute(), 'duplicate_count' => 3, 'price' => 45000]);

        $this->artisan('loads:release-to-free')->assertSuccessful();
        Http::assertNothingSent();
        $this->assertNull($due->fresh()->telegram_posted_at);
        $this->assertSame('https://t.me/navluniq', TelegramPublisher::channelUrl());
    }
}
