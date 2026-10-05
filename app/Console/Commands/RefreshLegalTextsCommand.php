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
    protected $signature = 'legal:refresh {--if-stale : Yalnız eski kalan metinleri yenile (yönetici elle düzenlediği metne dokunmaz)}';

    protected $description = 'Yasal metinleri (KVKK, kullanıcı sözleşmesi, gizlilik, mesafeli satış, iade) güncel şablonla yeniler';

    public function handle(): int
    {
        if ($this->option('if-stale')) {
            $stale = self::staleKeys();
            if ($stale === []) {
                $this->info('Yasal metinler güncel biçimde; değişiklik yok.');

                return self::SUCCESS;
            }
            foreach ($stale as $key) {
                CmsContractSeeder::seedKey($key);
            }
            $this->info('Yenilenen yasal metin: '.implode(', ', $stale));

            return self::SUCCESS;
        }

        (new CmsContractSeeder)->run();
        $this->info('Yasal metinler güncel şablonla yenilendi.');

        return self::SUCCESS;
    }

    /** En az bir yasal metin eski ya da eksikse true (sağlık ekranı ve --if-stale bunu kullanır). */
    public static function isStale(): bool
    {
        return self::staleKeys() !== [];
    }

    /**
     * Yenilenmesi gereken metinlerin anahtarları. Bir metin şu hallerde eskidir:
     *  - boş;
     *  - seed izi var ve metin seed'in yazdığı haliyle duruyor (yönetici dokunmamış) ama koddaki şablon değişmiş;
     *  - seed izi yok (bu izleme eklenmeden önce yazılmış) ya da eski biçim belirteçleri eksik.
     * Yöneticinin elle değiştirdiği (seed iziyle eşleşmeyen) güncel biçimdeki metne dokunulmaz.
     *
     * @return list<string>
     */
    public static function staleKeys(): array
    {
        $stale = [];
        foreach (CmsContractSeeder::KEYS as $key) {
            $html = (string) CmsContent::getVal($key, '');
            if (trim($html) === '') {
                $stale[] = $key;

                continue;
            }

            $mark = (string) CmsContent::getVal(CmsContractSeeder::SEED_MARK_PREFIX.$key, '');
            if ($mark === '') {
                // Seed izi yok: izleme eklenmeden önce yazılmış metin, bir kez güncel şablonla yenilenir.
                $stale[] = $key;

                continue;
            }

            [$templateHash, $writtenHash] = array_pad(explode('|', $mark, 2), 2, '');
            if ($writtenHash === sha1($html)) {
                // Yönetici dokunmamış: şablon değiştiyse yenile.
                if ($templateHash !== CmsContractSeeder::templateHash($key)) {
                    $stale[] = $key;
                }

                continue;
            }

            if (self::missesRequiredMarkers($key, $html)) {
                $stale[] = $key;
            }
        }

        return $stale;
    }

    /** Elle düzenlenmiş metinde bile bulunması gereken asgari belirteçler (eski biçim ya da eksik madde). */
    private static function missesRequiredMarkers(string $key, string $html): bool
    {
        // Künye metne gömülü (eski seed): sabit adres ya da eski vergi numarası geçiyorsa yenile.
        if (preg_match('/Cevizlidere|6301481858/u', $html)) {
            return true;
        }
        if (in_array($key, ['contract_kvkk', 'contract_terms', 'contract_privacy', 'contract_distance_sale'], true) && ! str_contains($html, '{{COMPANY_NAME}}')) {
            return true;
        }
        if (in_array($key, ['contract_kvkk', 'contract_terms'], true) && (! str_contains($html, 'data-clause="dis-kaynak"') || ! str_contains($html, 'data-clause="bildirim-tercihi"'))) {
            return true;
        }
        if ($key === 'contract_kvkk' && (! str_contains($html, 'gönderen adı saklanmaz') || ! str_contains($html, 'data-clause="yurt-disi-aktarim"'))) {
            return true;
        }
        if ($key === 'contract_terms' && (! str_contains($html, '{{AUTO_APPROVAL_HOURS}}') || ! str_contains($html, 'Madde 7A')
            || ! str_contains($html, 'data-clause="dis-kaynak-gizlilik"') || ! str_contains($html, 'data-clause="surum-onay"'))) {
            return true;
        }
        if ($key === 'contract_distance_sale' && ! str_contains($html, '{{PREMIUM_LEAD_MINUTES}}')) {
            return true;
        }
        if ($key === 'contract_cancellation' && (! str_contains($html, '{{OFFER_PAYMENT_HOURS}}') || ! str_contains($html, 'data-clause="iptal-asamalari"'))) {
            return true;
        }

        return false;
    }
}
