<?php

namespace App\Console\Commands;

use App\Models\ScrapedLoad;
use App\Services\LoadStandardizer;
use Illuminate\Console\Command;

/**
 * Dış kaynak ilanlarının araç tipini sınıflandırıcıyla (yeniden) hesaplar.
 * Varsayılan: yalnız araç tipi boş olanlar; --all ile yapay zeka dışındaki tüm kayıtlar yeniden değerlendirilir.
 */
class ClassifyScrapedLoadsCommand extends Command
{
    protected $signature = 'scraped-loads:classify {--all : Bütün kayıtları yeniden standartlaştır (yönetici düzenlemeleri korunur)}';

    protected $description = 'Dış kaynak ilanlarını standartlaştırır: il/ilçe, yük kategorisi, araç tipi, tonaj, fiyat';

    public function handle(): int
    {
        $query = ScrapedLoad::query()->where('status', '!=', 'rejected');
        if (! $this->option('all')) {
            $query->where(fn ($q) => $q->whereNull('vehicle_type')->orWhereNull('pickup_province_code')->orWhereNull('delivery_province_code')->orWhereNull('parse_metadata'));
        }

        $updated = 0;
        $total = 0;
        $standardizer = app(LoadStandardizer::class);
        $query->orderBy('id')->chunkById(200, function ($loads) use (&$updated, &$total, $standardizer) {
            foreach ($loads as $load) {
                $total++;
                if ($standardizer->restandardize($load)) {
                    $updated++;
                }
            }
        });

        $this->info("İncelenen: {$total}, güncellenen: {$updated}");

        return self::SUCCESS;
    }
}
