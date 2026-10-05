<?php

namespace Tests\Feature\Intake;

use App\Models\IntakeEvent;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Support\FailedJobSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kolona sığmayan metin kaydı düşürmez (canlı 2026-10-05: "1406 Data too long" ile 19 kuyruk işi başarısız olmuştu).
 * SQLite genişlik denetlemediğinden kesme davranışı modelden okunarak doğrulanır.
 */
class ColumnWidthTest extends TestCase
{
    use RefreshDatabase;

    public function test_scraped_load_long_labels_are_trimmed_to_column_width(): void
    {
        $scraper = Scraper::create(['name' => str_repeat('Çok Uzun Grup Adı ', 30), 'type' => 'notification', 'source_identifier' => 'wa:'.str_repeat('x', 400), 'is_active' => true]);
        $this->assertSame(255, mb_strlen($scraper->name));
        $this->assertSame(255, mb_strlen($scraper->source_identifier));

        $load = ScrapedLoad::create([
            'scraper_id' => $scraper->id,
            'raw_message' => str_repeat('İstanbul Ankara yük var 0532 000 00 00 ', 3000),
            'pickup_location' => str_repeat('İstanbul Çekmeköy ', 40),
            'delivery_location' => str_repeat('Ankara Çankaya ', 40),
            'pickup_district' => str_repeat('Çekmeköy', 20),
            'goods_type' => str_repeat('Paletli yük ', 40),
            'vehicle_type' => str_repeat('tir', 30),
            'vehicle_type_source' => 'template_with_a_long_name',
            'route_key' => str_repeat('5320000000|istanbul|ankara|', 20),
            'status' => 'parsed_success',
        ]);
        $load->refresh();
        $this->assertSame(255, mb_strlen($load->pickup_location));
        $this->assertSame(255, mb_strlen($load->delivery_location));
        $this->assertSame(80, mb_strlen($load->pickup_district));
        $this->assertSame(120, mb_strlen($load->goods_type)); // setGoodsTypeAttribute zaten 120'ye keser
        $this->assertSame(48, mb_strlen($load->vehicle_type));
        $this->assertSame(16, mb_strlen($load->vehicle_type_source));
        $this->assertSame(191, mb_strlen($load->route_key));
        $this->assertGreaterThan(65535, mb_strlen($load->raw_message)); // ham mesaj kesilmez (MEDIUMTEXT)
    }

    public function test_intake_event_fields_are_trimmed_per_column(): void
    {
        $event = IntakeEvent::record('failed', [
            'source_name' => str_repeat('Grup ', 60),
            'title' => str_repeat('Başlık ', 60),
            'excerpt' => str_repeat('metin ', 100),
            'reason' => 'PDOException: '.str_repeat('x', 300),
            'ip' => str_repeat('1', 60),
        ]);
        $event->refresh();
        $this->assertSame(160, mb_strlen($event->source_name));
        $this->assertSame(255, mb_strlen($event->title));
        $this->assertSame(300, mb_strlen($event->excerpt));
        $this->assertSame(120, mb_strlen($event->reason));
        $this->assertSame(45, mb_strlen($event->ip));
        $this->assertSame('failed', $event->status);
    }

    public function test_failed_job_summary_names_the_column(): void
    {
        $exception = "PDOException: SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'pickup_location' at row 1 in /var/www/navluniq/vendor/x.php:123\nStack trace:\n#0 ...\n\nNext Illuminate\\Database\\QueryException: ...";
        $this->assertSame('Kolon "pickup_location" için veri çok uzun (1406)', FailedJobSummary::line($exception));
        $this->assertSame('Mesaj işlenemedi: sağlayıcı yanıt vermedi', FailedJobSummary::line("RuntimeException: Mesaj işlenemedi: sağlayıcı yanıt vermedi in /var/www/app/Jobs/X.php:40\nStack"));
        $this->assertSame('', FailedJobSummary::line(null));
    }
}
