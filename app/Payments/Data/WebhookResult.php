<?php

namespace App\Payments\Data;

/**
 * Ödeme kuruluşundan gelen sunucu bildiriminin doğrulanmış özeti.
 * $valid=false ise imza doğrulanamamıştır; sipariş güncellenmez.
 */
final class WebhookResult
{
    public function __construct(
        public readonly bool $valid,
        public readonly string $merchantOid,
        public readonly string $status,        // success | failed
        public readonly ?float $paidAmount,    // sağlayıcının bildirdiği tutar (TL)
        public readonly string $eventId,
        public readonly array $payload,
        public readonly ?string $providerReference = null,
        public readonly ?string $failureMessage = null,
        public readonly string $ackBody = 'OK', // sağlayıcıya dönülecek yanıt gövdesi
        public readonly string $rejectBody = 'FAILED',
        public readonly bool $redirectUser = false, // true: bildirim kullanıcının tarayıcısından geldi, sonuç sayfasına yönlendir
    ) {}
}
