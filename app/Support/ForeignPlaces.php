<?php

namespace App\Support;

/**
 * Yurt dışı varış/kalkış noktaları (Irak, İran, Kafkasya, Orta Asya, Suriye, Balkanlar). İl kataloğunda olmadıkları
 * için ayrı tutulur; ilan "Mersin → Erbil (Irak)" biçiminde standartlaşır ve otomatik onaya girebilir.
 */
final class ForeignPlaces
{
    /** ascii küçük harf anahtar → [görünen ad, ülke, enlem, boylam] */
    public const PLACES = [
        // Irak
        'erbil' => ['Erbil', 'Irak', 36.19, 44.01], 'zaho' => ['Zaho', 'Irak', 37.14, 42.68], 'zakho' => ['Zaho', 'Irak', 37.14, 42.68], 'zaxo' => ['Zaho', 'Irak', 37.14, 42.68],
        'duhok' => ['Duhok', 'Irak', 36.87, 42.99], 'dohuk' => ['Duhok', 'Irak', 36.87, 42.99], 'suleymaniye' => ['Süleymaniye', 'Irak', 35.56, 45.43],
        'musul' => ['Musul', 'Irak', 36.34, 43.13], 'bagdat' => ['Bağdat', 'Irak', 33.31, 44.37], 'basra' => ['Basra', 'Irak', 30.51, 47.82],
        'kerkuk' => ['Kerkük', 'Irak', 35.47, 44.39], 'irak' => ['Irak', 'Irak', 33.31, 44.37], 'ibrahim halil' => ['İbrahim Halil (Zaho)', 'Irak', 37.14, 42.68],
        // İran
        'bazirgan' => ['Bazargan', 'İran', 39.39, 44.39], 'bazergan' => ['Bazargan', 'İran', 39.39, 44.39], 'bazargan' => ['Bazargan', 'İran', 39.39, 44.39],
        'tebriz' => ['Tebriz', 'İran', 38.08, 46.29], 'tabriz' => ['Tebriz', 'İran', 38.08, 46.29], 'tebrız' => ['Tebriz', 'İran', 38.08, 46.29], 'tahran' => ['Tahran', 'İran', 35.69, 51.39], 'urmiye' => ['Urmiye', 'İran', 37.55, 45.08], 'iran' => ['İran', 'İran', 35.69, 51.39],
        // Kafkasya
        'baku' => ['Bakü', 'Azerbaycan', 40.41, 49.87], 'gence' => ['Gence', 'Azerbaycan', 40.68, 46.36], 'azerbaycan' => ['Azerbaycan', 'Azerbaycan', 40.41, 49.87],
        'nahcivan' => ['Nahçıvan', 'Azerbaycan', 39.21, 45.41], 'tiflis' => ['Tiflis', 'Gürcistan', 41.72, 44.79], 'batum' => ['Batum', 'Gürcistan', 41.64, 41.64],
        'gurcistan' => ['Gürcistan', 'Gürcistan', 41.72, 44.79], 'erivan' => ['Erivan', 'Ermenistan', 40.18, 44.51],
        // Orta Asya
        'kazakistan' => ['Kazakistan', 'Kazakistan', 43.24, 76.89], 'almati' => ['Almatı', 'Kazakistan', 43.24, 76.89], 'astana' => ['Astana', 'Kazakistan', 51.17, 71.43],
        'ozbekistan' => ['Özbekistan', 'Özbekistan', 41.31, 69.24], 'taskent' => ['Taşkent', 'Özbekistan', 41.31, 69.24], 'turkmenistan' => ['Türkmenistan', 'Türkmenistan', 37.96, 58.33],
        'askabat' => ['Aşkabat', 'Türkmenistan', 37.96, 58.33], 'kirgizistan' => ['Kırgızistan', 'Kırgızistan', 42.87, 74.59], 'biskek' => ['Bişkek', 'Kırgızistan', 42.87, 74.59],
        'tacikistan' => ['Tacikistan', 'Tacikistan', 38.56, 68.79], 'dusanbe' => ['Duşanbe', 'Tacikistan', 38.56, 68.79], 'afganistan' => ['Afganistan', 'Afganistan', 34.53, 69.17],
        // Suriye / Ürdün / Körfez
        'halep' => ['Halep', 'Suriye', 36.20, 37.16], 'sam' => ['Şam', 'Suriye', 33.51, 36.29], 'kamisli' => ['Kamışlı', 'Suriye', 37.05, 41.22], 'suriye' => ['Suriye', 'Suriye', 36.20, 37.16],
        'amman' => ['Amman', 'Ürdün', 31.95, 35.93], 'urdun' => ['Ürdün', 'Ürdün', 31.95, 35.93], 'beyrut' => ['Beyrut', 'Lübnan', 33.89, 35.50],
        'suudi' => ['Suudi Arabistan', 'Suudi Arabistan', 24.71, 46.68], 'riyad' => ['Riyad', 'Suudi Arabistan', 24.71, 46.68], 'cidde' => ['Cidde', 'Suudi Arabistan', 21.49, 39.19],
        'dubai' => ['Dubai', 'BAE', 25.20, 55.27], 'katar' => ['Katar', 'Katar', 25.29, 51.53], 'kuveyt' => ['Kuveyt', 'Kuveyt', 29.38, 47.99],
        // Rusya / Balkanlar / Avrupa
        'rusya' => ['Rusya', 'Rusya', 55.76, 37.62], 'rostov' => ['Rostov', 'Rusya', 47.24, 39.70], 'krasnodar' => ['Krasnodar', 'Rusya', 45.04, 38.98],
        'nalcik' => ['Nalçik', 'Rusya', 43.49, 43.60], 'saratov' => ['Saratov', 'Rusya', 51.53, 46.03], 'voronej' => ['Voronej', 'Rusya', 51.66, 39.20], 'stavropol' => ['Stavropol', 'Rusya', 45.04, 41.97],
        'mahackale' => ['Mahaçkale', 'Rusya', 42.98, 47.50], 'astrahan' => ['Astrahan', 'Rusya', 46.35, 48.04], 'volgograd' => ['Volgograd', 'Rusya', 48.71, 44.51], 'samara' => ['Samara', 'Rusya', 53.20, 50.15],
        'novorossiysk' => ['Novorossiysk', 'Rusya', 44.72, 37.77], 'soci' => ['Soçi', 'Rusya', 43.60, 39.73], 'minsk' => ['Minsk', 'Belarus', 53.90, 27.57], 'belarus' => ['Belarus', 'Belarus', 53.90, 27.57], 'moskova' => ['Moskova', 'Rusya', 55.76, 37.62], 'petersburg' => ['St. Petersburg', 'Rusya', 59.93, 30.34], 'vladikafkas' => ['Vladikavkaz', 'Rusya', 43.02, 44.68],
        'ukrayna' => ['Ukrayna', 'Ukrayna', 50.45, 30.52], 'bulgaristan' => ['Bulgaristan', 'Bulgaristan', 42.70, 23.32], 'sofya' => ['Sofya', 'Bulgaristan', 42.70, 23.32],
        'romanya' => ['Romanya', 'Romanya', 44.43, 26.10], 'bukres' => ['Bükreş', 'Romanya', 44.43, 26.10], 'yunanistan' => ['Yunanistan', 'Yunanistan', 37.98, 23.73], 'selanik' => ['Selanik', 'Yunanistan', 40.64, 22.94],
        'makedonya' => ['Kuzey Makedonya', 'Kuzey Makedonya', 41.99, 21.43], 'uskup' => ['Üsküp', 'Kuzey Makedonya', 41.99, 21.43], 'sirbistan' => ['Sırbistan', 'Sırbistan', 44.79, 20.45], 'belgrad' => ['Belgrad', 'Sırbistan', 44.79, 20.45],
        'bosna' => ['Bosna-Hersek', 'Bosna-Hersek', 43.86, 18.41], 'kosova' => ['Kosova', 'Kosova', 42.66, 21.17], 'arnavutluk' => ['Arnavutluk', 'Arnavutluk', 41.33, 19.82],
        'almanya' => ['Almanya', 'Almanya', 52.52, 13.41], 'avusturya' => ['Avusturya', 'Avusturya', 48.21, 16.37], 'italya' => ['İtalya', 'İtalya', 41.90, 12.50], 'fransa' => ['Fransa', 'Fransa', 48.86, 2.35],
        'hollanda' => ['Hollanda', 'Hollanda', 52.37, 4.90], 'belcika' => ['Belçika', 'Belçika', 50.85, 4.35], 'polonya' => ['Polonya', 'Polonya', 52.23, 21.01], 'macaristan' => ['Macaristan', 'Macaristan', 47.50, 19.04],
        'ingiltere' => ['İngiltere', 'İngiltere', 51.51, -0.13], 'ispanya' => ['İspanya', 'İspanya', 40.42, -3.70], 'kibris' => ['KKTC', 'KKTC', 35.19, 33.38], 'kktc' => ['KKTC', 'KKTC', 35.19, 33.38], 'lefkosa' => ['Lefkoşa', 'KKTC', 35.19, 33.38],
        'libya' => ['Libya', 'Libya', 32.89, 13.19], 'misir' => ['Mısır', 'Mısır', 30.04, 31.24], 'cezayir' => ['Cezayir', 'Cezayir', 36.75, 3.06], 'tunus' => ['Tunus', 'Tunus', 36.81, 10.18], 'fas' => ['Fas', 'Fas', 33.97, -6.85],
    ];

    /**
     * Metnin ilk 1-2 sözcüğü yurt dışı bir yer mi? Ek takılı yazım da denenir ("Erbile", "Zahodan").
     *
     * @return array{name:string, country:string, lat:float, lng:float, label:string}|null
     */
    public static function match(?string $text): ?array
    {
        if ($text === null || trim($text) === '') {
            return null;
        }
        $words = array_values(array_filter(preg_split('/[\s,\/()\-]+/u', preg_replace("/[’'‘`]/u", '', trim($text)) ?? $text) ?: [], fn ($w) => $w !== ''));
        $candidates = [];
        if (isset($words[0], $words[1])) {
            $candidates[] = TurkishCities::ascii($words[0]).' '.TurkishCities::ascii($words[1]);
        }
        if (isset($words[0])) {
            $candidates[] = TurkishCities::ascii($words[0]);
        }
        foreach ($candidates as $key) {
            if (isset(self::PLACES[$key])) {
                return self::build($key);
            }
            foreach (['ndan', 'nden', 'dan', 'den', 'tan', 'ten', 'ya', 'ye', 'na', 'ne', 'a', 'e', 'i', 'u'] as $suffix) {
                if (str_ends_with($key, $suffix)) {
                    $stem = substr($key, 0, -strlen($suffix));
                    if (strlen($stem) >= 4 && isset(self::PLACES[$stem])) {
                        return self::build($stem);
                    }
                }
            }
        }

        return null;
    }

    private static function build(string $key): array
    {
        [$name, $country, $lat, $lng] = self::PLACES[$key];

        return ['name' => $name, 'country' => $country, 'lat' => $lat, 'lng' => $lng, 'label' => $name === $country ? $country : $name.' ('.$country.')'];
    }

    /** Görünen etiket yurt dışı bir yer mi ("Erbil (Irak)")? */
    public static function isForeignLabel(?string $label): bool
    {
        return is_string($label) && self::match($label) !== null;
    }
}
