<?php

namespace Tests\Feature\Admin;

use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Kaynaklar sayfasında arama: sözcükler ayrı aranır, telefonla aranır, üç sekmede kaç eşleşme olduğu görünür (Osman, 2026-10-08). */
class SourcesSearchTest extends TestCase
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

    public function test_words_are_searched_separately_and_hits_per_tab_are_shown(): void
    {
        Cache::flush();
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['current_role' => 'admin']);
        $admin->syncRoles(['super_admin']);
        $this->load(['raw_message' => "📍 ADANADAN ➡️ BATMAN\n- KISA DORSE -\n0532 111 22 33", 'pickup_location' => 'Adana', 'delivery_location' => 'Batman']);
        $this->load(['raw_message' => "📍 ADANADAN ➡️ TEKİRDAĞ (28 TON)\n- KISA DORSE - 13.60 -\n0532 111 22 33", 'pickup_location' => 'Adana', 'delivery_location' => 'Tekirdağ',
            'status' => 'rejected', 'visibility' => 'private', 'published_at' => null]);

        $this->actingAs($admin);
        // Yayında sekmesinde "adana tekirdağ" yok ama reddedilenlerde 1 var; sayaç satırı bunu söyler
        Volt::test('admin.scrapers-center')->set('activeTab', 'published')->set('search', 'adana tekirdağ')
            ->assertSee('reddedilen 1')->assertSee('yayında 0')->assertDontSee('Adana → Batman')
            ->set('activeTab', 'rejected')->assertSee('Tekirdağ');
        // Telefonla arama: boşluklar fark etmez
        Volt::test('admin.scrapers-center')->set('activeTab', 'published')->set('search', '0532 111 22 33')->assertSee('yayında 1')->assertSee('Batman');
        Volt::test('admin.scrapers-center')->set('activeTab', 'published')->set('search', '05321112233')->assertSee('yayında 1');
    }
}
