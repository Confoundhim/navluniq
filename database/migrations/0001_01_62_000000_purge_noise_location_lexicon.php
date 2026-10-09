<?php

use App\Models\AiLexicon;
use App\Support\Lexicon;
use App\Support\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * 2026-10-09 canlı döküm teşhisi: konum sözlüğünde "yüklemeli → İzmir Torbalı", "açık / kapalı → Denizli Tavas", "sabah / tenteli →
 * Kocaeli Kartepe", "sarar → Aydın Didim" gibi genel sözcükler yer takma adı olarak öğrenilmişti; 7 günlük kuyruktaki 5.200 adayın
 * 1.000'inde hayali il/ilçe vardı. Genel sözcüklü (Lexicon::LOCATION_NOISE) konum girdileri silinir, son 14 günün ilanları ham
 * mesajdan yeniden konumlanır (scraped-loads:relocate-force). Sözlük yüklenirken aynı süzgeç artık kalıcı uygulanır.
 */
return new class extends Migration
{
    public function up(): void
    {
        $removed = [];
        try {
            foreach (AiLexicon::query()->where('kind', 'location')->get() as $row) {
                $term = Lexicon::normalize((string) $row->term);
                if ($term === '' || Lexicon::isLocationNoise($term)) {
                    $removed[] = $row->term.' → '.$row->canonical.' ('.$row->source.'/'.$row->status.')';
                    $row->delete();
                }
            }
        } catch (Throwable $e) {
            Log::warning('Genel sözcüklü konum takma adı temizliği atlandı: '.$e->getMessage());
        }
        Lexicon::flush();
        if ($removed !== []) {
            Log::warning('Genel sözcüklü '.count($removed).' konum takma adı silindi', ['entries' => array_slice($removed, 0, 100)]);
        }
        // Son 14 günün ilanları ham mesajdan yeniden konumlanır (zamanlayıcı 5 dk'da bir, parça parça; bitince ayar silinir).
        Settings::set('scraper_relocate_force_until', now()->addDays(3)->toDateTimeString());
        Settings::set('scraper_relocate_force_cursor', '0');
        Settings::set('scraper_relocate_force_note', count($removed).' genel sözcüklü takma ad silindi (2026-10-09)');
    }

    public function down(): void
    {
        // Takma adlar geri getirilmez.
    }
};
