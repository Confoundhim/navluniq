<?php

namespace App\Support;

use App\Services\AiParserService;
use App\Services\LoadIntakeService;
use App\Services\LoadStandardizer;

/**
 * Altın ölçüm seti: gruplarda görülen yazım biçimlerinden türetilmiş, uydurma numaralı örnek mesajlar ve kuralın vermesi
 * gereken sonuç. Yapay zeka çağrılmaz; yalnız kural katmanı ölçülür. Her kod değişikliğinde test bu setin tamamının
 * doğru çözülmesini ister (`tests/Feature/Services/GoldenSetTest`), `php artisan ilan:dogruluk` raporu yazar.
 *
 * Örnekler: `resources/data/altin-set.json` (elle yazılmış zor biçimler) + burada üretilen kalıp × yer × yük bileşimleri.
 * Yeni bir yazım biçimi görülüp düzeltildiğinde JSON'a bir örnek eklenir; böylece bir daha bozulmaz.
 */
final class IntakeBenchmark
{
    /** @var list<array{text:string, label:string}> yer yazımı → beklenen konum (il ya da il ilçe) */
    private const PLACES = [
        ['text' => 'Mersin', 'label' => 'Mersin'],
        ['text' => 'Trabzon', 'label' => 'Trabzon'],
        ['text' => 'Ç.KALE ÇAN', 'label' => 'Çanakkale Çan'],
        ['text' => 'K.MARAŞ', 'label' => 'Kahramanmaraş'],
        ['text' => 'Şanlı Urfa', 'label' => 'Şanlıurfa'],
        ['text' => 'izmir aliağa', 'label' => 'İzmir Aliağa'],
        ['text' => 'ANKARA KAZAN', 'label' => 'Ankara Kahramankazan'],
        ['text' => 'Kocaeli Gebze', 'label' => 'Kocaeli Gebze'],
        ['text' => 'Diyarbakr', 'label' => 'Diyarbakır'],
        ['text' => 'Samsun Çarşamba', 'label' => 'Samsun Çarşamba'],
        ['text' => 'Bursa M.Kemalpaşa', 'label' => 'Bursa Mustafakemalpaşa'],
        ['text' => 'Adapazarı', 'label' => 'Sakarya'],
        ['text' => 'Antakya', 'label' => 'Hatay'],
        ['text' => 'Konya Ereğli', 'label' => 'Konya Ereğli'],
        ['text' => 'Iğdır', 'label' => 'Iğdır'],
        ['text' => 'GANTEP', 'label' => 'Gaziantep'],
        ['text' => 'Tekirdağ Çorlu', 'label' => 'Tekirdağ Çorlu'],
        ['text' => 'ISPARTA Ş.KARAAĞAÇ', 'label' => 'Isparta Şarkikaraağaç'],
    ];

    /** Tek sözcüklü yerler: "-dan/-ya" ekli kalıplarda kullanılır ("Mersinden Trabzona"). */
    private const SUFFIX_PLACES = [
        ['text' => 'Mersin', 'label' => 'Mersin'], ['text' => 'Trabzon', 'label' => 'Trabzon'], ['text' => 'Ankara', 'label' => 'Ankara'],
        ['text' => 'Bursa', 'label' => 'Bursa'], ['text' => 'Konya', 'label' => 'Konya'], ['text' => 'Samsun', 'label' => 'Samsun'],
        ['text' => 'Gebze', 'label' => 'Kocaeli Gebze'], ['text' => 'Aliağa', 'label' => 'İzmir Aliağa'], ['text' => 'Adana', 'label' => 'Adana'],
        ['text' => 'Kayseri', 'label' => 'Kayseri'], ['text' => 'Erzurum', 'label' => 'Erzurum'], ['text' => 'Mecitözü', 'label' => 'Çorum Mecitözü'],
    ];

    /** @var list<array{text:string, vehicle:string, body:?string}> */
    private const VEHICLES = [
        ['text' => 'tenteli tır', 'vehicle' => 'tir', 'body' => 'tenteli'],
        ['text' => 'damperli tır', 'vehicle' => 'tir', 'body' => 'damperli'],
        ['text' => 'frigo tır', 'vehicle' => 'tir', 'body' => 'frigo'],
        ['text' => 'kamyonet', 'vehicle' => 'kamyonet', 'body' => null],
        ['text' => '10 teker damperli', 'vehicle' => '10_teker_kamyon', 'body' => 'damperli'],
        ['text' => 'kırkayak', 'vehicle' => 'kirkayak', 'body' => null],
        ['text' => 'panelvan', 'vehicle' => 'panelvan', 'body' => null],
    ];

    /** @var list<array{text:string, goods:string}> yük sözcüğü → kategori etiketi */
    private const GOODS = [
        ['text' => 'gübre', 'goods' => 'Gübre'],
        ['text' => 'buğday', 'goods' => 'Tarım ürünü'],
        ['text' => 'kömür', 'goods' => 'Kömür'],
        ['text' => 'mermer', 'goods' => 'Mermer / taş'],
        ['text' => 'palet', 'goods' => 'Paletli yük'],
        ['text' => 'hurda', 'goods' => 'Hurda'],
    ];

    private const PHONES = ['0532 111 22 33', '0533 444 55 66', '05341112233', '+90 535 222 33 44', '0 536 777 88 99'];

    /** @return list<array{id:string, message:string, expect:array<string, mixed>, note?:string}> */
    public static function cases(): array
    {
        $cases = [];
        $path = resource_path('data/altin-set.json');
        if (is_file($path)) {
            foreach ((array) json_decode((string) file_get_contents($path), true) as $case) {
                if (is_array($case) && isset($case['message'], $case['expect'])) {
                    $cases[] = $case + ['id' => $case['id'] ?? 'json-'.count($cases)];
                }
            }
        }

        return array_merge($cases, self::generated());
    }

    /**
     * Kalıp × yer × araç × yük bileşimleri: aynı bilgi farklı yazımlarla (ekli, tireli, emojili, büyük harfli, satır satır).
     *
     * @return list<array{id:string, message:string, expect:array<string, mixed>}>
     */
    public static function generated(): array
    {
        $out = [];
        $ton = [24, 12, 3, 18, 30];
        $templates = [
            'tire' => fn ($p, $d, $v, $g, $t, $ph) => "{$p['text']} - {$d['text']} {$t} ton {$g['text']} {$v['text']} {$ph}",
            'buyuk' => fn ($p, $d, $v, $g, $t, $ph) => TurkishText::upper("{$p['text']} {$d['text']} {$g['text']} yükü {$t} tn {$v['text']} acil")."\n{$ph}",
            'emoji' => fn ($p, $d, $v, $g, $t, $ph) => "📍 {$p['text']}\n🏁 {$d['text']}\n🚚 {$v['text']}\n📦 {$g['text']} {$t} ton\n☎️ {$ph}",
            'cikis' => fn ($p, $d, $v, $g, $t, $ph) => "{$p['text']} çıkışlı {$d['text']} varışlı {$t} ton {$g['text']} {$v['text']} lazım {$ph}",
            'ok' => fn ($p, $d, $v, $g, $t, $ph) => "{$p['text']} ➡️ {$d['text']}\n{$g['text']} {$t} ton\n{$v['text']}\n{$ph}",
            'yukler' => fn ($p, $d, $v, $g, $t, $ph) => "{$p['text']} yükler\n{$d['text']} iner\n{$t} ton {$g['text']} {$v['text']}\n{$ph}",
            'etiketli' => fn ($p, $d, $v, $g, $t, $ph) => "Kalkış: {$p['text']}\nVarış: {$d['text']}\nYük: {$g['text']} {$t} ton\nAraç: {$v['text']}\nTel: {$ph}",
            'buyuk_tire' => fn ($p, $d, $v, $g, $t, $ph) => TurkishText::upper("{$p['text']} - {$d['text']} {$t} ton {$g['text']} {$v['text']}").' '.$ph,
        ];
        $i = 0;
        foreach ($templates as $name => $tpl) {
            foreach (self::PLACES as $k => $p) {
                $d = self::PLACES[($k + 7) % count(self::PLACES)];
                $v = self::VEHICLES[$i % count(self::VEHICLES)];
                $g = self::GOODS[$i % count(self::GOODS)];
                $t = $ton[$i % count($ton)];
                $ph = self::PHONES[$i % count(self::PHONES)];
                $out[] = ['id' => "uret-{$name}-{$k}", 'message' => $tpl($p, $d, $v, $g, $t, $ph), 'expect' => self::expect($p, $d, $v, $g, $t, $ph)];
                $i++;
            }
        }
        // Ekli yazım: "Mersinden Trabzona", "Gebzeden Aliağaya" (yalnız tek sözcüklü yerler)
        foreach (self::SUFFIX_PLACES as $k => $p) {
            $d = self::SUFFIX_PLACES[($k + 5) % count(self::SUFFIX_PLACES)];
            $v = self::VEHICLES[$i % count(self::VEHICLES)];
            $g = self::GOODS[$i % count(self::GOODS)];
            $t = $ton[$i % count($ton)];
            $ph = self::PHONES[$i % count(self::PHONES)];
            $dat = self::backVowel($p['text']) ? 'dan' : 'den';
            $ya = (preg_match('/[aeıioöuü]$/u', TurkishCities::lower($d['text'])) ? 'y' : '').(self::backVowel($d['text']) ? 'a' : 'e');
            $out[] = ['id' => "uret-ekli-{$k}", 'message' => "{$p['text']}{$dat} {$d['text']}{$ya} {$t} ton {$g['text']} {$v['text']} {$ph}", 'expect' => self::expect($p, $d, $v, $g, $t, $ph)];
            $i++;
        }

        return $out;
    }

    /** Son ünlü kalın mı (ünlü uyumu: -dan/-a, değilse -den/-e)? */
    private static function backVowel(string $word): bool
    {
        preg_match_all('/[aeıioöuü]/u', TurkishCities::lower($word), $m);
        $last = $m[0] === [] ? 'a' : end($m[0]);

        return in_array($last, ['a', 'ı', 'o', 'u'], true);
    }

    private static function expect(array $p, array $d, array $v, array $g, int $t, string $ph): array
    {
        return ['ads' => 1, 'routes' => [[$p['label'], $d['label']]], 'vehicle' => $v['vehicle'], 'body' => $v['body'] ? [$v['body']] : null,
            'goods' => $g['goods'], 'weight' => $t * 1000, 'phone' => Phone::normalizeContact($ph)];
    }

    /**
     * Tüm seti çalıştırır.
     *
     * @return array{total:int, passed:int, failed:list<array{id:string, errors:list<string>, message:string}>}
     */
    public static function run(?array $cases = null): array
    {
        $cases ??= self::cases();
        $failed = [];
        foreach ($cases as $case) {
            $errors = self::evaluate($case);
            if ($errors !== []) {
                $failed[] = ['id' => (string) $case['id'], 'errors' => $errors, 'message' => (string) $case['message']];
            }
        }

        return ['total' => count($cases), 'passed' => count($cases) - count($failed), 'failed' => $failed];
    }

    /**
     * Tek örneği kural katmanından geçirir; beklenenle farkları döndürür (boş liste = doğru).
     *
     * expect alanları: filtered (no_phone|not_load|no_pickup), ads (ilan sayısı), routes ([[kalkış, varış], …] il ya da "il ilçe"),
     * vehicle, body (kasa listesi, alt küme), goods (kategori etiketi), count (araç adedi), weight (kg), price (₺), phone (5xxxxxxxxx).
     * Alanlar ilk ilana bakar; "each": true ile her ilana uygulanır.
     *
     * @return list<string>
     */
    public static function evaluate(array $case): array
    {
        $message = (string) $case['message'];
        $e = (array) $case['expect'];
        $errors = [];
        if (isset($e['filtered'])) {
            $ok = match ($e['filtered']) {
                'no_phone' => ! LoadIntakeService::hasPhone($message),
                'not_load' => ! LoadIntakeService::looksLikeLoad($message) || Lexicon::isNotLoad($message),
                'no_pickup' => ! empty(LoadIntakeService::splitSegments($message)[0]['pickup_missing']),
                default => false,
            };

            return $ok ? [] : ["elenmeliydi ({$e['filtered']}) ama ilan sayıldı"];
        }
        $segments = LoadIntakeService::splitSegments($message);
        if (isset($e['ads']) && count($segments) !== (int) $e['ads']) {
            $errors[] = 'ilan sayısı: beklenen '.$e['ads'].', bulunan '.count($segments);
        }
        $parser = app(AiParserService::class);
        $standardizer = app(LoadStandardizer::class);
        $ads = [];
        foreach ($segments as $segment) {
            $parsed = $parser->parseCheap($segment['text']);
            if (isset($segment['series'])) {
                $parsed['pickup_location'] = $segment['series']['pickup'];
                $parsed['delivery_location'] = $segment['series']['delivery'];
            }
            $std = $standardizer->standardize($segment['text'], $parsed);
            $ads[] = $std + ['phones' => $segment['phones']];
        }
        foreach ((array) ($e['routes'] ?? []) as $i => $route) {
            if (! isset($ads[$i])) {
                $errors[] = "ilan {$i}: yok";

                continue;
            }
            foreach ([0 => 'pickup', 1 => 'delivery'] as $j => $side) {
                $want = TurkishLocations::resolve((string) $route[$j]);
                $got = TurkishLocations::resolve((string) ($ads[$i][$side.'_location'] ?? ''));
                $label = $ads[$i][$side.'_location'] ?? '—';
                if ($want === null) {
                    $errors[] = "ilan {$i} {$side}: beklenen '{$route[$j]}' katalogda yok";
                } elseif ($got === null || (int) $got['province_code'] !== (int) $want['province_code']) {
                    $errors[] = "ilan {$i} {$side}: beklenen '{$route[$j]}', bulunan '{$label}'";
                } elseif (($want['district'] ?? null) !== null && ($got['district'] ?? null) !== $want['district']) {
                    $errors[] = "ilan {$i} {$side} ilçe: beklenen '{$route[$j]}', bulunan '{$label}'";
                }
            }
        }
        $targets = ! empty($e['each']) ? $ads : array_slice($ads, 0, 1);
        foreach ($targets as $i => $ad) {
            foreach (['vehicle' => 'vehicle_type', 'goods' => 'goods_type', 'count' => 'vehicle_count', 'weight' => 'weight', 'price' => 'price'] as $key => $field) {
                if (array_key_exists($key, $e) && $e[$key] !== null && (string) ($ad[$field] ?? '') !== (string) $e[$key]) {
                    $errors[] = "ilan {$i} {$key}: beklenen '{$e[$key]}', bulunan '".($ad[$field] ?? '—')."'";
                }
            }
            if (! empty($e['body'])) {
                $missing = array_diff((array) $e['body'], (array) ($ad['body_types'] ?? []));
                if ($missing !== []) {
                    $errors[] = "ilan {$i} kasa: eksik ".implode(',', $missing).' (bulunan '.implode(',', (array) ($ad['body_types'] ?? [])).')';
                }
            }
            if (! empty($e['phone']) && ($ad['phones'][0] ?? null) !== $e['phone']) {
                $errors[] = "ilan {$i} telefon: beklenen {$e['phone']}, bulunan ".($ad['phones'][0] ?? '—');
            }
            // stops: sıralı teslim noktaları (il düzeyinde, sırayla)
            if (! empty($e['stops'])) {
                $got = array_map(fn ($s) => TurkishLocations::resolve((string) $s)['province'] ?? $s, (array) ($ad['delivery_stops'] ?? []));
                $want = array_map(fn ($s) => TurkishLocations::resolve((string) $s)['province'] ?? $s, (array) $e['stops']);
                if ($got !== $want) {
                    $errors[] = "ilan {$i} teslim noktaları: beklenen ".implode('+', $want).', bulunan '.(implode('+', $got) ?: '—');
                }
            }
        }

        return $errors;
    }
}
