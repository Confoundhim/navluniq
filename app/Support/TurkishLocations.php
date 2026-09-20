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
            foreach (self::$data['districts'] as $d) {
                self::$districtIndex[$d['p']][TurkishCities::ascii($d['n'])] = ['n' => $d['n'], 'lat' => $d['lat'], 'lng' => $d['lng']];
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
        $names = array_map(fn ($d) => $d['n'], array_values(self::$districtIndex[$provinceCode] ?? []));
        sort($names, SORT_LOCALE_STRING);

        return $names;
    }

    /**
     * "İzmir Aliağa", "Aliağa", "Ankara'dan", "Gebze" gibi metinleri il/ilçe ve koordinata çözer.
     *
     * @return array{province_code:int, province:string, district:?string, lat:float, lng:float}|null
     */
    public static function resolve(?string $text): ?array
    {
        if ($text === null || trim($text) === '') {
            return null;
        }
        self::load();
        $clean = preg_replace("/[’'‘`]/u", '', trim($text)) ?? trim($text);
        $words = preg_split('/[\s,\/\-]+/u', $clean) ?: [];
        $words = array_values(array_filter($words, fn ($w) => $w !== ''));

        // Sıra: birebir il → tek başına ilçe adı → yazım hatalı il → yazım hatalı ilçe.
        $province = TurkishCities::fromText($clean, fuzzy: false);
        $code = $province ? (self::$provinceIndex[TurkishCities::ascii($province)] ?? null) : null;
        if ($code !== null) {
            return self::withDistrict($code, array_slice($words, 1));
        }

        foreach ([false, true] as $fuzzy) {
            foreach (self::$districtIndex as $pCode => $districts) {
                $district = self::matchDistrict($pCode, array_slice($words, 0, 2), $fuzzy);
                if ($district !== null && $district['n'] !== 'Merkez') {
                    $p = self::province($pCode);

                    return ['province_code' => $pCode, 'province' => $p['name'], 'district' => $district['n'], 'lat' => $district['lat'], 'lng' => $district['lng']];
                }
            }
            if (! $fuzzy) {
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
