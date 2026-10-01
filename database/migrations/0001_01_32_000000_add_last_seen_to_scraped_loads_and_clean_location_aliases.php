<?php

use App\Models\AiLexicon;
use App\Support\Lexicon;
use App\Support\Settings;
use App\Support\TurkishLocations;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-10-01 teşhisi (Osman + Engin Abi): (1) öğrenilmiş konum takma adları kataloğu eziyordu ("ankara" → "İzmir Torbalı");
 * bilinen il/ilçe adına bağlanmış her takma ad silinir, son 14 günün ilanları ham mesajdan yeniden konumlanır
 * (scraped-loads:relocate-force, ayarla tetiklenir). (2) Her gün yeniden paylaşılan ilan "7 gün önce" diye eskiyip düşüyordu;
 * last_seen_at / sighting_count ile tekrar görülen ilan tazelenir ve listede öne çıkar.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('scraped_loads', 'last_seen_at')) {
            Schema::table('scraped_loads', function (Blueprint $table): void {
                $table->timestamp('last_seen_at')->nullable()->index()->after('published_at');
            });
        }
        if (! Schema::hasColumn('scraped_loads', 'sighting_count')) {
            Schema::table('scraped_loads', function (Blueprint $table): void {
                $table->unsignedInteger('sighting_count')->default(1)->after('last_seen_at');
            });
        }
        DB::table('scraped_loads')->whereNull('last_seen_at')->update(['last_seen_at' => DB::raw('COALESCE(published_at, created_at)')]);

        // Kataloğa karşı yazılmış konum takma adları: terim katalogda birebir çözülüyorsa (il ya da ilçe adı) silinir.
        $removed = [];
        try {
            foreach (AiLexicon::query()->where('kind', 'location')->get() as $row) {
                $term = (string) $row->term;
                if ($term !== '' && TurkishLocations::resolveCatalog($term) !== null) {
                    $removed[] = $term.' → '.$row->canonical.' ('.$row->source.')';
                    $row->delete();
                }
            }
        } catch (Throwable $e) {
            Log::warning('Konum sözlüğü temizliği atlandı: '.$e->getMessage());
        }
        Lexicon::flush();
        if ($removed !== []) {
            Log::warning('Kataloğu ezen '.count($removed).' konum takma adı silindi', ['entries' => array_slice($removed, 0, 50)]);
        }
        // Son 14 günün ilanları ham mesajdan yeniden konumlanır (zamanlayıcı 5 dk'da bir, parça parça; bitince ayar silinir).
        Settings::set('scraper_relocate_force_until', now()->addDays(3)->toDateTimeString());
        Settings::set('scraper_relocate_force_cursor', '0');
        Settings::set('scraper_relocate_force_note', count($removed).' takma ad silindi');
    }

    public function down(): void
    {
        // Sütunlar kalır; takma adlar geri getirilmez.
    }
};
