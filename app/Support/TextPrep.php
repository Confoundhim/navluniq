<?php

namespace App\Support;

/**
 * WhatsApp ilan metnini kural ayrıştırıcı için hazırlar: biçim işaretleri (*kalın*, _eğik_), süs satırları
 * (➖➖➖, ━━━, 🔥🔥), emoji oklar (➡️ 👉 🔹 🟰 ▶ …), harf aralıklı sözcükler ("T I R"), "…" ve "__" bağlaçları.
 * Amaç: farklı komisyoncuların yüzlerce görsel biçimini aynı düz yazıma indirgemek.
 */
final class TextPrep
{
    /** Emoji/simge oklar ve rota ayırıcıları → " -> " */
    private const ARROW_CHARS = '(?:[\x{27A1}-\x{27BF}]|➡️|➡|⮕|➔|➜|➝|➞|➟|➠|➥|➦|➧|➨|⇒|⇨|→|⟶|ᯓ|▶️|▶|►|⏩|👉🏻|👉|↘️|↘|🔹|🔸|🟰|🔰|--》|—》|-》|》|»|>>+|=>|➖>|<)';

    /** Satır içindeki oklar (yeni satırı yutmaz: her satır ayrı rota olabilir) */
    private const ARROWS = '/[ \t]*'.self::ARROW_CHARS.'+[ \t]*/u';

    /** Satır başındaki ok = madde işareti ("➡️ANKARA KAPALI TIR"); bağlaç değildir */
    private const LEADING_ARROWS = '/^[ \t]*'.self::ARROW_CHARS.'+[ \t]*/mu';

    public static function prepare(string $text): string
    {
        // Görünüm seçicileri (U+FE0F) ve yön işaretleri atılır; "➡️" ile "➡" aynı karakter olur
        $t = str_replace(["\r\n", "\r", "\u{200E}", "\u{200F}", "\u{202F}", "\u{00A0}", "\u{FE0F}", "\u{FE0E}", "\u{2060}", "\u{FEFF}"], ["\n", "\n", '', '', ' ', ' ', '', '', '', ''], $text);
        // Kesme/backtick işaretleri ("Tarsus'tan", "BİMS`DEN", "Adapazarın,dan") sözcüğe bitişir
        $t = preg_replace('/(?<=\p{L})[’\'‘`´,](?=(?:dan|den|tan|ten|ya|ye|a|e|na|ne|dan|de|da)\b)/iu', '', $t) ?? $t;
        $t = preg_replace('/(?<=\p{L})[’\'‘`´]\s?(?=\p{L})/u', '', $t) ?? $t;
        // Bağlaç olarak kullanılan çift alt çizgi / üç nokta: "ESKİŞEHİR__ BAZIRGAN", "KAYSERİ...ZAHO"
        $t = preg_replace('/(?<=[\p{L}\p{N})])\s*(?:_{2,}|\.{2,}|…)\s*(?=[\p{L}\p{N}(])/u', ' -> ', $t) ?? $t;
        // Kalın/eğik işaretleri
        $t = preg_replace('/(?<!\p{L})[*_~]+|[*_~]+(?!\p{L})/u', '', $t) ?? $t;
        $t = preg_replace(self::LEADING_ARROWS, '', $t) ?? $t;
        $t = preg_replace(self::ARROWS, ' -> ', $t) ?? $t;
        // "—>>", "-- ->", "→ -" gibi ok+çizgi karışımları ve çift tire tek bağlaca iner
        $t = preg_replace('/[ \t]*[—–\-]+[ \t]*->|->[ \t]*[—–\-]+/u', ' -> ', $t) ?? $t;
        $t = preg_replace('/(?<=[\p{L}\p{N})])[ \t]*[—–\-]{2,}[ \t]*(?=[\p{L}\p{N}(])/u', ' - ', $t) ?? $t;
        // İki yer adı arasında boşluksuz süs emojisi ("MERSİN📍MİDYAT", "GÜRCİSTAN✅CİLVEGÖZÜ", "ᴋɪᴢɪʟᴛᴇᴘᴇ❇️ᴋᴏɴʏᴀ") bağlaçtır
        $t = preg_replace('/(?<=\p{L})[\p{So}\x{FE0F}\x{200D}]+(?=\p{L})/u', ' -> ', $t) ?? $t;
        $t = str_replace(["\u{2060}", "\u{FEFF}"], '', $t);
        // "T I R", "O R D U": tek harflerle aralıklı yazım birleşir (3+ harf)
        $t = preg_replace_callback('/(?<!\p{L})(\p{L}(?: \p{L}){2,})(?!\p{L})/u', fn ($m) => str_replace(' ', '', $m[1]), $t) ?? $t;
        $lines = [];
        foreach (explode("\n", $t) as $line) {
            $line = rtrim($line);
            // Süs satırı: harf ve rakam içermiyor (➖➖➖, ━━━━, ----, 🔥🔥, 👇👇) → boş satır (blok ayırıcı)
            if ($line !== '' && ! preg_match('/[\p{L}\p{N}]/u', $line)) {
                $line = '';
            }
            // Satır başı/sonu süs karakterleri (📍, 🚛, ❌, ♦, •, -) atılır; rakam ve harf korunur
            $line = preg_replace('/^[\s\p{So}\p{Sk}\p{Sm}\p{Po}\p{Pd}\x{FE0F}\x{200D}]+(?=[\p{L}\p{N}(])/u', '', $line) ?? $line;
            $line = preg_replace('/(?<=[\p{L}\p{N}).!?])[\s\p{So}\x{FE0F}\x{200D}]+$/u', '', $line) ?? $line;
            $lines[] = trim($line);
        }
        $t = implode("\n", $lines);
        $t = preg_replace('/[ \t]{2,}/u', '  ', $t) ?? $t;

        return trim(preg_replace('/\n{3,}/u', "\n\n", $t) ?? $t);
    }

    /** Harflerin çoğu Latin dışı (Kiril, Arap, Fars…) ise Türkçe ilan değildir. */
    public static function isForeignScript(string $text): bool
    {
        $latin = preg_match_all('/\p{Latin}/u', $text);
        $other = preg_match_all('/[\p{Cyrillic}\p{Arabic}\p{Greek}\p{Hebrew}]/u', $text);

        return $other > 20 && $other > $latin * 2;
    }
}
