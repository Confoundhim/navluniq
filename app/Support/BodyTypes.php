<?php

namespace App\Support;

/**
 * Kasa / dorse tipi taksonomisi ve serbest metinden kasa çıkarımı.
 *
 * Araç sınıfı (tır, kamyon…) ile kasa tipi ayrı boyutlardır: "13.60 tenteli" = tır + tenteli.
 * Sektör dili (şoför ve yük vereninin ortak anladığı kurallar):
 *  - "13.60" tek başına: damper hariç her dorse (tenteli, kapalı, açık, frigo).
 *  - "sadece frigo / damper / sal açık / tenteli": yalnız o kasa.
 *  - "her türlü dorseye uygun", "fark etmez": kasa belirtilmez (hepsi uygun).
 *  - "dökme yük": yalnız damperli; "dökme, damper ile 13.60 açık sal da uyar": damperli + açık.
 *  - "kasalı" (meyve/sebze): kapalı, tenteli ya da frigo.
 *  - Kasa yazmayan ilanda yükten çıkarım (kemik, kömür, dökme üzüm → damper; donuk gıda → frigo…).
 * Kapalı ve tenteli ayrı tiplerdir (birbirine karıştırılmaz).
 */
final class BodyTypes
{
    /**
     * Anahtar → etiket ve geçerli araç sınıfları (VehicleTypes::CLASSES). Sektör matrisi (2026-09 revizyonu):
     *  - Panelvan: kapalı, frigo.
     *  - Kamyonet: tenteli, kapalı, açık, frigo (+ liftli).
     *  - Kamyon ve kırkayak: tenteli, kapalı, açık, frigo, damperli (+ liftli).
     *  - TIR: tenteli, kapalı, açık, frigo, damperli, silobas, lowbed (+ liftli); dorse boyu kısa / 13.60 ayrı boyut.
     * "liftli" bir kasa cinsi değil ek özelliktir (kuyruk lifti): ilan isterse şoförün aracında olmalıdır.
     */
    public const TYPES = [
        'tenteli' => ['label' => 'Tenteli', 'short' => 'Tenteli', 'classes' => ['tir', 'kirkayak', 'kamyon', 'kamyonet']],
        'kapali' => ['label' => 'Kapalı kasa', 'short' => 'Kapalı', 'classes' => ['tir', 'kirkayak', 'kamyon', 'kamyonet', 'panelvan']],
        'acik' => ['label' => 'Açık kasa (sal)', 'short' => 'Açık', 'classes' => ['tir', 'kirkayak', 'kamyon', 'kamyonet']],
        'frigo' => ['label' => 'Frigo (soğutmalı)', 'short' => 'Frigo', 'classes' => ['tir', 'kirkayak', 'kamyon', 'kamyonet', 'panelvan']],
        'damperli' => ['label' => 'Damperli', 'short' => 'Damper', 'classes' => ['tir', 'kirkayak', 'kamyon']],
        'silobas' => ['label' => 'Silobas', 'short' => 'Silobas', 'classes' => ['tir']],
        'lowbed' => ['label' => 'Lowbed', 'short' => 'Lowbed', 'classes' => ['tir']],
        'liftli' => ['label' => 'Liftli (kuyruk lifti)', 'short' => 'Liftli', 'classes' => ['tir', 'kirkayak', 'kamyon', 'kamyonet'], 'feature' => true],
        'kisa_dorse' => ['label' => 'Kısa dorse', 'short' => 'Kısa dorse', 'classes' => ['tir'], 'length' => true],
        'uzun_dorse' => ['label' => 'Uzun dorse (13.60)', 'short' => '13.60', 'classes' => ['tir'], 'length' => true],
    ];

    /** Kasa cinsleri (uzunluk ve ek özellik değil). */
    public const KINDS = ['tenteli', 'kapali', 'acik', 'frigo', 'damperli', 'silobas', 'lowbed'];

    /** Ek özellikler: kasa cinsinin yanında istenir (liftli tenteli gibi). */
    public const FEATURES = ['liftli'];

    /** "13.60" tek başına: damper hariç dorseler. */
    public const NOT_DAMPER = ['tenteli', 'kapali', 'acik', 'frigo'];

    /** "Kasalı" meyve/sebze: kapalı, tenteli ya da frigo. */
    public const CRATED = ['kapali', 'tenteli', 'frigo'];

    public const LOAD_KINDS = ['komple' => 'Komple yük', 'parca' => 'Parça yük'];

    /** Yük kategorisinden (GoodsCatalog anahtarı) kasa çıkarımı; kasa yazmayan ilanlarda kullanılır. */
    private const GOODS_BODIES = [
        'komur' => ['damperli'], 'maden_dokme' => ['damperli'], 'tuz' => ['damperli'], 'gubre' => ['damperli'], 'hurda' => ['damperli'],
        'insaat' => ['damperli', 'acik'], 'lastik' => ['damperli', 'acik'], 'kemik' => ['damperli'],
        'is_makinesi' => ['lowbed', 'acik'],
        'donuk_gida' => ['frigo'], 'tavuk_yumurta' => ['frigo'], 'meyve_sebze' => ['kapali', 'tenteli', 'frigo'],
        'kereste' => ['acik', 'tenteli'], 'demir_celik' => ['acik', 'tenteli'], 'mermer_tas' => ['acik'], 'arac_tasima' => ['acik'],
        'orman_kagit' => ['acik', 'damperli'], 'tarim' => ['damperli', 'tenteli'],
        'palet' => ['kapali', 'tenteli'], 'koli' => ['kapali', 'tenteli'], 'gida' => ['kapali', 'tenteli'], 'tekstil' => ['kapali', 'tenteli'],
        'kagit' => ['kapali', 'tenteli'], 'kagit_pecete' => ['kapali', 'tenteli'], 'plastik' => ['kapali', 'tenteli'],
        'beyaz_esya' => ['kapali'], 'mobilya' => ['kapali'], 'elektronik' => ['kapali'], 'cam' => ['acik', 'kapali'], 'makine' => ['acik', 'tenteli'],
        'kimyasal' => ['kapali', 'tenteli'],
    ];

    /** Kasa sözcükleri (VehicleClassifier::normalize çıktısında aranır). */
    private const PATTERNS = [
        'tenteli' => '/\btent(?:e|eli|elidir|eliler|elik|en|ene|eneli|enli|ali)?\b|\btnt\b|\btentli\b|\bmega\s+tente\w*/',
        'kapali' => '/\bkapali\b/',
        'acik' => '/\bacik(?:ta|da|a|i|tir|dir)?\b|\bsal\b|\btentesiz\b|\bplatform\b/',
        'frigo' => '/\bfr[iı]?[iı]?go\w*|\bfirgo\w*|\bfirigo\w*|\btermo\s?k[iı]ng?\w*|\bthermo\s?king\w*|\btermokin\w*|\bsogutucu\w*|\bsogutmali\b|\bsoguk\s+hava\w*|\bfrigolu\b/',
        'damperli' => '/\bdamper\w*|\bdanper\w*/',
        'silobas' => '/\bsilobas\w*|\bsilo\s?bas\w*/',
        'lowbed' => '/\blow\s?bed\w*|\blowbet\w*|\blobed\w*|\blovbed\w*|\blovbet\w*|\blowboy\w*/',
        'liftli' => '/\blift(?:li|i)?\b|\bkuyruk\s+lift\w*|\bhidrolik\s+lift\w*/',
        'kisa_dorse' => '/\bkisa\s+(?:dorse|tir|arac|kasa)\b/',
        'uzun_dorse' => '/(?<![\d.,])(?:13[.,\/\- ]?60|1360)(?![\d])|\buzun\s+(?:dorse|arac|tir)\b|\bmega\b/',
    ];

    private const ANY_PATTERN = '/\bher\s+turlu\s+(?:dorse|arac|kasa)\w*|\bfark\s?etmez\b|\bfarketmez\b|\bhepsi\s+olur\b|\bher\s+dorse\w*|\bne\s+olursa\b|\bdorse\s+farketmez\b/';

    private const BULK_PATTERN = '/\bdokme\b|\bdokum\b/';

    private const CRATED_PATTERN = '/\bkasali\b|\bkasa\s+(?:meyve|sebze|domates|uzum|elma|narenciye|portakal|mandalina|limon|kiraz|seftali|kayisi|erik|incir)\w*/';

    public static function isValid(?string $key): bool
    {
        return $key !== null && isset(self::TYPES[$key]);
    }

    public static function label(?string $key): string
    {
        return self::TYPES[$key ?? '']['label'] ?? (string) $key;
    }

    public static function short(?string $key): string
    {
        return self::TYPES[$key ?? '']['short'] ?? (string) $key;
    }

    /** @return array<string, string> anahtar → etiket */
    public static function labels(): array
    {
        return array_map(fn ($t) => $t['label'], self::TYPES);
    }

    public static function isLength(string $key): bool
    {
        return (bool) (self::TYPES[$key]['length'] ?? false);
    }

    public static function isFeature(string $key): bool
    {
        return (bool) (self::TYPES[$key]['feature'] ?? false);
    }

    /** Listedeki ek özellikler (liftli). */
    public static function featuresOf(array $list): array
    {
        return array_values(array_filter($list, fn ($k) => self::isFeature($k)));
    }

    /** Verilen araç sınıfında geçerli kasa anahtarları (form ve filtre kutuları). */
    public static function forClass(?string $class): array
    {
        return array_keys(array_filter(self::TYPES, fn ($t) => $class === null || in_array($class, $t['classes'], true)));
    }

    /** Liste temizliği: geçerli, tekrarsız, sabit sırada. */
    public static function clean(mixed $list): array
    {
        $out = [];
        foreach ((array) $list as $k) {
            if (is_string($k) && isset(self::TYPES[$k]) && ! in_array($k, $out, true)) {
                $out[] = $k;
            }
        }
        usort($out, fn ($a, $b) => array_search($a, array_keys(self::TYPES), true) <=> array_search($b, array_keys(self::TYPES), true));

        return $out;
    }

    /** Yalnız kasa cinsleri (uzunluk ve ek özellik girdileri atılır). */
    public static function kindsOf(array $list): array
    {
        return array_values(array_filter($list, fn ($k) => ! self::isLength($k) && ! self::isFeature($k)));
    }

    /**
     * Kısa insan etiketi: "Tenteli", "Damperli + Açık", "13.60 · damper hariç", "Fark etmez".
     */
    public static function summary(?array $list, bool $withLength = true): ?string
    {
        $list = self::clean($list ?? []);
        if ($list === []) {
            return null;
        }
        $kinds = self::kindsOf($list);
        $lengths = array_values(array_filter($list, fn ($k) => self::isLength($k)));
        $parts = [];
        if ($withLength) {
            foreach ($lengths as $l) {
                $parts[] = self::short($l);
            }
        }
        if ($kinds !== []) {
            $notDamper = array_diff(self::NOT_DAMPER, $kinds) === [] && ! in_array('damperli', $kinds, true) && count($kinds) === count(self::NOT_DAMPER);
            $crated = array_diff(self::CRATED, $kinds) === [] && count($kinds) === count(self::CRATED);
            if ($notDamper) {
                $parts[] = 'damper hariç';
            } elseif ($crated) {
                $parts[] = 'Kapalı / Tenteli / Frigo';
            } else {
                $parts[] = implode(' / ', array_map(fn ($k) => self::short($k), $kinds));
            }
        }
        foreach (self::featuresOf($list) as $f) {
            $parts[] = self::short($f);
        }

        return implode(' · ', $parts);
    }

    /**
     * Serbest metinden kasa çıkarımı.
     *
     * @param  string  $norm  VehicleClassifier::normalize çıktısı
     * @param  string|null  $goodsKey  GoodsCatalog anahtarı (yükten çıkarım için)
     * @return array{types: list<string>, source: ?string, any: bool, evidence: list<string>}
     *                                                                                        source: keyword (açık kasa sözcüğü), lexicon (sözlük), goods (yükten), null (bulunamadı)
     */
    public static function detect(string $norm, ?string $goodsKey = null): array
    {
        $r = self::detectKinds($norm, $goodsKey);
        // Ek özellik (liftli) kasa cinsinden bağımsızdır; bulunduysa listeye eklenir
        foreach (self::FEATURES as $feature) {
            if (preg_match(self::PATTERNS[$feature], $norm, $m)) {
                $r['types'] = self::clean(array_merge($r['types'], [$feature]));
                $r['evidence'][] = "özellik: {$m[0]}";
                $r['source'] ??= 'keyword';
            }
        }

        return $r;
    }

    private static function detectKinds(string $norm, ?string $goodsKey): array
    {
        $found = [];
        $evidence = [];
        foreach (self::PATTERNS as $key => $pattern) {
            if (! self::isFeature($key) && preg_match($pattern, $norm, $m)) {
                $found[] = $key;
                $evidence[] = "kasa: {$m[0]}";
            }
        }
        // "mega": uzun ve tenteli; "tentesiz": açık ama tenteli değil
        if (preg_match('/\bmega\b/', $norm) && ! in_array('tenteli', $found, true)) {
            $found[] = 'tenteli';
        }
        if (preg_match('/\btentesiz\b/', $norm)) {
            $found = array_values(array_diff($found, ['tenteli']));
        }
        // Sözlük: yöneticinin öğrettiği sözcük ("sal dorse" → acik, "kemik" → damperli)
        $lexicon = Lexicon::matchBody($norm);
        if ($lexicon !== null) {
            $evidence[] = "sözlük: {$lexicon['term']}";
        }

        $any = preg_match(self::ANY_PATTERN, $norm) === 1;
        $bulk = preg_match(self::BULK_PATTERN, $norm) === 1;
        $crated = preg_match(self::CRATED_PATTERN, $norm) === 1;
        $kinds = self::kindsOf($found);
        $lengths = array_values(array_filter($found, fn ($k) => self::isLength($k)));

        if ($kinds !== []) {
            // Açık kasa sözcüğü: yazılanlar geçerli. Dökme yazılmışsa damper de uygundur ("damper ile 13.60 açık sal uygundur").
            if ($bulk && ! in_array('damperli', $kinds, true)) {
                $kinds[] = 'damperli';
                $evidence[] = 'dökme: damper eklendi';
            }

            return ['types' => self::clean(array_merge($lengths, $kinds)), 'source' => 'keyword', 'any' => false, 'evidence' => $evidence];
        }
        if ($any) {
            return ['types' => self::clean($lengths), 'source' => 'keyword', 'any' => true, 'evidence' => array_merge($evidence, ['her türlü dorse'])];
        }
        if ($bulk) {
            return ['types' => self::clean(array_merge($lengths, ['damperli'])), 'source' => 'keyword', 'any' => false, 'evidence' => array_merge($evidence, ['dökme: damper'])];
        }
        if ($crated) {
            return ['types' => self::clean(array_merge($lengths, self::CRATED)), 'source' => 'keyword', 'any' => false, 'evidence' => array_merge($evidence, ['kasalı: kapalı/tenteli/frigo'])];
        }
        if ($lexicon !== null && $lexicon['types'] !== []) {
            return ['types' => self::clean(array_merge($lengths, $lexicon['types'])), 'source' => 'lexicon', 'any' => false, 'evidence' => $evidence];
        }
        if (in_array('uzun_dorse', $lengths, true)) {
            // "13.60" tek başına: damper hariç her dorse
            return ['types' => self::clean(array_merge($lengths, self::NOT_DAMPER)), 'source' => 'keyword', 'any' => false, 'evidence' => array_merge($evidence, ['13.60: damper hariç'])];
        }
        if ($goodsKey !== null && isset(self::GOODS_BODIES[$goodsKey])) {
            return ['types' => self::clean(array_merge($lengths, self::GOODS_BODIES[$goodsKey])), 'source' => 'goods', 'any' => false, 'evidence' => array_merge($evidence, ["yükten: {$goodsKey}"])];
        }
        if ($lengths !== []) {
            return ['types' => self::clean($lengths), 'source' => 'keyword', 'any' => false, 'evidence' => $evidence];
        }

        return ['types' => [], 'source' => null, 'any' => false, 'evidence' => $evidence];
    }

    /**
     * Şoförün aracı (kasa cinsi + dorse uzunluğu) ilanın kasa listesine uyar mı?
     * İlan kasa belirtmemişse ya da şoför aracının kasasını girmemişse gizlenmez.
     */
    public static function vehicleFits(?string $driverBody, ?string $driverLength, ?array $loadBodies, ?bool $driverHasLift = null): bool
    {
        $loadBodies = self::clean($loadBodies ?? []);
        if ($loadBodies === []) {
            return true;
        }
        $kinds = self::kindsOf($loadBodies);
        if ($kinds !== [] && $driverBody !== null && ! in_array($driverBody, $kinds, true)) {
            return false;
        }
        // İlan lift istiyor: şoför "liftim yok" dediyse uymaz; belirtmediyse gizlenmez
        if ($driverHasLift === false && in_array('liftli', $loadBodies, true)) {
            return false;
        }
        if ($driverLength === 'kisa' && in_array('uzun_dorse', $loadBodies, true) && ! in_array('kisa_dorse', $loadBodies, true)) {
            return false;
        }
        if ($driverLength === 'uzun' && in_array('kisa_dorse', $loadBodies, true) && ! in_array('uzun_dorse', $loadBodies, true)) {
            return false;
        }

        return true;
    }

    public const TRAILER_LENGTHS = ['kisa' => 'Kısa dorse', 'uzun' => 'Uzun dorse (13.60)'];

    /**
     * Yük biçimi: komple (aracın tamamı) / parça (kısmi) / null.
     */
    public static function detectLoadKind(string $norm, ?int $weightKg = null, ?int $vehicleCount = null): ?string
    {
        if (preg_match('/\b(?:parca\s+yuk\w*|parsiyel|parsiyal|parsiyl|kismi\s+yuk\w*|grupaj|yanina\s+(?:yuk|alinir|alir)|bosluk\w*\s+(?:olan|var)|ek\s+yuk\w*|parca\b)/', $norm)) {
            return 'parca';
        }
        if (preg_match('/\b(?:komple|tirlik|araclik|full\s+(?:tir|arac|yuk)|ftl|tam\s+arac|komple\s+yuk\w*)\b/', $norm)) {
            return 'komple';
        }
        if (($vehicleCount ?? 0) >= 2) {
            return 'komple';
        }
        if (preg_match('/\b(\d{1,2})\s*(?:palet|paletlik|pallet)\b/', $norm, $m) && (int) $m[1] <= 12) {
            return 'parca';
        }
        if ($weightKg !== null && $weightKg >= 18000) {
            return 'komple';
        }

        return null;
    }

    /**
     * İstenen araç adedi: "2 yer" (2 ayrı araç), "5 araç", "3 tır", "iki tır". 1 ise null.
     */
    public static function detectVehicleCount(string $norm): ?int
    {
        $words = ['bir' => 1, 'iki' => 2, 'uc' => 3, 'dort' => 4, 'bes' => 5, 'alti' => 6, 'yedi' => 7, 'sekiz' => 8, 'dokuz' => 9, 'on' => 10];
        $n = null;
        if (preg_match('/(?<![\d.,])(\d{1,2})\s*(?:yer|yere|yerde|nokta|noktaya)\b/', $norm, $m)) {
            $n = (int) $m[1];
        } elseif (preg_match('/(?<![\d.,])(\d{1,2})\s*(?:adet\s+)?(?:arac|araclik|tir|kamyon|kamyonet|kirkayak|dorse|cekici)\b/', $norm, $m)) {
            $n = (int) $m[1];
        } elseif (preg_match('/\b('.implode('|', array_keys($words)).')\s+(?:adet\s+)?(?:arac|tir|kamyon|kamyonet|kirkayak)\b/', $norm, $m)) {
            $n = $words[$m[1]];
        }

        return $n !== null && $n >= 2 && $n <= 30 ? $n : null;
    }
}
