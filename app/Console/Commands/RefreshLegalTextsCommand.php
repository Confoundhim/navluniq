<?php

namespace App\Console\Commands;

use App\Models\CmsContent;
use Database\Seeders\CmsContractSeeder;
use Illuminate\Console\Command;

/**
 * Beş yasal metni koddaki güncel şablonla yeniden yükler. Metinlerdeki {{COMPANY_*}} yer tutucuları
 * sayfada gösterilirken panelden yönetilen şirket künyesiyle doldurulur; künye değişince metin de değişir.
 */
class RefreshLegalTextsCommand extends Command
{
    protected $signature = 'legal:refresh {--if-stale : Yalnız şirket künyesi yer tutucusu taşımayan (eski biçim) metinler varsa yenile}';

    protected $description = 'Yasal metinleri (KVKK, kullanıcı sözleşmesi, gizlilik, mesafeli satış, iade) güncel şablonla yeniler';

    public function handle(): int
    {
        if ($this->option('if-stale') && ! self::isStale()) {
            $this->info('Yasal metinler güncel biçimde; değişiklik yok.');

            return self::SUCCESS;
        }

        (new CmsContractSeeder)->run();
        $this->info('Yasal metinler güncel şablonla yenilendi.');

        return self::SUCCESS;
    }

    /**
     * Herhangi bir yasal metin boşsa ya da künye taşıyan metinlerin hiçbiri yer tutucu içermiyorsa
     * (künyenin metne gömüldüğü eski seed) true. İptal politikası künye içermediğinden ona bakılmaz.
     */
    public static function isStale(): bool
    {
        $withToken = 0;
        foreach (CmsContractSeeder::KEYS as $key) {
            $html = (string) CmsContent::getVal($key, '');
            if (trim($html) === '') {
                return true;
            }
            // Künye metne gömülü (eski seed): sabit adres ya da eski vergi numarası geçiyorsa yenile.
            if (preg_match('/Cevizlidere|6301481858/u', $html)) {
                return true;
            }
            // Dış kaynak ilanı maddesi eklenmemiş eski metin: yenile.
            if (in_array($key, ['contract_kvkk', 'contract_terms'], true) && (! str_contains($html, 'data-clause="dis-kaynak"') || ! str_contains($html, 'data-clause="bildirim-tercihi"'))) {
                return true;
            }
            // Dış kaynak ilan bilgilerinin gizliliği (premium sürücü paylaşamaz; WhatsApp/Facebook grupları) eklenmemişse yenile.
            if ($key === 'contract_terms' && ! str_contains($html, 'data-clause="dis-kaynak-gizlilik"')) {
                return true;
            }
            if ($key === 'contract_kvkk' && ! str_contains($html, 'Facebook grupları dahil')) {
                return true;
            }
            $withToken += str_contains($html, '{{COMPANY_NAME}}') ? 1 : 0;
        }

        return $withToken === 0;
    }
}
