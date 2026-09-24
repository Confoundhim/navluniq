<?php

namespace App\Support;

use App\Models\AiLexicon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Jargon sözlüğü: veritabanındaki aktif girdileri türe göre önbellekten sunar ve metinde arar.
 * Terimler küçük harf + ASCII saklanır; arama da aynı biçime indirgenmiş metinde yapılır.
 */
final class Lexicon
{
    private const CACHE_KEY = 'ai:lexicon:v1';

    /** @var array<string, array<string, string>>|null kind → [term → canonical] */
    private static ?array $memo = null;

    public static function normalize(string $text): string
    {
        $t = TurkishCities::ascii($text);
        $t = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t) ?? $t;

        return trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
    }

    /** @return array<string, string> term → canonical (kind için) */
    public static function map(string $kind): array
    {
        return self::all()[$kind] ?? [];
    }

    /** @return array<string, array<string, string>> */
    private static function all(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }
        try {
            self::$memo = Cache::remember(self::CACHE_KEY, now()->addMinutes(10), function (): array {
                $out = [];
                foreach (AiLexicon::query()->where('status', 'active')->whereNotNull('term')->get(['kind', 'term', 'canonical']) as $row) {
                    $term = self::normalize((string) $row->term);
                    if ($term !== '') {
                        $out[$row->kind][$term] = (string) ($row->canonical ?? '');
                    }
                }

                return $out;
            });
        } catch (Throwable) {
            self::$memo = [];
        }

        return self::$memo;
    }

    public static function flush(): void
    {
        self::$memo = null;
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Konum kısaltması: metnin tamamı ya da ilk 1-2 sözcüğü sözlükte varsa katalog adını döndürür
     * ("ostim" → "Ankara Ostim", "gebze osb" → "Kocaeli Gebze").
     */
    public static function matchLocation(string $text): ?string
    {
        $map = self::map('location');
        if ($map === []) {
            return null;
        }
        $norm = self::normalize($text);
        if ($norm === '') {
            return null;
        }
        $words = explode(' ', $norm);
        foreach ([$norm, implode(' ', array_slice($words, 0, 2)), $words[0]] as $candidate) {
            if ($candidate !== '' && isset($map[$candidate]) && $map[$candidate] !== '') {
                return $map[$candidate];
            }
        }
        // Ek takılı yazım: "ostimden", "gebzeye"
        foreach ($map as $term => $canonical) {
            if ($canonical !== '' && ! str_contains($term, ' ') && preg_match('/^'.preg_quote($term, '/').'(?:dan|den|tan|ten|ya|ye|a|e|na|ne|nin|nın|in|ın)?$/u', $words[0]) === 1) {
                return $canonical;
            }
        }

        return null;
    }

    /** Sözlükteki bir sözcük/ifade metinde tam sözcük olarak geçiyor mu; geçen ilk girdinin karşılığı. */
    private static function firstHit(string $kind, string $normalizedText): ?array
    {
        $map = self::map($kind);
        if ($map === []) {
            return null;
        }
        $hay = ' '.$normalizedText.' ';
        foreach ($map as $term => $canonical) {
            if ($term !== '' && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'(?:[\p{L}]{0,4})?(?![\p{L}\p{N}])/u', $hay) === 1) {
                return ['term' => $term, 'canonical' => $canonical];
            }
        }

        return null;
    }

    /** Araç tipi anahtarı (VehicleTypes) ya da null. */
    public static function matchVehicle(string $text): ?array
    {
        $hit = self::firstHit('vehicle', self::normalize($text));
        if ($hit !== null) {
            $hit['canonical'] = VehicleTypes::canonical($hit['canonical']);
        }

        return $hit !== null && VehicleTypes::isValid($hit['canonical']) ? $hit : null;
    }

    /** Yük kategorisi anahtarı (GoodsCatalog) ya da null. */
    public static function matchGoods(string $text): ?array
    {
        $hit = self::firstHit('goods', self::normalize($text));

        return $hit !== null && GoodsCatalog::label($hit['canonical']) !== null ? $hit : null;
    }

    /**
     * Kasa sözlüğü: yöneticinin öğrettiği sözcük → kasa tipleri ("kemik" → damperli, "kasalı" → kapali,tenteli,frigo).
     *
     * @return array{term:string, types:list<string>}|null
     */
    public static function matchBody(string $text): ?array
    {
        $hit = self::firstHit('body', self::normalize($text));
        if ($hit === null) {
            return null;
        }
        $types = BodyTypes::clean(array_map('trim', explode(',', $hit['canonical'])));

        return ['term' => $hit['term'], 'types' => $types];
    }

    public static function isNotLoad(string $text): bool
    {
        return self::firstHit('not_load', self::normalize($text)) !== null;
    }

    public static function hasLoadSignal(string $text): bool
    {
        return self::firstHit('load_signal', self::normalize($text)) !== null;
    }

    /** @return list<string> */
    public static function ignoreWords(): array
    {
        return array_keys(self::map('ignore'));
    }
}
