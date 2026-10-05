<?php

namespace App\Support;

/**
 * Türkiye telefon numaralarını tek biçime indirger.
 *
 * İki ayrı kavram vardır:
 *  - Hesap numarası (üye, personel, iletişim formu): yalnız cep (5XX); SMS doğrulaması ve WhatsApp bu numaraya gider.
 *    `normalize` / `RULE` bu kapıdır.
 *  - İlan iletişim numarası (gruptan derlenen ilanlar): cep, sabit hat (0212…, 0312…), kurumsal hat (0850, 0800) ve
 *    444'lü kısa numara olabilir. `normalizeContact` bu kapıdır; nakliye firmaları çağrı merkezi numarasıyla ilan verir
 *    (2026-10-05'e kadar yalnız cep alınıyordu, 0850'li ilanlar "numara yok" diye düşüyordu).
 *
 * Veritabanında numaralar başında sıfır olmadan tutulur ("5XXXXXXXXX", "212XXXXXXX", "850XXXXXXX", "444XXXX").
 */
final class Phone
{
    public const RULE = 'regex:/^(\+?90[\s-]?|0)?5\d{2}[\s-]?\d{3}[\s-]?\d{2}[\s-]?\d{2}$/';

    /** Cep: 5 ile başlayan 10 hane. */
    private const MOBILE = '/^5\d{9}$/';

    /** Sabit hat (2xx/3xx/4xx alan kodu) ve kurumsal hat (850, 800): 10 hane. */
    private const LANDLINE = '/^(?:[234]\d{2}|850|800)\d{7}$/';

    /** 444'lü kısa kurumsal numara: 7 hane ("444 1 234"). */
    private const SHORT_444 = '/^444\d{4}$/';

    /** Hesap numarası: yalnız cep. */
    public static function normalize(?string $raw): ?string
    {
        $digits = self::digits($raw);

        return preg_match(self::MOBILE, $digits) ? $digits : null;
    }

    /** İlan iletişim numarası: cep, sabit hat, 0850/0800 ve 444'lü numara. */
    public static function normalizeContact(?string $raw): ?string
    {
        $digits = self::digits($raw);

        return self::isContact($digits) ? $digits : null;
    }

    /** Normalleştirilmiş (başında sıfırsız) numara ilan iletişim numarası olarak geçerli mi? */
    public static function isContact(?string $normalized): bool
    {
        $n = (string) $normalized;

        return preg_match(self::MOBILE, $n) === 1 || preg_match(self::LANDLINE, $n) === 1 || preg_match(self::SHORT_444, $n) === 1;
    }

    public static function isMobile(?string $normalized): bool
    {
        return preg_match(self::MOBILE, (string) $normalized) === 1;
    }

    /** Sabit hat (2xx/3xx/4xx), kurumsal hat (850/800) ve 444: cep değil. */
    public static function isLandline(?string $normalized): bool
    {
        return $normalized !== null && $normalized !== '' && ! self::isMobile($normalized) && self::isContact($normalized);
    }

    /** Kart üstündeki küçük etiket: cep için boş, ötekilerde hat türü. */
    public static function kindLabel(?string $normalized): ?string
    {
        $n = (string) $normalized;

        return match (true) {
            self::isMobile($n) => null,
            str_starts_with($n, '850') => 'Kurumsal hat (0850)',
            str_starts_with($n, '800') => 'Ücretsiz hat (0800)',
            preg_match(self::SHORT_444, $n) === 1 => 'Çağrı merkezi (444)',
            self::isLandline($n) => 'Sabit hat',
            default => null,
        };
    }

    /** WhatsApp bağlantısı yalnız cep ve 0850'de (WhatsApp Business 0850'yi kabul eder); klasik sabit hat ve 444'te yok. */
    public static function supportsWhatsapp(?string $normalized): bool
    {
        $n = (string) $normalized;

        return self::isMobile($n) || str_starts_with($n, '850');
    }

    /** "tel:" bağlantısı: 10 haneli numaralar +90 ile, 444'lü kısa numara olduğu gibi. */
    public static function telHref(?string $normalized): string
    {
        $n = (string) $normalized;

        return preg_match(self::SHORT_444, $n) === 1 ? 'tel:'.$n : 'tel:+90'.$n;
    }

    /** Görüntüleme biçimi: 0 5XX XXX XX XX, 0212 345 67 89, 0850 222 33 44, 444 1 234. */
    public static function format(?string $normalized): string
    {
        $n = (string) $normalized;
        if (preg_match(self::SHORT_444, $n) === 1) {
            return substr($n, 0, 3).' '.substr($n, 3, 1).' '.substr($n, 4, 3);
        }
        if (strlen($n) !== 10) {
            return $n;
        }

        return '0'.substr($n, 0, 3).' '.substr($n, 3, 3).' '.substr($n, 6, 2).' '.substr($n, 8, 2);
    }

    /** Veritabanında hem "5XX..." hem "05XX..." olarak saklanmış eski kayıtları yakalar. */
    public static function variants(string $normalized): array
    {
        return [$normalized, '0'.$normalized, '90'.$normalized, '+90'.$normalized];
    }

    /** Ülke kodu ve baştaki sıfır atılmış rakamlar. 444'lü numarada sıfır yoktur. */
    private static function digits(?string $raw): string
    {
        $digits = preg_replace('/\D/', '', (string) $raw) ?? '';

        if (str_starts_with($digits, '90') && strlen($digits) === 12) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $digits = substr($digits, 1);
        }

        return $digits;
    }
}
