<?php

namespace App\Support;

/**
 * Serbest metinden (WhatsApp ilanı) araç tipini çıkaran puanlama tabanlı sınıflandırıcı.
 *
 * Katmanlar, güçlüden zayıfa:
 *  1. Araç adı: tır, çekici, dorse, kırkayak, 10/8/6 teker, kamyon, kamyonet, panelvan, minivan, otomobil, marka adları.
 *  2. Kasa/üstyapı ipucu: tenteli, kapalı kasa, açık kasa, frigo, damper, mega, lowbed, silobas, tanker, konteyner…
 *     Bunlar tek başına sınıfı kesinleştirmez; aile (tır / kamyon / hafif) belirler, tonaj alt tipi seçer.
 *  3. Kapasite: tonaj (aralık, "t", "tn", "tonluk", yazıyla sayı), palet adedi, hacim (m³).
 *  4. Yük biçimi: "komple", "tırlık", "full" → tır.
 *
 * Sözcükler kök+ek biçiminde eşlenir ("tırla", "tırlar", "kamyonetle", "dorseli"); "getir", "bitir" gibi
 * içinde geçen sözcükler eşleşmez. Sonuç: tip, kaynak (keyword|hint|weight|pallet|volume|komple) ve güven.
 */
final class VehicleClassifier
{
    /** Araç adları: düzenli ifade (ASCII'ye indirgenmiş, sözcük sınırlı) → [tip, puan]. */
    private const NOUNS = [
        // TIR ailesi
        '/\btir(?:lar|lari|lik|la|i|a|e|in|im|dan|da|imiz|iniz|lara|larla|lardan|larda)?\b/' => ['tir', 10],
        '/\bcekici(?:ler|li|yle|si|ye|den|de|m|miz)?\b/' => ['tir', 9],
        '/\bdorse(?:ler|li|yle|si|ye|den|de|m|miz|lik)?\b/' => ['tir', 9],
        '/\bkirk\s?ayak(?:lar|la|li|i|a|in)?\b/' => ['kirkayak', 10],
        '/\b(?:4|dort)\s?dingil(?:li)?\b/' => ['kirkayak', 8],
        // Kamyon alt tipleri
        '/\b(?:10|on)\s?(?:teker|tekerlek|tekerli|tekerlekli)\b/' => ['10_teker_kamyon', 10],
        '/\b(?:8|sekiz)\s?(?:teker|tekerlek|tekerli|tekerlekli)\b/' => ['8_teker_kamyon', 10],
        '/\b(?:6|alti)\s?(?:teker|tekerlek|tekerli|tekerlekli)\b/' => ['6_teker_kamyon', 10],
        '/\b(?:3|uc)\s?dingil(?:li)?\b/' => ['10_teker_kamyon', 7],
        // Hafif ticari
        '/\bkamyonet(?:ler|le|i|e|in|im|ten|te|lik|ler[ei])?\b/' => ['kamyonet', 10],
        '/\b(?:pikap|pick\s?up|pickup)\b/' => ['kamyonet', 9],
        '/\buzun\s+(?:sasi|sase|panelvan|panel\s?van)\b/' => ['uzun_panelvan', 10],
        '/\b(?:panelvan|panel\s?van)\s+uzun\b/' => ['uzun_panelvan', 10],
        '/\b(?:maxi|l3h2|l4h2|l3|l4)\b/' => ['uzun_panelvan', 7],
        '/\borta\s+(?:panelvan|panel\s?van)\b/' => ['orta_panelvan', 10],
        '/\b(?:panelvan|panel\s?van)(?:la|i|a|in|lar|dan)?\b/' => ['orta_panelvan', 8],
        '/\b(?:transit|sprinter|crafter|ducato|boxer|jumper|master|daily|iveco)\b/' => ['orta_panelvan', 8],
        '/\bminivan(?:la|i|a|lar)?\b/' => ['minivan', 10],
        '/\b(?:doblo|caddy|connect|kangoo|fiorino|combo|partner|berlingo|expert|vito|bipper|nemo|scudo|dokker|courier)\b/' => ['minivan', 9],
        '/\bhafif\s+ticari\b/' => ['minivan', 7],
        '/\b(?:otomobil|binek|sedan|hatchback)(?:la|yla|le|i|a|lar)?\b/' => ['otomobil', 9],
    ];

    /**
     * Kasa/üstyapı ipuçları: ailenin tipleri ve tonaj yoksa varsayılan tip.
     * family: bu ipucunun geçerli olduğu tipler; default: tonaj/başka ipucu yoksa seçilen tip.
     */
    private const HINTS = [
        '/\btent(?:e|eli|elidir|eliler|esiz|elik)?\b/' => ['family' => ['tir', 'kirkayak', '10_teker_kamyon', '8_teker_kamyon', '6_teker_kamyon'], 'default' => 'tir', 'score' => 6],
        '/\b(?:mega|lowbed|low\s?bed|lowbet|lobed|silobas|silo\s?bas|tanker|konteyn[ie]r|platform|jumbo\s+dorse|13[.,]60?)\b/' => ['family' => ['tir'], 'default' => 'tir', 'score' => 6],
        '/\bfrigo(?:rifik|lu|dur)?\b|\bsogutucu(?:lu)?\b|\bsogutmali\b/' => ['family' => ['tir', '10_teker_kamyon', '8_teker_kamyon', '6_teker_kamyon', 'kamyonet', 'orta_panelvan'], 'default' => 'tir', 'score' => 3],
        '/\bdamper(?:li|le|i)?\b/' => ['family' => ['tir', 'kirkayak', '10_teker_kamyon', '8_teker_kamyon', '6_teker_kamyon'], 'default' => '10_teker_kamyon', 'score' => 3],
        '/\bkapali\s+kasa\b/' => ['family' => ['tir', '10_teker_kamyon', '8_teker_kamyon', '6_teker_kamyon', 'kamyonet', 'uzun_panelvan', 'orta_panelvan'], 'default' => 'kamyonet', 'score' => 3],
        '/\bacik\s+kasa\b/' => ['family' => ['10_teker_kamyon', '8_teker_kamyon', '6_teker_kamyon', 'kamyonet'], 'default' => 'kamyonet', 'score' => 3],
        '/\b(?:komple|tirlik|full\s+tir|full\s+arac|full\s+yuk|ftl)\b/' => ['family' => ['tir', 'kirkayak', '10_teker_kamyon'], 'default' => 'tir', 'score' => 2],
        '/\b(?:jumbo)\b/' => ['family' => ['tir', 'uzun_panelvan'], 'default' => 'uzun_panelvan', 'score' => 2],
        // "araba" nakliye dilinde her araç için kullanılır; yalnız tonaj/başka ipucu yoksa otomobil sayılır.
        '/\baraba(?:yla|la|si|m|miz|lar)?\b/' => ['family' => ['tir', 'kirkayak', '10_teker_kamyon', '8_teker_kamyon', '6_teker_kamyon', 'kamyonet', 'uzun_panelvan', 'orta_panelvan', 'minivan', 'otomobil'], 'default' => 'otomobil', 'score' => 1],
    ];

    private const WORD_NUMBERS = [
        'bir' => 1, 'iki' => 2, 'uc' => 3, 'dort' => 4, 'bes' => 5, 'alti' => 6, 'yedi' => 7, 'sekiz' => 8, 'dokuz' => 9,
        'on' => 10, 'yirmi' => 20, 'otuz' => 30, 'kirk' => 40, 'elli' => 50,
    ];

    /**
     * @return array{type:?string, source:?string, confidence:?string, weight_kg:?int, evidence:list<string>}
     */
    public static function analyze(string $text, ?int $weightKg = null): array
    {
        $norm = self::normalize($text);
        $evidence = [];

        $weight = $weightKg !== null && $weightKg > 0 ? $weightKg : self::weightFromText($norm);
        if ($weight !== null) {
            $evidence[] = "tonaj {$weight} kg";
        }

        // 1) Araç adları
        $scores = [];
        foreach (self::NOUNS as $pattern => [$type, $score]) {
            if (preg_match($pattern, $norm, $m)) {
                $scores[$type] = max($scores[$type] ?? 0, $score);
                $evidence[] = "ad: {$m[0]}";
            }
        }
        // Jargon sözlüğü: yöneticinin öğrettiği araç sözcükleri kesin eşleşme sayılır.
        if (($lex = Lexicon::matchVehicle($norm)) !== null) {
            $scores[$lex['canonical']] = max($scores[$lex['canonical']] ?? 0, 10);
            $evidence[] = "sözlük: {$lex['term']}";
        }
        // "kamyon" tek başına: alt tipi teker/dingil ipucu ya da tonaj belirler.
        $genericTruck = (bool) preg_match('/\bkamyon(?:lar|la|u|a|un|um|dan|da|lari|larla)?\b/', $norm);
        if ($genericTruck) {
            $evidence[] = 'ad: kamyon';
        }

        if ($scores !== []) {
            arsort($scores);
            $type = (string) array_key_first($scores);
            $top = $scores[$type];
            // Birden fazla güçlü aday varsa ("tenteli kamyon ya da tır") tonaj ayırır.
            $tied = array_keys(array_filter($scores, fn ($s) => $s === $top));
            if (count($tied) > 1 && $weight !== null) {
                $type = self::closestByWeight($tied, $weight);
            }
            // Genel "kamyon" sözcüğü kamyon alt tipini kesinleştirir: "kamyon" + "tenteli" tır sayılmaz.
            if ($genericTruck && ! str_contains($type, 'kamyon') && $top < 10) {
                $type = self::truckSubtype($weight);
            }
            // Panelvan ailesi: "uzun/maxi" ipucu ya da tonaj alt tipi belirler.
            if ($type === 'orta_panelvan' && ($weight !== null && $weight > 1500 || preg_match('/\b(?:uzun|maxi|jumbo|l3|l4)\b/', $norm))) {
                $type = 'uzun_panelvan';
            }

            return self::result($type, 'keyword', $top >= 9 ? 'high' : 'medium', $weight, $evidence);
        }

        if ($genericTruck) {
            return self::result(self::truckSubtype($weight), 'keyword', $weight !== null ? 'high' : 'medium', $weight, $evidence);
        }

        // 2) Kasa/üstyapı ipuçları
        $hintFamily = null;
        $hintDefault = null;
        $hintScore = 0;
        foreach (self::HINTS as $pattern => $meta) {
            if (preg_match($pattern, $norm, $m)) {
                $evidence[] = "ipucu: {$m[0]}";
                if ($meta['score'] > $hintScore) {
                    $hintScore = $meta['score'];
                    $hintFamily = $meta['family'];
                    $hintDefault = $meta['default'];
                }
            }
        }
        if ($hintFamily !== null) {
            if ($weight !== null) {
                return self::result(self::closestByWeight($hintFamily, $weight), 'hint', 'medium', $weight, $evidence);
            }

            return self::result($hintDefault, 'hint', $hintScore >= 6 ? 'medium' : 'low', $weight, $evidence);
        }

        // 3) Kapasite: tonaj, palet, hacim
        if ($weight !== null) {
            return self::result(VehicleTypes::byWeight($weight), 'weight', 'medium', $weight, $evidence);
        }
        if (preg_match('/\b(\d{1,3})\s*(?:palet|paletlik|pallet)\b/', $norm, $m)) {
            $evidence[] = "palet {$m[1]}";

            return self::result(self::byPallets((int) $m[1]), 'pallet', 'low', null, $evidence);
        }
        if (preg_match('/\b(\d{1,3}(?:[.,]\d)?)\s*(?:m3|m³|metrekup|kup)\b/', $norm, $m)) {
            $evidence[] = "hacim {$m[1]} m³";

            return self::result(self::byVolume((float) str_replace(',', '.', $m[1])), 'volume', 'low', null, $evidence);
        }

        return ['type' => null, 'source' => null, 'confidence' => null, 'weight_kg' => null, 'evidence' => $evidence];
    }

    /** Türkçe küçük harf + ASCII; noktalama boşluğa çevrilir, sayı ayırıcıları korunur. */
    public static function normalize(string $text): string
    {
        $t = TurkishCities::ascii($text);
        $t = str_replace(['m³'], ['m3'], $t);
        $t = preg_replace('/[^\p{L}\p{N}.,\/\-\s]+/u', ' ', $t) ?? $t;
        $t = preg_replace('/(?<=\p{L})[.,\/\-]+|[.,\/\-]+(?=\p{L})/u', ' ', $t) ?? $t; // "tır." "tenteli/kapalı" → boşluk
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;

        return ' '.trim($t).' ';
    }

    /**
     * Normalleştirilmiş metinden tonajı kilogram olarak çıkarır.
     * "24 ton", "24t", "24 tn", "24 tonluk", "20-25 ton" (üst sınır), "3,5 ton", "24.000 kg", "yirmi dört ton".
     */
    public static function weightFromText(string $norm): ?int
    {
        $num = '(\d{1,3}(?:\.\d{3})+|\d{1,6}(?:[.,]\d{1,3})?)';
        // Aralık: "20-25 ton", "20/25 ton", "20 25 ton", "20 ile 25 ton"
        if (preg_match('/(?<![\d.])'.$num.'\s*(?:-|\/|ile|ila|veya)\s*'.$num.'\s*(ton|tn|t|tonluk|tonu|tonlarda)(?!\p{L})/', $norm, $m)) {
            $hi = max(self::toNumber($m[1]), self::toNumber($m[2]));

            return $hi > 0 && $hi <= 60 ? (int) round($hi * 1000) : null;
        }
        if (preg_match('/(?<![\d.])'.$num.'\s*(ton|tn|tonluk|tonu|tonlarda|tonla)(?!\p{L})/', $norm, $m)) {
            $v = self::toNumber($m[1]);

            return $v > 0 && $v <= 60 ? (int) round($v * 1000) : null;
        }
        if (preg_match('/(?<![\d.])'.$num.'\s?t(?![\p{L}\d])/', $norm, $m)) { // "24t"
            $v = self::toNumber($m[1]);

            return $v > 0 && $v <= 60 ? (int) round($v * 1000) : null;
        }
        if (preg_match('/(?<![\d.])'.$num.'\s*(?:kg|kilo|kilogram)(?!\p{L})/', $norm, $m)) {
            $v = self::toNumber($m[1]);

            return $v >= 50 && $v <= 60000 ? (int) round($v) : null;
        }
        // Yazıyla: "yirmi dört ton", "on ton", "üç buçuk ton"
        if (preg_match('/\b((?:'.implode('|', array_keys(self::WORD_NUMBERS)).')(?:\s+(?:'.implode('|', array_keys(self::WORD_NUMBERS)).'))?(?:\s+bucuk)?)\s+ton(?:luk|u)?\b/', $norm, $m)) {
            $v = 0;
            foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $w) {
                $v += $w === 'bucuk' ? 0.5 : (self::WORD_NUMBERS[$w] ?? 0);
            }

            return $v > 0 ? (int) round($v * 1000) : null;
        }

        return null;
    }

    private static function toNumber(string $raw): float
    {
        if (preg_match('/^\d{1,3}(?:\.\d{3})+$/', $raw)) {
            return (float) str_replace('.', '', $raw);
        }

        return (float) str_replace(',', '.', $raw);
    }

    /** Verilen tipler arasından tonajı taşıyan en küçük kapasiteli olanı seçer. */
    private static function closestByWeight(array $types, int $weightKg): string
    {
        usort($types, fn ($a, $b) => VehicleTypes::TYPES[$a]['capacity_kg'] <=> VehicleTypes::TYPES[$b]['capacity_kg']);
        foreach ($types as $t) {
            if (VehicleTypes::TYPES[$t]['capacity_kg'] >= $weightKg) {
                return $t;
            }
        }

        return $types[array_key_last($types)];
    }

    private static function truckSubtype(?int $weightKg): string
    {
        return $weightKg !== null ? VehicleTypes::byWeight($weightKg, true) : '8_teker_kamyon';
    }

    private static function byPallets(int $n): string
    {
        return match (true) {
            $n >= 30 => 'tir',
            $n >= 20 => 'kirkayak',
            $n >= 14 => '10_teker_kamyon',
            $n >= 10 => '8_teker_kamyon',
            $n >= 6 => '6_teker_kamyon',
            $n >= 3 => 'kamyonet',
            $n >= 2 => 'uzun_panelvan',
            default => 'orta_panelvan',
        };
    }

    private static function byVolume(float $m3): string
    {
        return match (true) {
            $m3 >= 80 => 'tir',
            $m3 >= 50 => '10_teker_kamyon',
            $m3 >= 35 => '8_teker_kamyon',
            $m3 >= 20 => '6_teker_kamyon',
            $m3 >= 12 => 'kamyonet',
            $m3 >= 8 => 'uzun_panelvan',
            $m3 >= 4 => 'orta_panelvan',
            default => 'minivan',
        };
    }

    private static function result(string $type, string $source, string $confidence, ?int $weight, array $evidence): array
    {
        return ['type' => $type, 'source' => $source, 'confidence' => $confidence, 'weight_kg' => $weight, 'evidence' => $evidence];
    }
}
