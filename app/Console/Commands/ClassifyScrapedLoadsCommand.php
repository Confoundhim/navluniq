<?php

namespace App\Console\Commands;

use App\Models\ScrapedLoad;
use App\Support\VehicleClassifier;
use Illuminate\Console\Command;

/**
 * Dış kaynak ilanlarının araç tipini sınıflandırıcıyla (yeniden) hesaplar.
 * Varsayılan: yalnız araç tipi boş olanlar; --all ile yapay zeka dışındaki tüm kayıtlar yeniden değerlendirilir.
 */
class ClassifyScrapedLoadsCommand extends Command
{
    protected $signature = 'scraped-loads:classify {--all : Araç tipi dolu olanları da (yapay zeka kaynaklılar hariç) yeniden sınıflandır}';

    protected $description = 'Dış kaynak ilanlarında araç tipini metin, tonaj, palet ve hacimden çıkarır';

    public function handle(): int
    {
        $query = ScrapedLoad::query();
        if (! $this->option('all')) {
            $query->whereNull('vehicle_type');
        } else {
            $query->where(fn ($q) => $q->whereNull('vehicle_type_source')->orWhere('vehicle_type_source', '!=', 'ai'));
        }

        $updated = 0;
        $total = 0;
        $query->orderBy('id')->chunkById(200, function ($loads) use (&$updated, &$total) {
            foreach ($loads as $load) {
                $total++;
                $r = VehicleClassifier::analyze((string) $load->raw_message, $load->weight ? (int) $load->weight : null);
                $changes = [];
                if ($r['type'] !== null && $r['type'] !== $load->vehicle_type) {
                    $changes['vehicle_type'] = $r['type'];
                    $changes['vehicle_type_source'] = $r['source'];
                }
                if (! $load->weight && $r['weight_kg']) {
                    $changes['weight'] = $r['weight_kg'];
                }
                if ($changes !== []) {
                    $load->forceFill($changes)->save();
                    $updated++;
                }
            }
        });

        $this->info("İncelenen: {$total}, güncellenen: {$updated}");

        return self::SUCCESS;
    }
}
