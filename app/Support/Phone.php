<?php

namespace App\Support;

/**
 * Türkiye cep telefonu numaralarını tek biçime indirger.
 * Veritabanında numaralar 10 haneli ("5XXXXXXXXX") tutulur.
 */
final class Phone
{
    public const RULE = 'regex:/^(\+?90|0)?5\d{9}$/';

    public static function normalize(?string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $raw);

        if (str_starts_with($digits, '90') && strlen($digits) === 12) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $digits = substr($digits, 1);
        }

        return preg_match('/^5\d{9}$/', $digits) ? $digits : null;
    }

    /** Görüntüleme biçimi: 0 5XX XXX XX XX */
    public static function format(?string $normalized): string
    {
        if (! $normalized || strlen($normalized) !== 10) {
            return (string) $normalized;
        }

        return '0'.substr($normalized, 0, 3).' '.substr($normalized, 3, 3).' '.substr($normalized, 6, 2).' '.substr($normalized, 8, 2);
    }

    /** Veritabanında hem "5XX..." hem "05XX..." olarak saklanmış eski kayıtları yakalar. */
    public static function variants(string $normalized): array
    {
        return [$normalized, '0'.$normalized, '90'.$normalized, '+90'.$normalized];
    }
}
