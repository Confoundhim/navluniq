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

    /** Metindeki ilk sözcükten il adını çıkarır; bulunamazsa null. */
    public static function fromText(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }
        $first = strtok(trim(preg_replace("/[’'‘`]/u", '', $text) ?? $text), " \t\n,.;:-");
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
            }
        }

        return null;
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
