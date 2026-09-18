<?php

namespace App\Payments\Data;

/** Pazaryeri modelinde alt üye işyerine (şoför) yapılan aktarımın sonucu. */
final class TransferResult
{
    public function __construct(
        public readonly bool $succeeded,
        public readonly ?string $reference = null,
        public readonly ?string $failureMessage = null,
    ) {}
}
