<?php

namespace App\Support;

/**
 * 81 il için Türkçe duyarlı eşleme: "İzmir'e", "Ankaradan", "istanbul" gibi yazımlardan il adını çıkarır.
 */
final class TurkishCities
{
    public const PROVINCES = [
        'Adana', 'Adıyaman', 'Afyonkarahisar', 'Ağrı', 'Amasya', 'Ankara', 'Antalya', 'Artvin', 'Aydın', 'Balıkesir',
        'Bilecik', 'Bingöl', 'Bitlis', 'Bolu', 'Burdur', 'Bursa', 'Çanakkale', 'Çankırı', 'Çorum', 'Denizli',
        'Diyarbakır', 'Edirne', 'Elazığ', 'Erzincan', 'Erzurum', 'Eskişehir', 'Gaziantep', 'Giresun', 'Gümüşhane', 'Hakkari',
        'Hatay', 'Isparta', 'Mersin', 'İstanbul', 'İzmir', 'Kars', 'Kastamonu', 'Kayseri', 'Kırklareli', 'Kırşehir',
        'Kocaeli', 'Konya', 'Kütahya', 'Malatya', 'Manisa', 'Kahramanmaraş', 'Mardin', 'Muğla', 'Muş', 'Nevşehir',
        'Niğde', 'Ordu', 'Rize', 'Sakarya', 'Samsun', 'Siirt', 'Sinop', 'Sivas', 'Tekirdağ', 'Tokat',
        'Trabzon', 'Tunceli', 'Şanlıurfa', 'Uşak', 'Van', 'Yozgat', 'Zonguldak', 'Aksaray', 'Bayburt', 'Karaman',
        'Kırıkkale', 'Batman', 'Şırnak', 'Bartın', 'Ardahan', 'Iğdır', 'Yalova', 'Karabük', 'Kilis', 'Osmaniye', 'Düzce',
    ];

    /** Yaygın kısaltma ve eski adlar. */
    private const ALIASES = [
        'afyon' => 'Afyonkarahisar', 'maraş' => 'Kahramanmaraş', 'maras' => 'Kahramanmaraş', 'urfa' => 'Şanlıurfa',
        'antep' => 'Gaziantep', 'içel' => 'Mersin', 'icel' => 'Mersin', 'ist' => 'İstanbul', 'izmit' => 'Kocaeli',
        'gantep' => 'Gaziantep', 'ank' => 'Ankara', 'izm' => 'İzmir', 'istanbul' => 'İstanbul', 'stanbul' => 'İstanbul',
        'ıstanbul' => 'İstanbul', 'kmaras' => 'Kahramanmaraş', 'sanliurfa' => 'Şanlıurfa', 'diyarbekir' => 'Diyarbakır',
        'trabzon' => 'Trabzon', 'mrs' => 'Mersin', 'ist.' => 'İstanbul', 'izmir' => 'İzmir',
        'kmaraş' => 'Kahramanmaraş', 'k.maraş' => 'Kahramanmaraş', 'k.maras' => 'Kahramanmaraş', 'kahramanmaras' => 'Kahramanmaraş', 'gaziantep' => 'Gaziantep',
        'ş.urfa' => 'Şanlıurfa', 's.urfa' => 'Şanlıurfa', 'surfa' => 'Şanlıurfa', 'sanli' => 'Şanlıurfa', 'd.bakır' => 'Diyarbakır', 'd.bakir' => 'Diyarbakır', 'dbakir' => 'Diyarbakır',
        'eskişehr' => 'Eskişehir', 'esk' => 'Eskişehir', 'ktahya' => 'Kütahya', 'a.karahisar' => 'Afyonkarahisar', 'akarahisar' => 'Afyonkarahisar',
        'ç.kale' => 'Çanakkale', 'c.kale' => 'Çanakkale', 'ckale' => 'Çanakkale', 'çkale' => 'Çanakkale', 'zong' => 'Zonguldak',
        'kkale' => 'Kırıkkale', 'k.kale' => 'Kırıkkale', 'gantep' => 'Gaziantep', 'g.antep' => 'Gaziantep', 'tdag' => 'Tekirdağ', 't.dag' => 'Tekirdağ',
        'bkesir' => 'Balıkesir', 'b.kesir' => 'Balıkesir', 'esehir' => 'Eskişehir', 'e.sehir' => 'Eskişehir', 'ksehir' => 'Kırşehir', 'nsehir' => 'Nevşehir',
        'adapazari' => 'Sakarya', // antakya / iskenderun ilçe yolundan çözülür (ilçe ve koordinat kaybolmasın)
        'ıst' => 'İstanbul', 'i̇st' => 'İstanbul', 'ankra' => 'Ankara', 'ankr' => 'Ankara',
    ];

    /** Yer adına benzeyen gündelik sözcükler: yazım hatası toleransıyla bile il/ilçe sanılmaz. */
    public const STOP_WORDS = ['burda', 'orda', 'surda', 'sonra', 'yukle', 'yuklu', 'hemen', 'acele', 'kadar', 'tonaj', 'arasi', 'gunde', 'yarin',
        'kahraman', 'kahramanlar', 'sultan', 'mustafa', 'kemal', 'kirik', 'sanli', 'gazi', 'sehir', 'merkez', 'liman', 'sanayi', 'tenteli', 'kapali',
        'bugun', 'sabah', 'aksam', 'gece', 'fiyat', 'kamyon', 'bosta', 'ambar', 'depo', 'sube', 'aydan', 'kilit', 'ucak', 'boru', 'palet', 'torba',
        'sirket', 'firma', 'musteri', 'dolar', 'nakit', 'siparis', 'teslim', 'gidecek', 'gelecek', 'olacak', 'lazim', 'aranan', 'yukleme', 'bosaltma',
        'haftaya', 'hatasi', 'samsung', 'bilesik', 'denizci', 'karahan', 'tirnak', 'kaydin', 'kaydi', 'anadolu', 'avrupa', 'marmara', 'akdeniz', 'karadeniz'];

    private const SUFFIXES = ['ından', 'inden', 'undan', 'ünden', 'dan', 'den', 'tan', 'ten', 'da', 'de', 'ta', 'te', 'ya', 'ye', 'na', 'ne', 'a', 'e', 'ı', 'i', 'u', 'ü'];

    /** Türkçe büyük İ/I kurallarına uygun küçük harf. */
    public static function lower(string $text): string
    {
        return mb_strtolower(str_replace(['İ', 'I'], ['i', 'ı'], $text));
    }

    /** Aksan ve Türkçe karakterleri sadeleştirir: "İzmir" → "izmir", "Çorum" → "corum". */
    public static function ascii(string $text): string
    {
        return strtr(self::lower($text), ['ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u', 'â' => 'a', 'î' => 'i', 'û' => 'u']);
    }

    /** Metindeki ilk sözcükten il adını çıkarır; bulunamazsa null. $fuzzy: yazım hatasına tolerans. */
    public static function fromText(?string $text, bool $fuzzy = true): ?string
    {
        return self::match($text, $fuzzy)['name'] ?? null;
    }

    /**
     * Metnin başındaki il adını ve kaç sözcük kapladığını verir (bkz. TurkishLocations::resolve: kalan sözcüklerde ilçe aranır).
     * Sıra: noktalı kısaltma ("K.Maraş", "Ç.Kale", "G.Antep": liste ya da "ilk harf + son ek" kuralı) → ayrık yazım
     * ("Kahraman Maraş", "Gazi Antep", "Kırık Kale", "Afyon Karahisar": sözcükler birleştirilir) → birebir/takma ad → ek atma →
     * noktasız kısaltma ("GANTEP", "KKALE", "TDAĞ") → yazım hatası (fuzzy).
     *
     * @return array{name:string, tokens:int}|null
     */
    public static function match(?string $text, bool $fuzzy = true): ?array
    {
        if ($text === null || trim($text) === '') {
            return null;
        }
        $clean = trim(preg_replace("/[’'‘`]/u", '', $text) ?? $text);
        $tokens = array_values(array_filter(preg_split('/[\s,;:]+/u', $clean) ?: [], fn ($t) => $t !== ''));
        if ($tokens === []) {
            return null;
        }
        $map = self::asciiMap();

        // 1) Noktalı kısaltma: "K.Maraş", "Ş.Urfa", "Ç.Kale", "G.Antep", "T.Dağ", "B.Kesir"
        $t0 = rtrim($tokens[0], '.');
        if (str_contains($t0, '.')) {
            $a = self::ascii($t0);
            if (isset(self::ALIASES[$a])) {
                return ['name' => self::ALIASES[$a], 'tokens' => 1];
            }
            if (($abbr = self::abbreviation($a, 3)) !== null) {
                return ['name' => $abbr, 'tokens' => 1];
            }
        }

        // 2) Ayrık yazım: iki ya da üç sözcük birleşince il adı veriyorsa ("Kahraman Maraş", "Şanlı Urfa", "Afyon Kara Hisar")
        foreach ([3, 2] as $n) {
            if (count($tokens) < $n) {
                continue;
            }
            $joined = self::ascii(str_replace('.', '', implode('', array_slice($tokens, 0, $n))));
            $joined = preg_replace('/[^a-z]/', '', $joined) ?? $joined;
            if (isset($map[$joined])) {
                return ['name' => $map[$joined], 'tokens' => $n];
            }
            if (isset(self::ALIASES[$joined]) && strlen($joined) >= 6) {
                return ['name' => self::ALIASES[$joined], 'tokens' => $n];
            }
        }

        // 3) Tek sözcük: "Bursa-İstanbul" gibi tireli/noktalı yazımda ilk parça
        $first = strtok($tokens[0], '.-');
        if ($first === false || $first === '') {
            return null;
        }
        $token = self::ascii($first);
        if (isset(self::ALIASES[$token])) {
            return ['name' => self::ALIASES[$token], 'tokens' => 1];
        }
        if (isset($map[$token])) {
            return ['name' => $map[$token], 'tokens' => 1];
        }
        foreach (self::SUFFIXES as $suffix) {
            if (str_ends_with($token, $suffix)) {
                $stem = substr($token, 0, -strlen($suffix));
                // Tek harfli ek yalnız uzun gövdede atılır: "vana" Van, "musa" Muş, "karşı" Kars değildir ("Vandan", "Van'a" yine çözülür)
                if (strlen($suffix) === 1 && strlen($stem) < 5) {
                    continue;
                }
                if (strlen($stem) >= 3 && isset($map[$stem])) {
                    return ['name' => $map[$stem], 'tokens' => 1];
                }
                if (strlen($stem) >= 3 && isset(self::ALIASES[$stem])) {
                    return ['name' => self::ALIASES[$stem], 'tokens' => 1]; // "antepe", "urfadan", "izmitten"
                }
            }
        }
        // 4) Noktasız kısaltma: "GANTEP", "KKALE", "KMARAS" (ilk harf + il adının sonu, tek eşleşme)
        if (($abbr = self::abbreviation($token, 4)) !== null) {
            return ['name' => $abbr, 'tokens' => 1];
        }

        $name = $fuzzy ? self::fuzzyProvince($token) : null;

        return $name !== null ? ['name' => $name, 'tokens' => 1] : null;
    }

    /**
     * "İlk harf + il adının sonu" kısaltması: "c.kale" → Çanakkale, "g.antep" → Gaziantep, "kkale" → Kırıkkale.
     * Yalnız tek bir il uyarsa; kısaltma parçası en az $minRest harf (noktalı 3, noktasız 4: gündelik sözcükler eşleşmesin).
     */
    public static function abbreviation(string $ascii, int $minRest): ?string
    {
        if (preg_match('/^([a-z])(?:\.[a-z])*?\.?([a-z]{3,})$/', $ascii, $m) !== 1 || strlen($m[2]) < $minRest) {
            return null;
        }
        $hits = [];
        foreach (self::asciiMap() as $name => $label) {
            if ($name[0] === $m[1] && str_ends_with($name, $m[2]) && strlen($name) > strlen($m[2]) + 1) {
                $hits[$label] = true;
            }
        }

        return count($hits) === 1 ? array_key_first($hits) : null;
    }

    /**
     * Yazım hatalı il adı: "diyarbakr" → Diyarbakır, "istanbl" → İstanbul, "ankra" → Ankara.
     * Kısa sözcüklerde tek harf, uzunlarda iki harf farkına izin verir; ek takılı yazımları da dener.
     */
    public static function fuzzyProvince(string $asciiToken): ?string
    {
        $token = preg_replace('/[^a-z]/', '', $asciiToken) ?? '';
        // Yer adına benzeyen gündelik sözcükler il sanılmasın ("burda" → Bursa, "aydan" → Aydın).
        if (strlen($token) < 5 || in_array($token, self::STOP_WORDS, true)) {
            return null;
        }
        // Ek takılı yazım da denenir: "diyarbakrdan" → "diyarbakr"
        $variants = [$token];
        foreach (self::SUFFIXES as $suffix) {
            $sfx = self::ascii($suffix);
            if (str_ends_with($token, $sfx) && strlen($token) - strlen($sfx) >= 5) {
                $variants[] = substr($token, 0, -strlen($sfx));
            }
        }
        $best = null;
        $bestDist = PHP_INT_MAX;
        foreach (self::asciiMap() as $ascii => $name) {
            // Kısa il adlarında (Kars, Bolu, Van) yalnız birebir eşleşme; 5-7 harfte tek, 8+ harfte iki harf farkı.
            $limit = strlen($ascii) >= 8 ? 2 : (strlen($ascii) >= 5 ? 1 : 0);
            foreach ($variants as $v) {
                $d = levenshtein($v, $ascii);
                if ($d <= $limit && $d < $bestDist) {
                    $bestDist = $d;
                    $best = $name;
                }
            }
        }

        return $best;
    }

    /** Konum metninin ilk sözcüğü il ise ek atılmış haliyle geri yazar: "İzmire Aliağa" → "İzmir Aliağa". */
    public static function normalizeLocation(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $province = self::fromText($text, fuzzy: false); // yakın eşleme yok: "Haftaya Ankara" Hatay olmaz
        if (! $province) {
            return $text;
        }
        $rest = preg_replace('/^\S+/u', '', trim($text)) ?? '';

        return trim($province.' '.trim($rest));
    }

    /** @return array<string, string> ascii → il adı */
    private static function asciiMap(): array
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (self::PROVINCES as $province) {
                $map[self::ascii($province)] = $province;
            }
        }

        return $map;
    }
}
