<?php

namespace App\Console\Commands;

use App\Services\AiParserService;
use App\Services\LoadIntakeService;
use App\Services\LoadStandardizer;
use App\Services\LocalClassifier;
use App\Support\Lexicon;
use App\Support\TextPrep;
use App\Support\TurkishLocations;
use Illuminate\Console\Command;

/**
 * WhatsApp grup dışa aktarımını (.txt) ya da satır satır mesaj dosyasını yapay zeka çağırmadan kural hattından geçirir;
 * hangi mesajın neden elendiğini, kaç ilanın kuralla tam çözüldüğünü ve çözülemeyen yer/araç sözcüklerini raporlar.
 * Jargon sözlüğünü ve ön eleme kurallarını geliştirmek için kullanılır.
 */
class AnalyzeIntakeSamplesCommand extends Command
{
    protected $signature = 'intake:analyze {file : WhatsApp dışa aktarımı ya da boş satırla ayrılmış mesaj dosyası} {--limit=0 : En fazla bu kadar mesaj} {--show=15 : Örnek satır sayısı}';

    protected $description = 'Örnek grup mesajlarını kural hattından geçirip ön eleme ve çözümleme raporu üretir (yapay zeka çağrılmaz)';

    public function handle(AiParserService $parser, LoadStandardizer $standardizer, LocalClassifier $classifier): int
    {
        $path = (string) $this->argument('file');
        if (! is_readable($path)) {
            $this->error("Dosya okunamadı: {$path}");

            return self::FAILURE;
        }
        $messages = self::parseExport((string) file_get_contents($path));
        if (($limit = (int) $this->option('limit')) > 0) {
            $messages = array_slice($messages, 0, $limit);
        }
        $show = max(1, (int) $this->option('show'));
        $this->info('Mesaj: '.count($messages));

        $stats = ['phone_missing' => 0, 'lexicon_not_load' => 0, 'no_logistics_signal' => 0, 'candidate' => 0, 'segments' => 0,
            'rule_full' => 0, 'route_missing' => 0, 'vehicle_missing' => 0, 'local_low' => 0, 'local_high' => 0, 'duplicate_text' => 0];
        $seen = [];
        $unresolved = [];
        $samples = ['route_missing' => [], 'no_logistics_signal' => [], 'rule_full' => [], 'vehicle_missing' => []];
        foreach ($messages as $raw) {
            $norm = LoadIntakeService::normalizeText($raw);
            if (isset($seen[$norm])) {
                $stats['duplicate_text']++;

                continue;
            }
            $seen[$norm] = true;
            if (! LoadIntakeService::hasPhone($raw)) {
                $stats['phone_missing']++;

                continue;
            }
            if (Lexicon::isNotLoad($raw) || TextPrep::isForeignScript($raw) || LoadIntakeService::isNotLoadPattern($raw)) {
                $stats['lexicon_not_load']++;

                continue;
            }
            if (! LoadIntakeService::looksLikeLoad($raw)) {
                $stats['no_logistics_signal']++;
                $samples['no_logistics_signal'][] = $raw;

                continue;
            }
            $stats['candidate']++;
            $local = $classifier->score($raw);
            if ($local !== null) {
                $local >= 0.9 ? $stats['local_high']++ : ($local < 0.15 ? $stats['local_low']++ : null);
            }
            foreach (LoadIntakeService::splitSegments($raw) as $segment) {
                $stats['segments']++;
                $parsed = $parser->parseCheap($segment['text']);
                if (($parsed['success'] ?? false) !== true && $segment['phones'] === []) {
                    $stats['segment_phone_missing'] = ($stats['segment_phone_missing'] ?? 0) + 1;

                    continue;
                }
                if (($parsed['success'] ?? false) !== true) {
                    $stats['route_missing']++;
                    $samples['route_missing'][] = $segment['text'];
                    foreach (AiParserService::connectorMatches($segment['text']) as $m) {
                        foreach ([$m['pickup'], $m['delivery']] as $t) {
                            if (is_string($t) && $t !== '' && TurkishLocations::resolve($t) === null) {
                                $key = Lexicon::normalize($t);
                                $unresolved[$key] = ($unresolved[$key] ?? 0) + 1;
                            }
                        }
                    }

                    continue;
                }
                $std = $standardizer->standardize($segment['text'], $parsed);
                if ($std['vehicle_type'] === null) {
                    $stats['vehicle_missing']++;
                    $samples['vehicle_missing'][] = $segment['text'];
                }
                $intl = (array) ($std['metadata']['international'] ?? []);
                if (($std['pickup_province_code'] || ! empty($intl['pickup'])) && ($std['delivery_province_code'] || ! empty($intl['delivery']))) {
                    $stats['rule_full']++;
                    $samples['rule_full'][] = $segment['text'];
                } else {
                    $stats['route_missing']++;
                    $samples['route_missing'][] = $segment['text'];
                }
            }
        }

        $this->table(['Ölçüt', 'Adet'], [
            ['Aynı metin tekrarı (elenir)', $stats['duplicate_text']],
            ['Telefon yok (elenir)', $stats['phone_missing']],
            ['İlan değil: sözlük / sabit kalıp (boş araç, şoför ilanı, reklam) / yabancı alfabe (elenir)', $stats['lexicon_not_load']],
            ['Lojistik işaret yok (elenir)', $stats['no_logistics_signal']],
            ['Aday mesaj', $stats['candidate']],
            ['  → ayrılan ilan parçası', $stats['segments']],
            ['  → kural tam çözdü (telefon + kalkış/varış ili)', $stats['rule_full']],
            ['  → rota çözülemedi (yapay zeka gerekir)', $stats['route_missing']],
            ['  → parçada numara yok', $stats['segment_phone_missing'] ?? 0],
            ['  → araç tipi metinde yok (yapay zeka da bilemez; tonaj/yükten çıkarım)', $stats['vehicle_missing']],
            ['  → yerel sınıflandırıcı ≥ %90', $stats['local_high']],
            ['  → yerel sınıflandırıcı < %15', $stats['local_low']],
        ]);
        $aiShare = $stats['segments'] > 0 ? (int) round(100 * ($stats['segments'] - $stats['rule_full']) / $stats['segments']) : 0;
        $this->info("Yapay zekaya gitmesi gereken parça oranı (kural eksik bırakınca kipi): ~%{$aiShare}");

        arsort($unresolved);
        if ($unresolved !== []) {
            $this->line('');
            $this->info('Çözülemeyen yer adları (sözlüğe aday):');
            foreach (array_slice($unresolved, 0, 40, true) as $term => $n) {
                $this->line(sprintf('  %3d × %s', $n, $term));
            }
        }
        foreach (['no_logistics_signal' => 'Lojistik işaret bulunamayan (telefonlu) mesajlar', 'route_missing' => 'Rota çözülemeyen parçalar', 'vehicle_missing' => 'Araç tipi çözülemeyen parçalar'] as $key => $title) {
            if ($samples[$key] === []) {
                continue;
            }
            $this->line('');
            $this->info($title.' (ilk '.$show.'):');
            foreach (array_slice($samples[$key], 0, $show) as $t) {
                $this->line('  · '.mb_substr(str_replace("\n", ' ⏎ ', $t), 0, 200));
            }
        }

        return self::SUCCESS;
    }

    /**
     * WhatsApp dışa aktarımı ("20.09.2026 15:06 - Ahmet: mesaj", "[20/09/2026, 15:06:12] Ahmet: mesaj") ya da
     * boş satırla ayrılmış düz metin. Sistem satırları ("mesajlar uçtan uca şifrelidir", "<Medya dahil edilmedi>") atılır.
     *
     * @return list<string>
     */
    public static function parseExport(string $content): array
    {
        $content = str_replace(["\r\n", "\r", "\u{200E}", "\u{202F}"], ["\n", "\n", '', ' '], $content);
        $header = '/^\[?\d{1,2}[.\/]\d{1,2}[.\/]\d{2,4},?\s+\d{1,2}:\d{2}(?::\d{2})?\]?\s*-?\s*([^:\n]{1,80}):\s?(.*)$/u';
        $messages = [];
        $current = null;
        $isExport = (bool) preg_match(substr($header, 0, -1).'mu', $content); // çok satırlı kipte herhangi bir satır başlık mı?
        if (! $isExport) {
            return array_values(array_filter(array_map('trim', preg_split('/\n[ \t]*\n+/u', $content) ?: [])));
        }
        $stamp = '/^\[?\d{1,2}[.\/]\d{1,2}[.\/]\d{2,4},?\s+\d{1,2}:\d{2}(?::\d{2})?\]?\s*-?\s*/u'; // tarih-saat ile başlayan her satır sınırdır
        foreach (explode("\n", $content) as $line) {
            if (preg_match($header, $line, $m)) {
                if ($current !== null) {
                    $messages[] = $current;
                }
                $current = trim($m[2]);
            } elseif (preg_match($stamp, $line)) {
                // Sistem satırı ("X kişisini ekledi", "ayrıldı", "kullanıcı adını değiştirdi"): mesaj değildir, önceki mesaja da eklenmez.
                if ($current !== null) {
                    $messages[] = $current;
                }
                $current = null;
            } elseif ($current !== null) {
                $current .= "\n".$line;
            }
        }
        if ($current !== null) {
            $messages[] = $current;
        }
        $skip = '/uçtan uca şifreli|end-to-end encrypted|<Medya dahil edilmedi>|<Media omitted>|Bu mesaj silindi|This message was deleted|gruba katıldı|joined using|grubu oluşturdu|created group/iu';

        return array_values(array_filter(array_map('trim', $messages), fn ($t) => $t !== '' && ! preg_match($skip, $t)));
    }
}
