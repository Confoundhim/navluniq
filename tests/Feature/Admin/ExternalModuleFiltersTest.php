<?php

namespace Tests\Feature\Admin;

use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Models\User;
use App\Services\AiParserService;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Dış kaynak ilanları (yönetici) düz filtre paneli: nereden/nereye il + ilçe, gün ve saat aralığı, kasa, yük türü, fiyat/tonaj
 * aralığı, birden çok durum çipi, × ile kaldırılan rozetler (Osman 2026-10-08: "Ankara'dan Gebze'yi seçtiğimde görmem gerek,
 * saat aralığı seçebilmeliyim, kayan menü olmasın").
 */
class ExternalModuleFiltersTest extends TestCase
{
    use RefreshDatabase;

    private function load(array $attrs): ScrapedLoad
    {
        $scraper = Scraper::query()->firstOrCreate(['source_identifier' => 'notif:grup-a'], ['name' => 'Grup A', 'type' => 'notification', 'is_active' => true]);

        return ScrapedLoad::create(array_merge([
            'scraper_id' => $scraper->id, 'content_hash' => uniqid('h'), 'raw_message' => 'x', 'encrypted_sender_phone' => Crypt::encryptString('5321112233'),
            'status' => 'approved', 'visibility' => 'public', 'published_at' => now(), 'retention_expires_at' => now()->addDays(7), 'vehicle_type' => 'tir',
        ], $attrs));
    }

    private function admin(): User
    {
        Cache::flush();
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['current_role' => 'admin']);
        $admin->syncRoles(['super_admin']);

        return $admin;
    }

    public function test_route_filter_matches_province_and_district_and_swaps(): void
    {
        $this->actingAs($this->admin());
        $this->load(['pickup_location' => 'Ankara Sincan', 'pickup_province_code' => 6, 'pickup_district' => 'Sincan', 'delivery_location' => 'Kocaeli Gebze', 'delivery_province_code' => 41, 'delivery_district' => 'Gebze', 'raw_message' => 'GEBZE-YUKU']);
        $this->load(['pickup_location' => 'Ankara', 'pickup_province_code' => 6, 'delivery_location' => 'Kocaeli İzmit', 'delivery_province_code' => 41, 'delivery_district' => 'İzmit', 'raw_message' => 'IZMIT-YUKU']);
        $this->load(['pickup_location' => 'Kocaeli Gebze', 'pickup_province_code' => 41, 'pickup_district' => 'Gebze', 'delivery_location' => 'Ankara', 'delivery_province_code' => 6, 'raw_message' => 'TERS-YUK']);

        $c = Volt::test('admin.scrapers-center')->set('activeTab', 'published')->set('period', 'all');
        $c->assertSee('3 ilan');
        // Ankara'dan Kocaeli'ye: iki ilan; Gebze ilçesi seçilince tek ilan
        $c->set('fromProvince', '6')->set('toProvince', '41')->assertSee('2 ilan')->assertDontSee('TERS-YUK');
        $c->set('toDistrict', 'Gebze')->assertSee('1 ilan')->assertSee('GEBZE-YUKU')->assertDontSee('IZMIT-YUKU')->assertSee('Nereye: Kocaeli Gebze');
        // ⇄ : Gebze'den Ankara'ya
        $c->call('swapRoute')->assertSee('1 ilan')->assertSee('TERS-YUK')->assertDontSee('GEBZE-YUKU')->assertSee('Nereden: Kocaeli Gebze');
        // İl değişince ilçe sıfırlanır; rozet × ile kalkar
        $c->set('fromProvince', '34')->assertSet('fromDistrict', '')->assertSee('0 ilan');
        $c->call('removeFilter', 'from')->call('removeFilter', 'to')->assertSee('3 ilan');
    }

    public function test_time_range_with_hours_overrides_period_presets(): void
    {
        $this->actingAs($this->admin());
        // created_at toplu atanamaz; doğrudan yazılır
        $this->load(['raw_message' => 'SABAH-YUKU'])->forceFill(['created_at' => now()->setTime(8, 30)])->save();
        $this->load(['raw_message' => 'AKSAM-YUKU'])->forceFill(['created_at' => now()->setTime(20, 15)])->save();
        $this->load(['raw_message' => 'ESKI-YUK'])->forceFill(['created_at' => now()->subDays(10)])->save();

        $c = Volt::test('admin.scrapers-center')->set('activeTab', 'published');
        // Hazır pencere: son 7 gün eskiyi gizler, "Tümü" gösterir
        $c->assertSee('2 ilan')->assertDontSee('ESKI-YUK');
        $c->call('setPeriod', 'all')->assertSee('3 ilan');
        // Saat aralığı: bugün 07:00–12:00 → yalnız sabah ilanı; hazır pencere dikkate alınmaz
        $c->set('from', now()->format('Y-m-d').'T07:00')->set('to', now()->format('Y-m-d').'T12:00')
            ->assertSee('1 ilan')->assertSee('SABAH-YUKU')->assertDontSee('AKSAM-YUKU')->assertSee('Zaman:');
        // Hazır pencereye dönüş özel aralığı siler
        $c->call('setPeriod', '1')->assertSet('from', '')->assertSee('2 ilan');
    }

    public function test_body_goods_price_weight_and_multiple_flags_apply_together(): void
    {
        $this->actingAs($this->admin());
        $this->load(['goods_type' => 'Gıda', 'body_types' => ['frigo'], 'price' => 30000, 'weight' => 20000, 'parse_metadata' => ['urgent' => true]]);
        $this->load(['goods_type' => 'Gıda', 'body_types' => ['tenteli'], 'price' => 1500, 'price_unit' => 'per_ton', 'weight' => 25000]);
        $this->load(['goods_type' => 'İnşaat malzemesi', 'body_types' => null, 'price' => null, 'weight' => null, 'encrypted_sender_phone' => null]);

        $c = Volt::test('admin.scrapers-center')->set('activeTab', 'published')->set('period', 'all');
        $c->set('body', 'frigo')->assertSee('1 ilan')->assertSee('Kasa: Frigo');
        $c->set('body', 'none')->assertSee('1 ilan')->assertSee('Kasa: yazmıyor')->set('body', '');
        $c->set('goods', 'Gıda')->assertSee('2 ilan')->set('goods', '');
        // Ton başı fiyat toplamla karşılaştırılır: 1.500 ₺/t × 25 t = 37.500 ₺
        $c->set('minPrice', '35000')->assertSee('1 ilan')->assertSee('Fiyat: 35.000 – … ₺');
        $c->set('minPrice', '')->set('maxWeight', '22')->assertSee('1 ilan')->assertSee('Tonaj: … – 22 t')->set('maxWeight', '');
        // Çipler birlikte: fiyatlı + acil → 1; telefon yok → 1; ton başı → 1
        $c->call('toggleFlag', 'priced')->assertSee('2 ilan')->call('toggleFlag', 'urgent')->assertSee('1 ilan')->assertSee('Acil');
        $c->call('clearFilters')->assertSee('3 ilan')->assertSet('flags', []);
        $c->call('toggleFlag', 'no_phone')->assertSee('1 ilan')->call('toggleFlag', 'no_phone');
        $c->call('toggleFlag', 'per_ton')->assertSee('1 ilan')->call('removeFilter', 'flag:per_ton')->assertSee('3 ilan');
    }

    public function test_hidden_ai_providers_with_leftover_keys_are_not_in_the_chain(): void
    {
        Settings::set('ai_gemini_key', 'AIza-test');
        Settings::set('ai_cerebras_key', 'csk-leftover');
        Settings::set('ai_openrouter_key', 'sk-or-leftover');
        $parser = app(AiParserService::class);
        $this->assertSame(['gemini'], $parser->chain());
        $this->assertSame(['gemini'], array_keys($parser->providerStatus()));
    }
}
