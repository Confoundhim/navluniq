<?php

namespace App\Payments\Data;

/**
 * Kayıtlı kartla sunucudan sunucuya yapılan çekimin sonucu (otomatik abonelik yenileme). Sonuç anında döner;
 * bildirim (webhook) beklenmez. $eventId aynı çekimin iki kez işlenmesini önler.
 */
final class ChargeResult
{
    public function __construct(
        public readonly bool $succeeded,
        public readonly string $eventId,
        public readonly array $payload,
        public readonly ?float $paidAmount = null,
        public readonly ?string $providerReference = null,
        public readonly ?string $failureMessage = null,
        public readonly bool $cardInvalid = false, // kart silinmiş/süresi dolmuş: tekrar denemenin anlamı yok
    ) {}
}
