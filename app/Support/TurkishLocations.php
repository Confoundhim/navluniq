<?php

namespace App\Support;

/**
 * 81 il ve 973 ilçe merkez koordinatı (resources/data/tr-locations.json).
 * Metinden il/ilçe çözümler; mesafe hesabı için haversine sağlar.
 */
final class TurkishLocations
{
    /** @var array{provinces: list<array{code:int,name:string,lat:float,lng:float}>, districts: list<array{p:int,n:string,lat:float,lng:float}>}|null */
    private static ?array $data = null;

    /** @var array<string,int>|null ascii il adı → kod */
    private static ?array $provinceIndex = null;

    /** @var array<int, array<string, array{n:string,lat:float,lng:float}>>|null il kodu → ascii ilçe → veri */
    private static ?array $districtIndex = null;

    /**
     * Veri dosyasında olmayan ilçeler, eski ilçe adları, ilanlarda ilçe gibi kullanılan semt/sanayi/OSB/liman adları ve
     * sınır kapıları. n: görünen ad (ilçe düzeyinde), aliases: mesajlarda geçen yazımlar. Nakliye gruplarından derlendi.
     */
    public const EXTRA_PLACES = [
        ['p' => 43, 'n' => 'Gediz', 'lat' => 38.99, 'lng' => 29.39],
        ['p' => 6, 'n' => 'Kahramankazan', 'lat' => 40.23, 'lng' => 32.68, 'aliases' => ['Kazan']],
        ['p' => 6, 'n' => 'Şereflikoçhisar', 'lat' => 38.94, 'lng' => 33.54, 'aliases' => ['Koçhisar', 'Ş.Koçhisar', 'Şereflikoçhisar']],
        ['p' => 6, 'n' => 'Yenimahalle', 'lat' => 39.97, 'lng' => 32.75, 'aliases' => ['Ostim', 'Gimat', 'İvedik', 'Batıkent', 'Şaşmaz']],
        ['p' => 6, 'n' => 'Altındağ', 'lat' => 39.96, 'lng' => 32.88, 'aliases' => ['Siteler']],
        ['p' => 6, 'n' => 'Polatlı', 'lat' => 39.58, 'lng' => 32.14, 'aliases' => ['Temelli']],
        ['p' => 6, 'n' => 'Nallıhan', 'lat' => 40.19, 'lng' => 31.35, 'aliases' => ['Çayırhan']],
        ['p' => 6, 'n' => 'Sincan', 'lat' => 39.97, 'lng' => 32.58, 'aliases' => ['Malıköy', 'Başkent OSB']],
        ['p' => 6, 'n' => 'Akyurt', 'lat' => 40.13, 'lng' => 33.09, 'aliases' => ['Esenboğa']],
        ['p' => 34, 'n' => 'Arnavutköy', 'lat' => 41.14, 'lng' => 28.63, 'aliases' => ['Hadımköy', 'Hadimkoy']],
        ['p' => 34, 'n' => 'Başakşehir', 'lat' => 41.09, 'lng' => 28.80, 'aliases' => ['İkitelli', 'Ikitelli']],
        ['p' => 34, 'n' => 'Eyüpsultan', 'lat' => 41.16, 'lng' => 28.91, 'aliases' => ['Kemerburgaz', 'Eyüp', 'Göktürk']],
        ['p' => 34, 'n' => 'Esenyurt', 'lat' => 41.03, 'lng' => 28.67, 'aliases' => ['Kıraç']],
        ['p' => 34, 'n' => 'Tuzla', 'lat' => 40.82, 'lng' => 29.30, 'aliases' => ['Orhanlı']],
        ['p' => 34, 'n' => 'Pendik', 'lat' => 40.88, 'lng' => 29.23, 'aliases' => ['Kurtköy']],
        ['p' => 34, 'n' => 'Sultanbeyli', 'lat' => 40.96, 'lng' => 29.27],
        ['p' => 34, 'n' => 'Kadıköy', 'lat' => 40.98, 'lng' => 29.03, 'aliases' => ['Erenköy']],
        ['p' => 34, 'n' => 'Beyoğlu', 'lat' => 41.03, 'lng' => 28.97, 'aliases' => ['Kasımpaşa']],
        ['p' => 34, 'n' => 'Ümraniye', 'lat' => 41.02, 'lng' => 29.10, 'aliases' => ['Dudullu']],
        ['p' => 34, 'n' => 'Bağcılar', 'lat' => 41.04, 'lng' => 28.85, 'aliases' => ['Güneşli']],
        ['p' => 34, 'n' => 'Çatalca', 'lat' => 41.14, 'lng' => 28.46],
        ['p' => 34, 'n' => 'Küçükçekmece', 'lat' => 41.00, 'lng' => 28.77, 'aliases' => ['Sefaköy', 'Halkalı']],
        ['p' => 34, 'n' => 'Ataşehir', 'lat' => 40.98, 'lng' => 29.12, 'aliases' => ['Ferhatpaşa']],
        ['p' => 34, 'n' => 'Bahçelievler', 'lat' => 41.00, 'lng' => 28.85, 'aliases' => ['Yenibosna']],
        ['p' => 34, 'n' => 'Bağcılar', 'lat' => 41.04, 'lng' => 28.85, 'aliases' => ['Güneşli', 'İstoç', 'Istoc', 'Mahmutbey']],
        ['p' => 34, 'n' => 'Eyüpsultan', 'lat' => 41.16, 'lng' => 28.91, 'aliases' => ['Kemerburgaz', 'Eyüp', 'Göktürk', 'Alibeyköy']],
        ['p' => 34, 'n' => 'Tuzla', 'lat' => 40.82, 'lng' => 29.30, 'aliases' => ['Orhanlı', 'Tepeören']],
        ['p' => 34, 'n' => 'Avcılar', 'lat' => 41.02, 'lng' => 28.72, 'aliases' => ['Ambarlı', 'Ambarlı Liman']],
        ['p' => 6, 'n' => 'Gölbaşı', 'lat' => 39.79, 'lng' => 32.81, 'aliases' => ['İncek']],
        ['p' => 6, 'n' => 'Bala', 'lat' => 39.55, 'lng' => 33.12, 'aliases' => ['Kesikköprü']],
        ['p' => 59, 'n' => 'Ergene', 'lat' => 41.13, 'lng' => 27.90, 'aliases' => ['Velimeşe', 'Velimese', 'Misinli', 'Veliköy']],
        ['p' => 39, 'n' => 'Lüleburgaz', 'lat' => 41.40, 'lng' => 27.36, 'aliases' => ['Büyükkarıştıran']],
        ['p' => 34, 'n' => 'Silivri', 'lat' => 41.07, 'lng' => 28.25],
        ['p' => 34, 'n' => 'Arnavutköy', 'lat' => 41.14, 'lng' => 28.63, 'aliases' => ['Hadımköy', 'Hadimkoy', 'Tayakadın']],
        ['p' => 34, 'n' => 'Avcılar', 'lat' => 41.02, 'lng' => 28.72, 'aliases' => ['Ambarlı', 'Ambarlı Liman', 'Marport', 'Kumport']],
        ['p' => 34, 'n' => 'Sultangazi', 'lat' => 41.10, 'lng' => 28.87],
        ['p' => 34, 'n' => 'Sancaktepe', 'lat' => 41.00, 'lng' => 29.23, 'aliases' => ['Samandıra']],
        ['p' => 41, 'n' => 'Çayırova', 'lat' => 40.83, 'lng' => 29.37, 'aliases' => ['Şekerpınar', 'Sekerpinar']],
        ['p' => 42, 'n' => 'Ereğli', 'lat' => 37.51, 'lng' => 34.05, 'aliases' => ['Zengen', 'Konya Ereğli']],
        ['p' => 35, 'n' => 'Gaziemir', 'lat' => 38.32, 'lng' => 27.13, 'aliases' => ['Sarnıç', 'Sarnic']],
        ['p' => 67, 'n' => 'Çaycuma', 'lat' => 41.43, 'lng' => 32.08, 'aliases' => ['Filyos']],
        ['p' => 16, 'n' => 'Gemlik', 'lat' => 40.43, 'lng' => 29.16, 'aliases' => ['Gemport', 'Gemlik Liman']],
        ['p' => 10, 'n' => 'Bandırma', 'lat' => 40.35, 'lng' => 27.97, 'aliases' => ['Bandırma Liman']],
        ['p' => 33, 'n' => 'Tarsus', 'lat' => 36.92, 'lng' => 34.89, 'aliases' => ['Tarsus OSB']],
        ['p' => 27, 'n' => 'Şehitkamil', 'lat' => 37.08, 'lng' => 37.37, 'aliases' => ['Antep OSB', 'Gaziantep OSB', 'GAOSB']],
        ['p' => 41, 'n' => 'Gebze', 'lat' => 40.80, 'lng' => 29.43, 'aliases' => ['Gebze OSB', 'GOSB', 'Dilovası OSB']],
        ['p' => 41, 'n' => 'Dilovası', 'lat' => 40.78, 'lng' => 29.53],
        ['p' => 41, 'n' => 'İzmit', 'lat' => 40.77, 'lng' => 29.92, 'aliases' => ['Kocaeli']],
        ['p' => 35, 'n' => 'Bornova', 'lat' => 38.46, 'lng' => 27.22, 'aliases' => ['Işıkkent', 'Pınarbaşı']],
        ['p' => 35, 'n' => 'Aliağa', 'lat' => 38.80, 'lng' => 26.97, 'aliases' => ['Aliağa OSB', 'Aliaga', 'Alsancak Liman', 'Petkim', 'Nemrut']],
        ['p' => 35, 'n' => 'Kemalpaşa', 'lat' => 38.43, 'lng' => 27.42, 'aliases' => ['K.Paşa', 'Kpaşa', 'İ.Kemalpaşa']],
        ['p' => 35, 'n' => 'Torbalı', 'lat' => 38.15, 'lng' => 27.36, 'aliases' => ['Ayrancılar']],
        ['p' => 35, 'n' => 'Konak', 'lat' => 38.42, 'lng' => 27.14, 'aliases' => ['Alsancak']],
        ['p' => 20, 'n' => 'Honaz', 'lat' => 37.86, 'lng' => 29.36, 'aliases' => ['Kaklık', 'Kocabaş']],
        ['p' => 16, 'n' => 'Mustafakemalpaşa', 'lat' => 40.04, 'lng' => 28.40, 'aliases' => ['M.Kemalpaşa', 'MKP']],
        ['p' => 16, 'n' => 'Nilüfer', 'lat' => 40.21, 'lng' => 28.98, 'aliases' => ['NOSAB', 'BOSB', 'Bursa OSB']],
        ['p' => 17, 'n' => 'Biga', 'lat' => 40.23, 'lng' => 27.24, 'aliases' => ['Karabiga', 'Cenal', 'İçdaş']],
        ['p' => 1, 'n' => 'Yüreğir', 'lat' => 36.99, 'lng' => 35.40, 'aliases' => ['Misis']],
        ['p' => 26, 'n' => 'Tepebaşı', 'lat' => 39.79, 'lng' => 30.50, 'aliases' => ['T.Başı', 'Tbaşı']],
        ['p' => 14, 'n' => 'Bolu Merkez', 'lat' => 40.73, 'lng' => 31.61, 'aliases' => ['Oyak']],
        ['p' => 48, 'n' => 'Milas', 'lat' => 37.32, 'lng' => 27.78, 'aliases' => ['Milas Termik', 'Kemerköy', 'Yeniköy Termik']],
        ['p' => 45, 'n' => 'Soma', 'lat' => 39.19, 'lng' => 27.61, 'aliases' => ['Soma Termik']],
        ['p' => 59, 'n' => 'Malkara', 'lat' => 40.89, 'lng' => 26.90],
        ['p' => 41, 'n' => 'Körfez', 'lat' => 40.77, 'lng' => 29.78, 'aliases' => ['Yarımca', 'Tüpraş', 'Gübretaş']],
        ['p' => 63, 'n' => 'Akçakale', 'lat' => 36.71, 'lng' => 38.95, 'aliases' => ['Akçakale Tampon']],
        // Sınır kapıları (ilçe düzeyinde konum)
        ['p' => 31, 'n' => 'Reyhanlı', 'lat' => 36.23, 'lng' => 36.66, 'aliases' => ['Cilvegözü', 'Cilvegozu', 'Cilevgözü', 'Cilvegöz']],
        ['p' => 73, 'n' => 'Silopi', 'lat' => 37.25, 'lng' => 42.47, 'aliases' => ['Habur', 'Habur Sınır']],
        ['p' => 4, 'n' => 'Doğubayazıt', 'lat' => 39.55, 'lng' => 44.08, 'aliases' => ['Gürbulak']],
        ['p' => 22, 'n' => 'Edirne Merkez', 'lat' => 41.68, 'lng' => 26.56, 'aliases' => ['Kapıkule', 'Hamzabeyli']],
        ['p' => 39, 'n' => 'Kırklareli Merkez', 'lat' => 41.74, 'lng' => 27.23, 'aliases' => ['Dereköy']],
        ['p' => 8, 'n' => 'Hopa', 'lat' => 41.41, 'lng' => 41.43, 'aliases' => ['Sarp', 'Sarp Sınır']],
        ['p' => 76, 'n' => 'Aralık', 'lat' => 39.87, 'lng' => 44.52, 'aliases' => ['Dilucu']],
        ['p' => 79, 'n' => 'Kilis Merkez', 'lat' => 36.72, 'lng' => 37.12, 'aliases' => ['Öncüpınar', 'Oncupinar']],
        ['p' => 79, 'n' => 'Elbeyli', 'lat' => 36.68, 'lng' => 37.47, 'aliases' => ['Çobanbey', 'Cobanbey']],
        ['p' => 27, 'n' => 'Karkamış', 'lat' => 36.83, 'lng' => 38.00],
        ['p' => 27, 'n' => 'İslahiye', 'lat' => 37.03, 'lng' => 36.63],
        ['p' => 33, 'n' => 'Akdeniz', 'lat' => 36.80, 'lng' => 34.63, 'aliases' => ['Mersin Liman', 'Mersin Limanı']],
        ['p' => 31, 'n' => 'İskenderun', 'lat' => 36.59, 'lng' => 36.17, 'aliases' => ['İskenderun Liman', 'Limak']],
        ['p' => 55, 'n' => 'Tekkeköy', 'lat' => 41.21, 'lng' => 36.46, 'aliases' => ['Samsun Liman']],
    ];

    private static function load(): array
    {
        if (self::$data === null) {
            self::$data = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/resources/data/tr-locations.json'), true) ?: ['provinces' => [], 'districts' => []];
            self::$provinceIndex = [];
            foreach (self::$data['provinces'] as $i => $p) {
                // Veri dosyasındaki ad kanonik il adına çevrilir ("Afyon" → "Afyonkarahisar"); kısa ad da dizine girer.
                $canonical = TurkishCities::fromText($p['name'], fuzzy: false) ?? $p['name'];
                self::$data['provinces'][$i]['name'] = $canonical;
                self::$provinceIndex[TurkishCities::ascii($canonical)] = $p['code'];
                self::$provinceIndex[TurkishCities::ascii($p['name'])] = $p['code'];
            }
            self::$districtIndex = [];
            foreach (array_merge(self::$data['districts'], self::EXTRA_PLACES) as $d) {
                self::$districtIndex[$d['p']][TurkishCities::ascii($d['n'])] = ['n' => $d['n'], 'lat' => $d['lat'], 'lng' => $d['lng']];
                foreach ($d['aliases'] ?? [] as $alias) {
                    self::$districtIndex[$d['p']][TurkishCities::ascii($alias)] = ['n' => $d['n'], 'lat' => $d['lat'], 'lng' => $d['lng']];
                }
            }
        }

        return self::$data;
    }

    /** @return list<array{code:int,name:string,lat:float,lng:float}> */
    public static function provinces(): array
    {
        return self::load()['provinces'];
    }

    public static function province(int $code): ?array
    {
        foreach (self::provinces() as $p) {
            if ($p['code'] === $code) {
                return $p;
            }
        }

        return null;
    }

    public static function provinceCode(string $name): ?int
    {
        self::load();
        $canonical = TurkishCities::fromText($name) ?? $name;

        return self::$provinceIndex[TurkishCities::ascii($canonical)] ?? null;
    }

    /** @return list<string> */
    public static function districtsOf(int $provinceCode): array
    {
        self::load();
        // Takma adlar (Kazan → Kahramankazan) aynı ilçeyi gösterir; liste tekilleştirilir.
        $names = array_values(array_unique(array_map(fn ($d) => $d['n'], array_values(self::$districtIndex[$provinceCode] ?? []))));
        usort($names, [TurkishText::class, 'compare']);

        return $names;
    }

    /**
     * "İzmir Aliağa", "Aliağa", "Ankara'dan", "Gebze" gibi metinleri il/ilçe ve koordinata çözer.
     *
     * @return array{province_code:int, province:string, district:?string, lat:float, lng:float}|null
     */
    public static function resolve(?string $text, bool $fuzzy = true): ?array
    {
        if ($text === null || trim($text) === '') {
            return null;
        }
        self::load();
        $clean = preg_replace("/[’'‘`]/u", '', trim($text)) ?? trim($text);
        $clean = trim(preg_replace('/[()\[\]]+/u', ' ', $clean) ?? $clean); // "Elmadağ(Ank)", "Bolu (Oyak)"
        // Jargon sözlüğü önce: "ostim" → "Ankara Ostim", "gebze osb" → "Kocaeli Gebze" (yönetici ya da öğrenilmiş).
        static $depth = 0;
        if ($depth === 0 && ($alias = Lexicon::matchLocation($clean)) !== null && Lexicon::normalize($alias) !== Lexicon::normalize($clean)) {
            $depth++;
            try {
                $hit = self::resolve($alias);
            } finally {
                $depth--;
            }
            if ($hit !== null) {
                return $hit;
            }
        }
        $words = preg_split('/[\s,\/\-()]+/u', $clean) ?: [];
        $words = array_values(array_filter($words, fn ($w) => $w !== ''));

        // Sıra: birebir il → tek başına ilçe adı → yazım hatalı il → yazım hatalı ilçe.
        $province = TurkishCities::fromText($clean, fuzzy: false);
        $code = $province ? (self::$provinceIndex[TurkishCities::ascii($province)] ?? null) : null;
        if ($code !== null) {
            return self::withDistrict($code, array_slice($words, 1));
        }

        foreach ($fuzzy ? [false, true] : [false] as $try) {
            foreach (self::$districtIndex as $pCode => $districts) {
                $district = self::matchDistrict($pCode, array_slice($words, 0, 2), $try);
                if ($district !== null && $district['n'] !== 'Merkez') {
                    $p = self::province($pCode);

                    return ['province_code' => $pCode, 'province' => $p['name'], 'district' => $district['n'], 'lat' => $district['lat'], 'lng' => $district['lng']];
                }
            }
            if (! $try && $fuzzy) {
                $province = TurkishCities::fromText($clean, fuzzy: true);
                $code = $province ? (self::$provinceIndex[TurkishCities::ascii($province)] ?? null) : null;
                if ($code !== null) {
                    return self::withDistrict($code, array_slice($words, 1));
                }
            }
        }

        return null;
    }

    /** İl bulunduysa kalan sözcüklerde ilçe arar (önce birebir, sonra yazım hatalı). */
    private static function withDistrict(int $code, array $rest): array
    {
        $district = self::matchDistrict($code, $rest, false) ?? self::matchDistrict($code, $rest, true);
        $p = self::province($code);

        return [
            'province_code' => $code,
            'province' => $p['name'],
            'district' => $district['n'] ?? null,
            'lat' => $district['lat'] ?? $p['lat'],
            'lng' => $district['lng'] ?? $p['lng'],
        ];
    }

    /** @param list<string> $words */
    private static function matchDistrict(int $provinceCode, array $words, bool $fuzzy = false): ?array
    {
        $districts = self::$districtIndex[$provinceCode] ?? [];
        if ($districts === [] || $words === []) {
            return null;
        }
        // İki sözcüklü ilçe adları ("Sultan Beyli" yazımı gibi) için birleşik denemeler
        $candidates = [];
        foreach ($words as $i => $w) {
            $candidates[] = $w;
            if (isset($words[$i + 1])) {
                $candidates[] = $w.$words[$i + 1];
            }
        }
        foreach ($candidates as $cand) {
            $a = TurkishCities::ascii($cand);
            if (strlen($a) < 3) {
                continue;
            }
            if (isset($districts[$a])) {
                return $districts[$a];
            }
            // Ek atma: "aliagaya" → "aliaga", "gebzeden" → "gebze"
            foreach (['indan', 'inden', 'undan', 'unden', 'dan', 'den', 'tan', 'ten', 'da', 'de', 'ta', 'te', 'ya', 'ye', 'na', 'ne', 'a', 'e', 'i', 'u'] as $suffix) {
                if (str_ends_with($a, $suffix)) {
                    $stem = substr($a, 0, -strlen($suffix));
                    if (strlen($stem) >= 4 && isset($districts[$stem])) {
                        return $districts[$stem];
                    }
                }
            }
        }
        if (! $fuzzy) {
            return null;
        }
        // Yazım hatası: tek harf farkı (5+ harf). "cesme" ↔ "çeşme" zaten ascii'de eşittir.
        foreach ($candidates as $cand) {
            $a = TurkishCities::ascii($cand);
            if (strlen($a) < 5) {
                continue;
            }
            foreach ($districts as $ascii => $d) {
                if (abs(strlen($ascii) - strlen($a)) <= 1 && levenshtein($a, $ascii) === 1) {
                    return $d;
                }
            }
        }

        return null;
    }

    /** İki nokta arası kuş uçuşu mesafe (km). */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }
}
