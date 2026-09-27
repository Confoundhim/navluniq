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

    protected $description = 'Dış kaynak ilanlarını standartlaştırır: il/ilçe, yük kategorisi, araç tipi, kasa tipi, yük biçimi, tonaj, fiyat';

    public function handle(): int
    {
        $query = ScrapedLoad::query()->where('status', '!=', 'rejected');
        if (! $this->option('all')) {
            // Araç/il boş olanlar ve kasa tipi ile yük biçimi henüz hesaplanmamış olanlar (yayındakiler dahil)
            $query->where(fn ($q) => $q->whereNull('vehicle_type')->orWhereNull('pickup_province_code')->orWhereNull('delivery_province_code')->orWhereNull('parse_metadata')
                ->orWhere(fn ($b) => $b->whereNull('body_types')->whereNull('load_kind')));
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

        // Konum kuralları geliştikçe (kısaltmalar, ayrık yazımlar, ilçe tablosu) son 30 günün kural ile çözülmüş ilanları ham
        // mesajdan yeniden konumlanır; yanlış ile gitmiş ya da boş kalmış kalkış/varış düzelir. En çok 90 sn.
        $started = microtime(true);
        $relocated = 0;
        $checked = 0;
        ScrapedLoad::query()->where('status', '!=', 'rejected')->where('created_at', '>=', now()->subDays(30))->where('ai_status', '!=', 'done')
            ->orderBy('id')->chunkById(200, function ($loads) use (&$relocated, &$checked, $standardizer, $started): bool {
                foreach ($loads as $load) {
                    if (microtime(true) - $started > 90) {
                        return false;
                    }
                    $checked++;
                    if ($standardizer->relocateFromRaw($load)) {
                        $relocated++;
                    }
                }

                return true;
            });
        $this->info("Konum yeniden çözülen: {$relocated} / {$checked}");

        return self::SUCCESS;
    }
}
