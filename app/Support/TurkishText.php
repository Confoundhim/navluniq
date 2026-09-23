<?php

namespace App\Support;

/**
 * Türkçeye uygun büyük/küçük harf çevirisi ve serbest metin temizliği.
 *
 * PHP'nin mb_convert_case'i "İ" harfini "i̇" (i + birleşik nokta) yapar, "I" harfini "i" yapar; ilan metinleri
 * ("İZMİR", "PALETLİ YÜK") bozuk görünür. Burada İ↔i ve I↔ı eşlemesi doğru yapılır. Şoförün gördüğü her alan
 * (yük türü, yer adı, teslim noktası) tek biçimde yazılır: yer adları sözcük başı büyük, yük türü cümle biçimi.
 */
final class TurkishText
{
    public static function lower(string $text): string
    {
        return mb_strtolower(str_replace(['İ', 'I'], ['i', 'ı'], $text), 'UTF-8');
    }

    public static function upper(string $text): string
    {
        return mb_strtoupper(str_replace(['i', 'ı'], ['İ', 'I'], $text), 'UTF-8');
    }

    /** Boşlukları sadeleştirir, süs karakterlerini (emoji, ‼, ★, tekrarlı noktalama) atar. */
    public static function clean(?string $text, int $limit = 120): ?string
    {
        if ($text === null) {
            return null;
        }
        $t = preg_replace('/[\p{So}\p{Cs}\x{FE0F}\x{200D}\x{200E}\x{200F}]+/u', ' ', $text) ?? $text; // emoji ve simgeler
        $t = preg_replace('/[!¡‼⁉?¿*_~#]+/u', ' ', $t) ?? $t; // vurgu işaretleri
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
        $t = trim($t, " \t\n\r\0\x0B-–—:;,./");
        if ($t === '') {
            return null;
        }

        return mb_substr($t, 0, $limit, 'UTF-8');
    }

    /** Sözcük başları büyük ("İSTANBUL KARTAL" → "İstanbul Kartal"); parantez ve tire sonrası da büyük. */
    public static function title(?string $text, int $limit = 120): ?string
    {
        $t = self::clean($text, $limit);
        if ($t === null) {
            return null;
        }
        $lower = self::lower($t);

        return preg_replace_callback('/(^|[\s(\-\/])(\p{Ll})/u', fn ($m) => $m[1].self::upper($m[2]), $lower) ?? $lower;
    }

    /** Cümle biçimi: yalnız ilk harf büyük ("PALETLİ YÜK" → "Paletli yük"); kısaltmalar (ADR, OSB, PVC, MDF) korunur. */
    public static function sentence(?string $text, int $limit = 120): ?string
    {
        $t = self::clean($text, $limit);
        if ($t === null) {
            return null;
        }
        $words = explode(' ', $t);
        foreach ($words as $i => $w) {
            $letters = preg_replace('/[^\p{L}]/u', '', $w) ?? $w;
            $isAcronym = mb_strlen($letters) >= 2 && mb_strlen($letters) <= 4 && $letters === self::upper($letters) && in_array(self::lower($letters), self::ACRONYMS, true);
            if ($isAcronym) {
                continue;
            }
            $words[$i] = self::lower($w);
        }
        $out = implode(' ', $words);

        return preg_replace_callback('/^(\p{Ll})/u', fn ($m) => self::upper($m[1]), $out) ?? $out;
    }

    /** Türk alfabesi sırası (ç c'den, ş s'den, ı i'den önce vb.); sunucuda intl/locale gerekmez. */
    private const ALPHABET = 'aâbcçdefgğhıiîjklmnoöprsştuüûvyzqwx';

    /** Türkçe alfabe sırasına göre karşılaştırma (usort için). */
    public static function compare(string $a, string $b): int
    {
        return strcmp(self::sortKey($a), self::sortKey($b));
    }

    /** Sıralama anahtarı: her harf alfabedeki sırasına göre iki haneli koda çevrilir. */
    public static function sortKey(string $text): string
    {
        $out = '';
        foreach (mb_str_split(self::lower($text), 1, 'UTF-8') as $ch) {
            $pos = mb_strpos(self::ALPHABET, $ch, 0, 'UTF-8');
            $out .= $pos === false ? ($ch === ' ' ? '00' : '99'.$ch) : str_pad((string) ($pos + 1), 2, '0', STR_PAD_LEFT);
        }

        return $out;
    }

    /** Cümle biçiminde büyük kalması gereken kısaltmalar. */
    private const ACRONYMS = ['adr', 'osb', 'pvc', 'mdf', 'osb', 'tır', 'tir', 'lpg', 'cng', 'ips', 'oem', 'abs', 'pe', 'pp', 'pet', 'ytong', 'dap', 'npk', 'led', 'tv', 'kdv', 'usd', 'eur', 'try', 'ftl', 'ltl'];
}
