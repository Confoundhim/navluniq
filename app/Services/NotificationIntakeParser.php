<?php

namespace App\Services;

use App\Support\TurkishCities;

/**
 * Android bildirim iletici (MacroDroid vb.) ile gelen WhatsApp bildirimini ilan mesajlarına ayırır.
 *
 * Grup bildirimlerinde başlık grup adı, metin "Gönderen: mesaj" satırlarıdır. Yeni Android
 * sürümlerinde WhatsApp göndereni ayrı alanda taşır ve metin yalnız mesajın kendisidir; bu durumda
 * metnin tamamı tek mesaj sayılır (gönderen varsa "Gönderen @ Grup: mesaj" biçimli ticker'dan alınır).
 * Özet bildirimleri ("12 mesaj 3 sohbet") atlanır; sohbet/ilan ayrımını ön filtre yapar.
 */
final class NotificationIntakeParser
{
    private const SUMMARY_PATTERNS = [
        '/^\s*\d+\s+(?:yeni\s+)?mesaj/iu',
        '/\d+\s+sohbet(?:ten)?\b/iu',
        '/^\s*\d+\s+new\s+messages?/iu',
        '/^\s*(?:whatsapp|whatsapp business)\s*$/iu',
        '/mesaj(?:lar)?ınız var/iu',
        '/yedekleme|backup|arama|call|görüşme|kaçırılan/iu',
    ];

    /**
     * @param  array{title?:?string, text?:?string, text_big?:?string, ticker?:?string, app?:?string}  $payload
     * @return array{skipped:?string, group:?string, messages:list<array{sender:?string, phone:?string, text:string}>}
     */
    public static function parse(array $payload): array
    {
        $app = trim((string) ($payload['app'] ?? ''));
        if ($app !== '' && ! preg_match('/whatsapp/iu', $app)) {
            return self::skip('not_whatsapp');
        }

        $title = trim((string) ($payload['title'] ?? ''));
        $text = trim((string) ($payload['text_big'] ?? ''));
        if ($text === '') {
            $text = trim((string) ($payload['text'] ?? ''));
        }
        if ($title === '' || $text === '') {
            return self::skip('empty');
        }

        foreach (self::SUMMARY_PATTERNS as $pattern) {
            if (preg_match($pattern, $title) || preg_match($pattern, $text)) {
                return self::skip('summary_notification');
            }
        }

        // "Grup Adı (3 mesaj)" → "Grup Adı"
        $group = trim(preg_replace('/\s*\(\d+\s+(?:yeni\s+)?(?:mesaj|messages?)\)\s*$/iu', '', $title) ?? $title);

        $messages = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^([^:\n]{1,40}?):\s+(.+)$/su', $line, $m) && ! preg_match('/^(?:yük|yuk|fiyat|tonaj|rota|tel|telefon|not|yükleme|teslim|araç|arac)$/iu', trim($m[1]))) {
                $sender = trim($m[1]);
                $messages[] = ['sender' => $sender, 'phone' => self::phoneFrom($sender), 'text' => trim($m[2])];
            } elseif ($messages !== []) {
                $last = array_key_last($messages);
                $messages[$last]['text'] .= "\n".$line; // önceki mesajın devam satırı
            }
        }

        if ($messages === []) {
            // Gönderen öneki yok: metin tek bir mesajdır. Ticker "Gönderen @ Grup: mesaj" biçimindeyse göndereni oradan al.
            $sender = self::senderFromTicker(trim((string) ($payload['ticker'] ?? '')), $group);
            $messages[] = ['sender' => $sender, 'phone' => $sender !== null ? self::phoneFrom($sender) : null, 'text' => $text];
        }

        return ['skipped' => null, 'group' => $group, 'messages' => $messages];
    }

    /** Grup ilan kaynağı için sabit tanımlayıcı: aynı grup adı her zaman aynı kaynağa düşer. */
    public static function sourceIdentifier(string $group): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', TurkishCities::ascii($group)) ?? '';

        return 'notif:'.trim($slug, '-');
    }

    private static function senderFromTicker(string $ticker, string $group): ?string
    {
        if ($ticker === '') {
            return null;
        }
        if (preg_match('/^(.{1,60}?)\s*@\s*(.{1,120}?):\s/su', $ticker, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/^([^:\n]{1,40}?):\s/su', $ticker, $m) && trim($m[1]) !== $group) {
            return trim($m[1]);
        }

        return null;
    }

    private static function phoneFrom(string $sender): ?string
    {
        $digits = preg_replace('/\D+/', '', $sender) ?? '';
        if (str_starts_with($digits, '90') && strlen($digits) === 12) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $digits = substr($digits, 1);
        }

        return preg_match('/^5\d{9}$/', $digits) ? $digits : null;
    }

    private static function skip(string $reason): array
    {
        return ['skipped' => $reason, 'group' => null, 'messages' => []];
    }
}
