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
        'trabzon' => 'Trabzon', 'ada' => 'Adana', 'mrs' => 'Mersin', 'ist.' => 'İstanbul', 'izmir' => 'İzmir',
        'kmaraş' => 'Kahramanmaraş', 'k.maraş' => 'Kahramanmaraş', 'k.maras' => 'Kahramanmaraş', 'kahramanmaras' => 'Kahramanmaraş', 'gaziantep' => 'Gaziantep',
        'ş.urfa' => 'Şanlıurfa', 's.urfa' => 'Şanlıurfa', 'surfa' => 'Şanlıurfa', 'sanli' => 'Şanlıurfa', 'd.bakır' => 'Diyarbakır', 'd.bakir' => 'Diyarbakır', 'dbakir' => 'Diyarbakır',
        'eskişehr' => 'Eskişehir', 'esk' => 'Eskişehir', 'ktahya' => 'Kütahya', 'a.karahisar' => 'Afyonkarahisar', 'akarahisar' => 'Afyonkarahisar',
        'anadolu' => 'İstanbul', 'avrupa' => 'İstanbul', 'ıst' => 'İstanbul', 'i̇st' => 'İstanbul', 'ankra' => 'Ankara', 'ankr' => 'Ankara',
    ];

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
        if ($text === null || trim($text) === '') {
            return null;
        }
        $clean = trim(preg_replace("/[’'‘`]/u", '', $text) ?? $text);
        // Noktalı kısaltma ("K.Maraş", "Ş.Urfa", "D.Bakır") önce bütün olarak takma ad listesinde aranır
        $dotted = strtok($clean, " \t\n,;:");
        if ($dotted !== false && str_contains($dotted, '.') && isset(self::ALIASES[self::ascii(rtrim($dotted, '.'))])) {
            return self::ALIASES[self::ascii(rtrim($dotted, '.'))];
        }
        $first = strtok($clean, " \t\n,.;:-");
        if ($first === false || $first === '') {
            return null;
        }
        $token = self::ascii($first);
        if (isset(self::ALIASES[$token])) {
            return self::ALIASES[$token];
        }

        $map = self::asciiMap();
        if (isset($map[$token])) {
            return $map[$token];
        }
        foreach (self::SUFFIXES as $suffix) {
            if (str_ends_with($token, $suffix)) {
                $stem = substr($token, 0, -strlen($suffix));
                if (strlen($stem) >= 3 && isset($map[$stem])) {
                    return $map[$stem];
                }
                if (strlen($stem) >= 3 && isset(self::ALIASES[$stem])) {
                    return self::ALIASES[$stem]; // "antepe", "urfadan", "izmitten"
                }
            }
        }

        return $fuzzy ? self::fuzzyProvince($token) : null;
    }

    /**
     * Yazım hatalı il adı: "diyarbakr" → Diyarbakır, "istanbl" → İstanbul, "ankra" → Ankara.
     * Kısa sözcüklerde tek harf, uzunlarda iki harf farkına izin verir; ek takılı yazımları da dener.
     */
    public static function fuzzyProvince(string $asciiToken): ?string
    {
        $token = preg_replace('/[^a-z]/', '', $asciiToken) ?? '';
        // Yer adına benzeyen gündelik sözcükler il sanılmasın ("burda" → Bursa, "aydan" → Aydın).
        static $stop = ['burda', 'orda', 'surda', 'sonra', 'yukle', 'yuklu', 'hemen', 'acele', 'kadar', 'tonaj', 'arasi', 'gunde', 'yarin',
            'bugun', 'sabah', 'aksam', 'gece', 'fiyat', 'kamyon', 'bosta', 'ambar', 'depo', 'liman', 'sube', 'aydan', 'kilit', 'ucak', 'boru',
            'sirket', 'firma', 'musteri', 'dolar', 'nakit', 'siparis', 'teslim', 'gidecek', 'gelecek', 'olacak', 'lazim', 'aranan'];
        if (strlen($token) < 5 || in_array($token, $stop, true)) {
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
        $province = self::fromText($text);
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
