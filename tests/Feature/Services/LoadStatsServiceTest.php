<?php

namespace Tests\Feature\Services;

use App\Models\CargoOwnerProfile;
use App\Models\Load;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Models\User;
use App\Services\LoadStatsService;
use App\Services\ScrapedLoadService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** "Bugüne kadar" sayaçları: arşivlenen ilanlar sayılır, reddedilen ve bekleyen adaylar sayılmaz; yayın süresi ayardan. */
class LoadStatsServiceTest extends TestCase
{
    use RefreshDatabase;

    private function scraped(array $o = []): ScrapedLoad
    {
        return ScrapedLoad::create(array_merge([
            'scraper_id' => 1, 'content_hash' => 'h'.uniqid(), 'raw_message' => 'm', 'sender_phone' => '05551112233',
            'pickup_location' => 'Ankara', 'pickup_province_code' => 6, 'delivery_location' => 'İzmir', 'delivery_province_code' => 35,
            'vehicle_type' => 'tir', 'status' => 'parsed_success', 'visibility' => 'private', 'retention_expires_at' => now()->addDays(30),
        ], $o));
    }

    public function test_lifetime_counters_include_archived_loads_and_never_drop(): void
    {
        Scraper::create(['name' => 'Grup', 'type' => 'notification', 'source_identifier' => 'notif:grup', 'is_active' => true]);
        $this->scraped(['visibility' => 'public', 'published_at' => now()]);                                        // bugün, listede
        $archived = $this->scraped(['visibility' => 'public', 'published_at' => now()->subDays(10), 'pickup_province_code' => 34, 'retention_expires_at' => now()->subDay()]);
        $this->scraped(['status' => 'rejected']);                                                                   // sayılmaz
        $this->scraped();                                                                                           // onay bekliyor, sayılmaz
        $ownerUser = User::factory()->create();
        $owner = CargoOwnerProfile::create(['user_id' => $ownerUser->id, 'type' => 'individual']);
        Load::create(['cargo_owner_profile_id' => $owner->id, 'source_type' => 'internal', 'visibility' => 'public', 'pickup_location' => 'Bursa', 'delivery_location' => 'Konya',
            'pickup_date' => now(), 'vehicle_type' => 'tir', 'goods_type' => 'Yük', 'weight' => 1000, 'price' => 10000, 'status' => Load::STATUS_COMPLETED, 'escrow_status' => Load::ESCROW_RELEASED]);

        app(ScrapedLoadService::class)->purgeExpired(); // 10 günlük ilan listeden kalkar (arşiv)
        $this->assertNotNull($archived->fresh()?->deleted_at ?? ScrapedLoad::withTrashed()->find($archived->id)->deleted_at);

        $s = app(LoadStatsService::class);
        $s->forget();
        $r = $s->summary();
        $this->assertSame(2, $r['external_total'], 'Arşivlenen ilan sayaçtan düşmez');
        $this->assertSame(1, $r['external_today']);
        $this->assertSame(1, $r['external_open']);
        $this->assertSame(round(2 / 11, 1), $r['external_daily_avg']);
        $this->assertSame(1, $r['system_total']);
        $this->assertSame(1, $r['completed']);
        $this->assertSame(['Ankara', 'İstanbul'], array_column($r['top_provinces'], 'name'));

        Volt::test('frontend.home')->assertSee('Bugün 1 yeni')->assertSee('Bugüne kadar açılan');
    }

    public function test_publishing_sets_published_at_and_list_window_from_setting(): void
    {
        Scraper::create(['name' => 'Grup', 'type' => 'notification', 'source_identifier' => 'notif:grup', 'is_active' => true]);
        Settings::set('scraper_list_days', '5');
        $load = $this->scraped();
        app(ScrapedLoadService::class)->approve($load);
        $load->refresh();
        $this->assertNotNull($load->published_at);
        $this->assertSame(now()->addDays(5)->toDateString(), Carbon::parse($load->retention_expires_at)->toDateString());
        $this->assertSame('public', $load->visibility);
    }
}
