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
 *
 * Facebook grup bildirimleri de aynı iletici ile gelir (uygulama adı "Facebook"): "Ad Soyad, Grup Adı grubunda
 * paylaştı: metin" ya da başlık grup adı, metin gönderi. Yorum / beğeni / arkadaşlık bildirimleri atlanır; gönderi
 * metni olmayan bildirim ("... grubunda paylaştı" kadar) atlanır. Facebook uzun gönderiyi "…" ile kısaltır; kalan
 * tekrar denetimi (aynı numara + rota) WhatsApp'ta da gelen aynı ilanı tek kayıtta birleştirir.
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

    /** Facebook'ta ilan olmayan bildirimler (yorum, beğeni, arkadaşlık, etkinlik…). */
    private const FACEBOOK_SKIP = '/yorum\s+yaptı|yorumladı|beğendi|tepki\s+verdi|arkadaşlık|etiketledi|bahsetti|doğum\s+gün|hatırlat|canlı\s+yayın|etkinlik|anı(?:nız|ları)|commented|reacted|liked|friend\s+request|tagged|mentioned|birthday|memories|is\s+live/iu';

    /**
     * @param  array{title?:?string, text?:?string, text_big?:?string, ticker?:?string, app?:?string}  $payload
     * @return array{skipped:?string, group:?string, platform:string, messages:list<array{sender:?string, phone:?string, text:string}>}
     */
    public static function parse(array $payload): array
    {
        $app = trim((string) ($payload['app'] ?? ''));
        if (preg_match('/facebook/iu', $app)) {
            return self::parseFacebook($payload);
        }
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

        // Başlık biçimleri: "Grup", "Grup (3 mesaj)", "Grup (3 mesaj): Gönderen", "Grup: Gönderen", "Gönderen @ Grup".
        [$group, $titleSender] = self::splitTitle($title);

        $messages = [];
        if ($titleSender !== null) {
            // Gönderen başlıkta: metin tek mesajdır, satırlar "Gönderen: mesaj" diye bölünmez
            // (aksi halde "Ankara: İzmir 24 ton" gibi satırlar gönderen sanılır).
            $messages[] = ['sender' => $titleSender, 'phone' => self::phoneFrom($titleSender), 'text' => $text];
        } else {
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
        }

        if ($messages === []) {
            // Gönderen öneki yok: metin tek bir mesajdır. Ticker "Gönderen @ Grup: mesaj" biçimindeyse göndereni oradan al.
            $sender = self::senderFromTicker(trim((string) ($payload['ticker'] ?? '')), $group);
            $messages[] = ['sender' => $sender, 'phone' => $sender !== null ? self::phoneFrom($sender) : null, 'text' => $text];
        }

        return ['skipped' => null, 'group' => $group, 'platform' => 'whatsapp', 'messages' => $messages];
    }

    /**
     * Facebook grup gönderisi bildirimi. Biçimler:
     *  - başlık "Facebook", metin "Ad Soyad, Grup Adı grubunda paylaştı: gönderi"
     *  - başlık "Grup Adı", metin "Ad Soyad: gönderi" ya da "Ad Soyad gönderi paylaştı: gönderi"
     *  - metin "Ad Soyad posted in Grup Adı: gönderi" (İngilizce arayüz)
     * Gönderinin tamamı text_big'te ise o kullanılır.
     */
    private static function parseFacebook(array $payload): array
    {
        $title = trim((string) ($payload['title'] ?? ''));
        $text = trim((string) ($payload['text'] ?? ''));
        $big = trim((string) ($payload['text_big'] ?? ''));
        if (mb_strlen($big) > mb_strlen($text)) {
            $text = $big;
        }
        if ($text === '') {
            return self::skip('empty');
        }
        if (preg_match(self::FACEBOOK_SKIP, $title.' '.mb_substr($text, 0, 160))) {
            return self::skip('facebook_not_post');
        }
        $titleIsApp = $title === '' || preg_match('/^facebook(?:\s+lite)?$/iu', $title) === 1;
        $group = null;
        $sender = null;
        $body = null;

        if (preg_match('/^(?<s>.{1,80}?),\s+(?<g>.{1,120}?)\s+grubunda\s+(?:yeni\s+bir\s+)?(?:gönderi\s+|bir\s+şey\s+)?paylaştı\s*[:\-–]?\s*(?<t>.*)$/su', $text, $m)
            || preg_match('/^(?<s>.{1,80}?)\s+posted\s+in\s+(?<g>.{1,120}?)\s*[:\-–]\s*(?<t>.*)$/su', $text, $m)) {
            $sender = trim($m['s']);
            $group = trim($m['g']);
            $body = trim($m['t']);
        } elseif (preg_match('/^(?<h>.{1,200}?)\s+grubunda\s+(?:yeni\s+bir\s+)?(?:gönderi\s+|bir\s+şey\s+)?paylaştı\s*[:\-–]?\s*(?<t>.*)$/su', $text, $m)) {
            // "Ad Soyad Grup Adı grubunda paylaştı": gönderen ile grup ayrılamaz; grup adı başlıktan, yoksa başlık cümlesinden
            $group = $titleIsApp ? trim($m['h']) : $title;
            $body = trim($m['t']);
        } elseif (! $titleIsApp) {
            $group = $title;
            $body = $text;
            if (preg_match('/^(?<s>[^:\n]{1,60}?)\s*(?:gönderi\s+paylaştı|paylaştı)?\s*:\s+(?<t>.+)$/su', $text, $m) && ! preg_match('/^(?:yük|yuk|fiyat|tonaj|rota|tel|telefon|not|yükleme|teslim|araç|arac)$/iu', trim($m['s']))) {
                $sender = trim($m['s']);
                $body = trim($m['t']);
            }
        } else {
            return self::skip('facebook_no_group');
        }

        $body = trim(preg_replace('/^[«"“]+|[»"”]+$/u', '', trim((string) $body)) ?? '');
        $body = trim(preg_replace('/\s*(?:…|\.\.\.)\s*$/u', '', $body) ?? $body); // kısaltma işareti
        if ($group === null || $group === '' || mb_strlen($body) < 12) {
            return self::skip('facebook_no_body');
        }

        return ['skipped' => null, 'group' => $group, 'platform' => 'facebook', 'messages' => [['sender' => $sender, 'phone' => null, 'text' => $body]]];
    }

    /** Grup ilan kaynağı için sabit tanımlayıcı: aynı grup adı her zaman aynı kaynağa düşer (Facebook: fb:, WhatsApp: notif:). */
    public static function sourceIdentifier(string $group, string $platform = 'whatsapp'): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', TurkishCities::ascii($group)) ?? '';

        return ($platform === 'facebook' ? 'fb:' : 'notif:').trim($slug, '-');
    }

    /**
     * Bildirim başlığını grup adı ve (varsa) gönderene ayırır.
     *
     * @return array{0:string, 1:?string}
     */
    public static function splitTitle(string $title): array
    {
        // "(3 mesaj)" / "(2 new messages)" parçası nerede olursa olsun atılır.
        $clean = trim(preg_replace('/\s*\(\d+\s+(?:yeni\s+)?(?:mesaj|new\s+messages?|messages?)\)\s*/iu', ' ', $title) ?? $title);
        $clean = trim(preg_replace('/\s{2,}/u', ' ', $clean) ?? $clean);

        if (preg_match('/^(.{1,80}?)\s+@\s+(.{1,120})$/su', $clean, $m)) {
            return [trim($m[2]), trim($m[1])]; // "Gönderen @ Grup"
        }
        if (preg_match('/^(.{1,120}?)\s*:\s+(.{1,80})$/su', $clean, $m)) {
            return [trim($m[1]), trim($m[2])]; // "Grup: Gönderen"
        }

        return [$clean, null];
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
        return ['skipped' => $reason, 'group' => null, 'platform' => 'whatsapp', 'messages' => []];
    }
}
