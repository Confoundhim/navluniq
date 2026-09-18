<?php

namespace App\Payments\Data;

/**
 * Ödeme kuruluşunun kullanıcıya gösterilecek ödeme ekranı: iframe (sayfa içinde) ya da redirect (yönlendirme).
 */
final class Checkout
{
    public function __construct(
        public readonly string $type,      // iframe | redirect
        public readonly string $url,
        public readonly ?string $token = null,
        public readonly int $expiresInSeconds = 1500,
        public readonly ?string $resizerScript = null, // iframe yükseklik betiği (varsa)
    ) {}
}
