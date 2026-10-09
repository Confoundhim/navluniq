<?php

namespace App\Support;

use App\Services\AiParserService;

/**
 * Serbest metinden (WhatsApp ilanı) araç tipini çıkaran puanlama tabanlı sınıflandırıcı.
 *
 * Katmanlar, güçlüden zayıfa:
 *  1. Araç adı: tır, çekici, dorse, kırkayak, 10/8/6 teker, kamyon, kamyonet, panelvan (hafif ticari marka adları dahil).
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
    /** "2 metre yer", "1 metre parsiyel", "3 metre boşluk": metre araç boyu değil parça yükün yeridir. */
    private const NOT_SPACE_METRE = '(?!\s*(?:yer|yeri|yerlik|parsiyel|parca|bosluk|yuk|yukumuz|alir|alinir))';

    /**
     * Tek başına "uzun" / "kısa" nakliye dilinde dorse boyudur ("Erbaa uzun", "Çorlu kısa", "uzun kısa 1.400 TL" = iki boy da olur) → TIR.
     * "uzun yol", "kısa mesafe", "en kısa sürede", "uzun vade", "7 metre uzunluğunda" boy değildir.
     */
    public const LENGTH_WORD = '(?<!en )(?<!cok )\b(?:uzun|kisa)\b(?!\s*(?:yol|yola|yolu|yollar|yollu|mesafe|mesafeli|vade|vadeli|sure|surede|sureli|surec|surecte|zaman|zamanda|zamanli|donem|donemli|metre|mt|m\b|kol|kollu|boylu|boy|soz|sozlu|not|film|sac|sacli|kenar|kenarli|tarif|omur|omurlu|sasi|sase|panelvan|panel|dorse|arac|tir|kasa|yillar|yillik|yildir|bir\s+sure))';

    /** Araç adları: düzenli ifade (ASCII'ye indirgenmiş, sözcük sınırlı) → [tip, puan]. */
    private const NOUNS = [
        // TIR ailesi
        // "e" eki yok: "tire" İzmir'in ilçesidir (TIR'ın yönelme hali "tıra"); canlı dökümde "Tire → …" ilanları tır sayılıyordu
        '/\btir(?:lar|lari|lik|la|i|a|in|im|dan|da|imiz|iniz|lara|larla|lardan|larda)?\b/' => ['tir', 10],
        // Adetle bitişik ya da yazım hatalı tır: "bırtır", "dörttir", "iki tr", "dört tur", "2 tr" (Engin Abi listeleri, 2026-10-08)
        '/\b(?:bir|iki|uc|dort|bes|alti|yedi|sekiz|dokuz|on)(?:tir|tirr|tr|tur)\b|\b(?:\d{1,2}|bir|iki|uc|dort|bes|alti|yedi|sekiz|dokuz|on)\s+(?:tr|tur|tirr)\b/' => ['tir', 9],
        // "13.60", "1360", "13-60", "13/60": dorse uzunluğu = tır; "kısa dorse" (10-11 m), "sal dorse", "2 kapak", "40 ayak konteyner", "uzun araç"
        '/(?<![\d.,])(?:13[.,\/\- ]?60|1360)(?![\d])/' => ['tir', 10],
        '/\bkisa\s+dorse\b|\bsal\s+dorse\b|\bsal\b(?=\s+(?:dorse|8\.60|860|damper))|\b\d\s*kapak\b|\b40\s*ayak\b|\b20\s*ayak\b|\bkonteyn[ie]r\b|\buzun\s+arac\b|\btirlik\b/' => ['tir', 9],
        '/\btente(?:li|n|si|le|siz)?\b|\btnt\b|\btentli\b/' => ['tir', 7],
        '/\bfr[iı]?[iı]?go(?:rifik|lu|dur|su|lar)?\b|\bfirgo\b|\bfirigo\b|\btermo\s?k[iı]ng?\b|\bthermo\s?king\b|\btermokin\b/' => ['tir', 6],
        // "8.60 damperli", "8.60 kasa": 8.60 m kasa = 10 teker kamyon; "6.20/6.50 metre" = 6-8 teker; "3-4.5 metre" = kamyonet/panelvan
        // Tam sayı metre de kasa boyudur ("7 metre kapalı", "8 metre araç"); "2 metre yer / 1 metre parsiyel" ise parça yükün yeri, araç değil
        '/(?<![\d.,])(?:8[.,]60|860)(?![\d])/' => ['10_teker_kamyon', 9],
        '/(?<![\d.,])(?:8(?:[.,]\d\d?)?|9(?:[.,]\d\d?)?)\s*(?:m|mt|metre|mtre)\b'.self::NOT_SPACE_METRE.'/' => ['10_teker_kamyon', 7],
        '/(?<![\d.,])(?:6[.,][2-9]\d?|7(?:[.,]\d\d?)?)\s*(?:m|mt|metre|mtre)\b'.self::NOT_SPACE_METRE.'/' => ['8_teker_kamyon', 7],
        '/(?<![\d.,])(?:4[.,][2-9]\d?|5(?:[.,]\d\d?)?|6(?:[.,]0\d?)?)\s*(?:m|mt|metre|mtre)\b'.self::NOT_SPACE_METRE.'/' => ['6_teker_kamyon', 7],
        '/(?<![\d.,])(?:2[.,]\d\d?|3[.,]?\d?\d?|4[.,]?[01]?)\s*(?:m|mt|metre|mtre)\b'.self::NOT_SPACE_METRE.'/' => ['kamyonet', 7],
        '/\bcekici(?:ler|li|yle|si|ye|den|de|m|miz)?\b/' => ['tir', 9],
        '/\bdorse(?:ler|li|yle|si|ye|den|de|m|miz|lik)?\b/' => ['tir', 9],
        '/\bkirk\s?ayak(?:lar|la|li|i|a|in)?\b/' => ['kirkayak', 10],
        '/\b(?:4|dort)\s?dingil(?:li)?\b/' => ['kirkayak', 8],
        // Kamyon alt tipleri
        '/\b(?:10|on)\s?(?:teker(?:lek|li|lekli|de|e|den|le|ler|lere|lerde|lerle|lik)?)\b/' => ['10_teker_kamyon', 10],
        '/\b(?:8|sekiz)\s?(?:teker(?:lek|li|lekli|de|e|den|le|ler|lere|lerde|lerle|lik)?)\b/' => ['8_teker_kamyon', 10],
        '/\b(?:6|alti)\s?(?:teker(?:lek|li|lekli|de|e|den|le|ler|lere|lerde|lerle|lik)?)\b/' => ['6_teker_kamyon', 10],
        '/\b(?:3|uc)\s?dingil(?:li)?\b/' => ['10_teker_kamyon', 7],
        // Hafif ticari
        '/\bkamyonet(?:ler|le|i|e|in|im|ten|te|lik|ler[ei])?\b/' => ['kamyonet', 10],
        '/\b(?:pikap|pick\s?up|pickup)\b/' => ['kamyonet', 9],
        // Panelvan: hafif ticari sınıfın tek tipi (uzun/orta şasi, minivan ve hafif ticari marka adları aynı tipe iner)
        '/\b(?:uzun|orta)\s+(?:sasi|sase|panelvan|panel\s?van)\b/' => ['panelvan', 10],
        '/\b(?:panelvan|panel\s?van)(?:\s+uzun|la|i|a|in|lar|dan)?\b/' => ['panelvan', 10],
        '/\b(?:maxi|l3h2|l4h2|l3|l4)\b/' => ['panelvan', 7],
        '/\b(?:transit|sprinter|crafter|ducato|boxer|jumper|master|daily|iveco)\b/' => ['panelvan', 8],
        '/\bminivan(?:la|i|a|lar)?\b/' => ['panelvan', 9],
        '/\b(?:doblo|caddy|connect|kangoo|fiorino|combo|partner|berlingo|expert|vito|bipper|nemo|scudo|dokker|courier)\b/' => ['panelvan', 8],
        '/\bhafif\s+ticari\b/' => ['panelvan', 7],
    ];

    /**
     * Kasa/üstyapı ipuçları: ailenin tipleri ve tonaj yoksa varsayılan tip.
     * family: bu ipucunun geçerli olduğu tipler; default: tonaj/başka ipucu yoksa seçilen tip.
     */
    private const HINTS = [
        '/\btent(?:e|eli|elidir|eliler|esiz|elik)?\b/' => ['family' => ['tir', 'kirkayak', '10_teker_kamyon', '8_teker_kamyon', '6_teker_kamyon'], 'default' => 'tir', 'score' => 6],
        '/\b(?:mega|lowbed|low\s?bed|lowbet|lobed|lovbed|silobas|silo\s?bas|tanker|konteyn[ie]r|platform|jumbo\s+dorse|13[.,]60?)\b/' => ['family' => ['tir'], 'default' => 'tir', 'score' => 6],
        '/\bfrigo(?:rifik|lu|dur)?\b|\bsogutucu(?:lu)?\b|\bsogutmali\b/' => ['family' => ['tir', 'kirkayak', '10_teker_kamyon', '8_teker_kamyon', '6_teker_kamyon', 'kamyonet', 'panelvan'], 'default' => 'tir', 'score' => 3],
        '/\bdamper(?:li|le|i|ler|lidir|liler)?\b|\bdanper(?:li)?\b/' => ['family' => ['tir', 'kirkayak', '10_teker_kamyon', '8_teker_kamyon', '6_teker_kamyon'], 'default' => '10_teker_kamyon', 'score' => 3],
        // Dökme kömür/klinker/maden yükleri damperli tır ya da kırkayakla taşınır; "sınırsız damperli araç", "basar tonaj" → tır
        '/\b(?:dokme|basar\s+tonaj|sinirsiz\s+damper\w*|tonajini\s+alir|serbest\s+tonaj)\b/' => ['family' => ['tir', 'kirkayak', '10_teker_kamyon'], 'default' => 'tir', 'score' => 2],
        '/\byuksek\s+yan\b/' => ['family' => ['10_teker_kamyon', '8_teker_kamyon', '6_teker_kamyon', 'kamyonet'], 'default' => '10_teker_kamyon', 'score' => 3],
        // "kapalı/açık", "kapalı veya açık" (normalize "/" işaretini boşluğa çevirir; eski kalıp hiç eşleşmiyordu), "açık kapalı fark etmez",
        // "tente frigo olur", "kısa uzun olur": kasa seçeneği sayan ilan dorse ister → TIR (tonaj yazılıysa ağır sınıf içinde tonaj seçer)
        '/\bkapali\s+(?:tir|arac|araclar|dorse)\b|\bacik\s+(?:tir|arac|araclar|dorse)\b|\bkapali\s+(?:veya\s+|yada\s+|ya\s+da\s+)?acik\b|\bacik\s+(?:veya\s+|yada\s+|ya\s+da\s+)?kapali\b/' => ['family' => ['tir'], 'default' => 'tir', 'score' => 5],
        '/\b(?:acik|kapali|tenteli|tente|tentenli|frigo|damper|damperli|uzun|kisa)(?:\s+(?:acik|kapali|tenteli|tente|tentenli|frigo|damper|damperli|uzun|kisa|veya|yada|ya\s+da|ve|olsun|arac|araca))*\s+(?:fark\s?etmez|farketmez|farkemez|olur|olabilir|uyar|uygun)\b/' => ['family' => ['tir', 'kirkayak', '10_teker_kamyon', '8_teker_kamyon', '6_teker_kamyon'], 'default' => 'tir', 'score' => 5],
        '/'.self::LENGTH_WORD.'/' => ['family' => ['tir'], 'default' => 'tir', 'score' => 4],
        // "Gaziantep basar" (tonajını basar = dolu tır/damper), "yük üstü" (açık dorse/kasa üstü yük) ağır araç ister
        '/\bbasar\b(?!\s+(?:nakliyat|nakliye|nak|lojistik|loj|bey|usta|abi|kardes|trans|tasimacilik|ve|ile))/' => ['family' => ['tir', 'kirkayak', '10_teker_kamyon'], 'default' => 'tir', 'score' => 2],
        '/\byuk\s?ustu(?:ne|nde|dur)?\b/' => ['family' => ['tir', 'kirkayak', '10_teker_kamyon', '8_teker_kamyon', '6_teker_kamyon'], 'default' => 'tir', 'score' => 3],
        '/\bats\s?li\b|\bustten\s+yukleme\b|\bmega\s+tente\w*\b|\btekstil\s+dorse\w*\b/' => ['family' => ['tir'], 'default' => 'tir', 'score' => 4],
        '/\bkapali\s+kasa\b/' => ['family' => ['tir', 'kirkayak', '10_teker_kamyon', '8_teker_kamyon', '6_teker_kamyon', 'kamyonet', 'panelvan'], 'default' => 'kamyonet', 'score' => 3],
        '/\bacik\s+kasa\b/' => ['family' => ['tir', 'kirkayak', '10_teker_kamyon', '8_teker_kamyon', '6_teker_kamyon', 'kamyonet'], 'default' => 'kamyonet', 'score' => 3],
        '/\b(?:komple|tirlik|full\s+tir|full\s+arac|full\s+yuk|ftl)\b/' => ['family' => ['tir', 'kirkayak', '10_teker_kamyon'], 'default' => 'tir', 'score' => 2],
        '/\b(?:jumbo)\b/' => ['family' => ['tir', 'panelvan'], 'default' => 'panelvan', 'score' => 2],
        // "araba" nakliye dilinde her araç için kullanılır; yalnız tonaj/başka ipucu yoksa en küçük tip (panelvan) sayılır.
        '/\baraba(?:yla|la|si|m|miz|lar)?\b/' => ['family' => ['tir', 'kirkayak', '10_teker_kamyon', '8_teker_kamyon', '6_teker_kamyon', 'kamyonet', 'panelvan'], 'default' => 'panelvan', 'score' => 1],
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
        // Telefon rakamları araç/kasa kalıplarına sızmasın ("0532 000 13 60" → 13.60 dorse, "0532 860 …" → 8.60 kasa)
        $norm = self::normalize(preg_replace(AiParserService::PHONE_PATTERN, ' ', $text) ?? $text);
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
            $scores[$lex['canonical']] = max($scores[$lex['canonical']] ?? 0, 8); // açık araç adı ("tır", "13.60": 10) sözlük sözcüğünü yener
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
            // Yalnız kasa sözcüğü (tenteli, frigo) ile "tır" çıktıysa ve tonaj kamyon sınıfındaysa (≤ 10 teker kapasitesi) araç kamyon
            // alt tipidir ve tahmindir: "10 ton tenteli" kesin TIR sayılınca 10 teker tenteli şoför ilanı hiç göremiyordu.
            if ($type === 'tir' && $top <= 7 && $weight !== null && $weight <= VehicleTypes::TYPES['10_teker_kamyon']['capacity_kg']) {
                return self::result(self::truckSubtype($weight), 'hint', 'medium', $weight, $evidence);
            }

            return self::result($type, 'keyword', $top >= 9 ? 'high' : 'medium', $weight, $evidence);
        }

        if ($genericTruck) {
            // Tonajsız "kamyon": sınıfın en küçüğü (6 teker) tahmin olarak yazılır; filtre tahmini yumuşak uygular, her kamyon şoförü görür
            // (eski sürüm "8 teker, kesin" yazıyor, 6 teker şoförü ilanı hiç göremiyordu).
            return $weight !== null
                ? self::result(self::truckSubtype($weight), 'keyword', 'high', $weight, $evidence)
                : self::result('6_teker_kamyon', 'hint', 'medium', null, $evidence);
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
    /**
     * "Araç fark etmez", "her türlü araç olur", "araç tipi önemli değil": ilan her araca açık.
     * Kasa için söylenen "tente frigo fark etmez" bu kalıba girmez (araç sözcüğü şarttır).
     */
    /**
     * Grup adından araç/kasa varsayımı (Osman, 2026-10-09: "13.60 ilanları diye grup var, burada araç belirtmesine gerek yok"): "13.60 TÜRKİYE
     * GENELİ", "KONYA KISA DORSE 13-60", "TÜRKİYE DAMPER", "İstanbul Minivan ve Panelvan", "Tavas Kamyoncular" gruplarında ilan araç yazmaz,
     * grubun kendisi söyler. Canlı dökümde araçsız bekleyen 5.192 adayın 362'si böyle gruplardandı. Sonuç tahmindir (ai_guess: filtre yumuşak
     * uygular), ilanda açıkça yazılan araç/kasa ezilmez. "Tire" gibi ilçe adları araç sayılmaz (ek listesi sınırlı).
     *
     * @return array{vehicle:?string, body:list<string>, load_kind:?string}
     */
    public static function fromGroupName(?string $groupName): array
    {
        $none = ['vehicle' => null, 'body' => [], 'load_kind' => null];
        if ($groupName === null || trim($groupName) === '') {
            return $none;
        }
        $n = self::normalize(preg_replace('/\d+\s*\/\s*\d+|\(\d+\)/u', ' ', $groupName) ?? $groupName); // "7/24", "(5)"
        $none['load_kind'] = preg_match('/\bpar[cs]a\b|\bparsiyel\b|\bgrupaj\b/', $n) ? 'parca' : null; // "PARÇA YÜKLER" grubu: araç yazmasa da yük biçimi parça
        $vehicle = null;
        foreach ([
            '/\bkamyonet(?:ler|ci|ciler|cileri|leri)?\b/' => 'kamyonet',
            '/\bpanel\s?van(?:lar|ci|cilar)?\b|\bminivan(?:lar|ci|cilar)?\b|\bhafif\s+ticari\b/' => 'panelvan',
            '/\bkirk\s?ayak(?:lar|ci|cilar|cilari)?\b/' => 'kirkayak',
            '/\b(?:10|on)\s?teker\w*|(?<![\d.,])(?:8[.,]60|860)(?![\d])/' => '10_teker_kamyon',
            '/\b(?:8|sekiz)\s?teker\w*/' => '8_teker_kamyon',
            '/\b(?:6|alti)\s?teker\w*/' => '6_teker_kamyon',
            '/\bkamyon(?:lar|cu|cular|culari|lari|lar\w*)?\b/' => '6_teker_kamyon', // tonajsız kamyon = sınıfın en küçüğü (tahmin)
            '/(?<![\d.,])(?:13[.,\/\- ]?60|1360)(?![\d])|\btir(?:lar|lari|ci|cilar|cilari|lik)?\b|\bcekici(?:ler|ci)?\b|\bdorse(?:ler|ci|ciler|cileri)?\b|\blow\s?bed\w*|\bsilobas\w*|\bkonteyn[ie]r\w*/' => 'tir',
            '/\bdamper(?:li|ci|ciler|cileri|lar)?\b|\bfr?i?r?igo(?:cu|cular|culari|lar|lu)?\b|\bfirgo\w*|\btente(?:li|n|ci|ciler|cileri)?\b/' => 'tir', // kasa adlı grup: ağır araç, varsayılan TIR
        ] as $pattern => $type) {
            if (preg_match($pattern, $n)) {
                $vehicle = $type;
                break;
            }
        }
        if ($vehicle === null) {
            return $none;
        }
        $body = [];
        foreach ([
            'damperli' => '/\bdamper\w*/', 'frigo' => '/\bfr?i?r?igo\w*|\bfirgo\w*/', 'tenteli' => '/\btente\w*/',
            'kapali' => '/\bkapali\b/', 'acik' => '/\bacik\b/',
        ] as $kind => $pattern) {
            if (preg_match($pattern, $n)) {
                $body[] = $kind;
            }
        }
        if ($vehicle === 'tir') {
            if (preg_match('/\bkisa\b/', $n)) {
                $body[] = 'kisa_dorse';
            }
            if (preg_match('/(?<![\d.,])(?:13[.,\/\- ]?60|1360)(?![\d])|\buzun\b/', $n)) {
                $body[] = 'uzun_dorse';
            }
        }

        return ['vehicle' => $vehicle, 'body' => $body, 'load_kind' => $none['load_kind']];
    }

    public static function anyVehicle(string $norm): bool
    {
        // "her araca uyar / olur / uygun", "tüm araçlara açık", "araç olur ne olursa": her araç (2026-10-09 canlı dökümü)
        return (bool) preg_match('/\b(?:arac(?:lar|i|lari)?\s*(?:tipi|cinsi)?\s*(?:fark ?etmez|farketmez|farkemez|onemli degil|onemsiz|serbest|ne olursa)|her\s*(?:turlu|tur|cins)\s*arac|her\s+araca?\s+(?:uyar|olur|uygun|acik|olabilir)|fark ?etmez\s*arac|hangi arac olursa|arac(?:lar)?\s*hepsi olur|tum arac(?:lar|lara)?(?:\s*uygun|\s*acik|\s*olur)?)\b/u', $norm);
    }

    public static function normalize(string $text): string
    {
        $t = TurkishCities::ascii($text);
        $t = str_replace(['m³'], ['m3'], $t);
        $t = preg_replace('/[^\p{L}\p{N}.,\/\-\s]+/u', ' ', $t) ?? $t;
        $t = preg_replace('/(?<=\p{L})[.,\/\-]+|[.,\/\-]+(?=\p{L})/u', ' ', $t) ?? $t; // "tır." "tenteli/kapalı" → boşluk
        $t = self::splitCompounds($t);
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;

        return ' '.trim($t).' ';
    }

    /**
     * Bitişik ve yazım hatalı araç/kasa yazımları ayrılır (2026-10-09 canlı dökümü: "TIRkapalı", "FRIGOTIR", "DAMPERDORSE", "TIR2",
     * "KAMYONONET", "kırkayakk", "1O TEKER", "l10 teker", "10TKR", "10 tk"): ASCII küçük harfli metinde çalışır.
     */
    public static function splitCompounds(string $t): string
    {
        $t = strtolower($t);
        $t = preg_replace('/\b(tir)(?=(?:kapali|acik|tenteli|tente|frigo|damper|lar|\d))/u', '$1 ', $t) ?? $t; // tirkapali, tir2
        $t = preg_replace('/\b(frigo|damper|tenteli|tente|kapali|acik|mega|sal)(?=tir\b|dorse)/u', '$1 ', $t) ?? $t; // frigotir, damperdorse, kapalitir
        $t = preg_replace('/\b(kapali|acik|tenteli|frigo)(?=(?:kasa|kamyon|kamyonet|kirkayak)\b)/u', '$1 ', $t) ?? $t; // kapalikasa, acikkamyon
        $t = str_replace(['kamyononet', 'kamyonett', 'kirkayakk', 'kirk ayak'], ['kamyonet', 'kamyonet', 'kirkayak', 'kirkayak'], $t);
        $t = preg_replace('/\b(?:1o|l10|lo)\b(?=\s*(?:teker|tekerlek|tkr|tk))/u', '10', $t) ?? $t; // harf O / l yazım hatası
        $t = preg_replace('/\b(10|8|6)\s*(?:tkr|tk)\b/u', '$1 teker', $t) ?? $t; // 10tkr, 10 tk
        $t = preg_replace('/\b(on|sekiz|alti)\s*(?:tkr|tk)\b/u', '$1 teker', $t) ?? $t; // "on tkr"

        return $t;
    }

    /**
     * Normalleştirilmiş metinden tonajı kilogram olarak çıkarır.
     * "24 ton", "24t", "24 tn", "24 tonluk", "20-25 ton" (üst sınır), "3,5 ton", "24.000 kg", "yirmi dört ton".
     */
    public static function weightFromText(string $norm): ?int
    {
        $num = '(\d{1,3}(?:\.\d{3})+|\d{1,6}(?:[.,]\d{1,3})?)';
        // Aralık: "20-25 ton", "20/25 ton", "20 25 ton", "20 ile 25 ton"
        // "0-25 ton", "0-21-22 ton", "20 25 ton", "10 12 ton": aralığın üst sınırı
        if (preg_match('/(?<![\d.])'.$num.'(?:\s*(?:-|\/|ile|ila|veya|\s)\s*'.$num.'){1,2}\s*(ton|tn|t|tonluk|tonu|tonlarda|tonlar)(?!\p{L})/', $norm, $m)) {
            preg_match_all('/\d{1,3}(?:\.\d{3})+|\d{1,6}(?:[.,]\d{1,3})?/', $m[0], $nums);
            $hi = max(array_map(fn ($n) => self::toNumber($n), $nums[0]));

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
            default => 'panelvan',
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
            default => 'panelvan', // eski uzun/orta panelvan ve minivan anahtarları geçersizdi; ilan hiçbir şoföre çıkmıyordu
        };
    }

    private static function result(string $type, string $source, string $confidence, ?int $weight, array $evidence): array
    {
        return ['type' => $type, 'source' => $source, 'confidence' => $confidence, 'weight_kg' => $weight, 'evidence' => $evidence];
    }
}
