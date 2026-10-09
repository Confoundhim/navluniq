<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\Admin\ScrapedLoadExportController;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/** Analiz dökümü: bekleyen adaylar kuralın okuduğu alanlar, karar puanı ve engel nedeniyle iner; telefonlar maskelidir (Osman, 2026-10-09). */
class ScrapedLoadExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_streams_masked_jsonl_with_decision_and_blocker(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['current_role' => 'admin']);
        $admin->syncRoles(['super_admin']);
        $scraper = Scraper::create(['name' => 'Grup A', 'type' => 'notification', 'source_identifier' => 'notif:a', 'is_active' => true]);
        $pending = ScrapedLoad::create([
            'scraper_id' => $scraper->id, 'content_hash' => 'h1', 'status' => 'parsed_partial', 'visibility' => 'private',
            'raw_message' => 'Ankara İzmir 24 ton palet 0532 111 22 33 / 0312 444 55 66 / 444 1 234 fiyat 2.450 artı kdv',
            'encrypted_sender_phone' => Crypt::encryptString('5321112233'), 'pickup_location' => 'Ankara', 'pickup_province_code' => 6,
            'delivery_location' => 'İzmir', 'delivery_province_code' => 35, 'parse_metadata' => ['warnings' => ['vehicle_missing']],
        ]);
        ScrapedLoad::create([
            'scraper_id' => $scraper->id, 'content_hash' => 'h2', 'status' => 'approved', 'visibility' => 'public', 'published_at' => now(),
            'raw_message' => 'YAYINDA-OLAN 0533 222 33 44', 'encrypted_sender_phone' => Crypt::encryptString('5332223344'), 'vehicle_type' => 'tir',
        ]);

        $this->actingAs(User::factory()->create(['current_role' => 'driver']))->get(route('admin.scrapers.export'))->assertStatus(302); // yönetici olmayan panele giremez

        $response = $this->actingAs($admin)->get(route('admin.scrapers.export', ['kapsam' => 'queue', 'gun' => 7]));
        $response->assertOk();
        $this->assertStringContainsString('.jsonl.gz', (string) $response->headers->get('content-disposition'));
        $lines = array_values(array_filter(explode("\n", gzdecode($response->streamedContent()))));
        $this->assertCount(2, $lines, 'başlık + bekleyen tek aday; yayındaki satır bekleyen kapsamına girmez');
        $meta = json_decode($lines[0], true);
        $this->assertSame('queue', $meta['_meta']['scope']);
        $row = json_decode($lines[1], true);
        $this->assertSame($pending->id, $row['id']);
        $this->assertSame('Ankara', $row['rule']['pickup']);
        $this->assertSame('0532 *** ** 33', $row['rule']['phone']);
        $this->assertNotEmpty($row['blocker']); // araç yok + düşük puan: engel nedeni her durumda yazılır
        $this->assertIsFloat($row['decision']['score']);
        // Ham mesajda hiçbir telefon açık kalmaz; fiyat/tonaj sayıları dokunulmaz
        $this->assertStringNotContainsString('111 22 33', $row['raw_message']);
        $this->assertStringNotContainsString('444 55 66', $row['raw_message']);
        $this->assertStringNotContainsString('444 1 234', $row['raw_message']);
        $this->assertStringContainsString('0532 *** ** 33', $row['raw_message']);
        $this->assertStringContainsString('0312 *** ** 66', $row['raw_message']);
        $this->assertStringContainsString('444 * ** **', $row['raw_message']);
        $this->assertStringContainsString('24 ton', $row['raw_message']);
        $this->assertStringContainsString('2.450 artı kdv', $row['raw_message']);

        // Tüm kapsam: iki satır + başlık
        $all = $this->actingAs($admin)->get(route('admin.scrapers.export', ['kapsam' => 'all']));
        $this->assertCount(3, array_values(array_filter(explode("\n", gzdecode($all->streamedContent())))));
    }

    public function test_mask_text_keeps_short_numbers_and_handles_glued_formats(): void
    {
        $this->assertSame('tır 0532 *** ** 11 kdv 2300', ScrapedLoadExportController::maskText('tır 05321112211 kdv 2300'));
        $this->assertSame('ara 0532 *** ** 33', ScrapedLoadExportController::maskText('ara +90 532 111 22 33'));
        $this->assertSame('tel 0532 *** ** 33', ScrapedLoadExportController::maskText('tel (0532) 111-22-33'));
        $this->assertSame('13.60 tenteli 2.100+kdv 28000 kg yarın 09:30', ScrapedLoadExportController::maskText('13.60 tenteli 2.100+kdv 28000 kg yarın 09:30'));
    }
}
