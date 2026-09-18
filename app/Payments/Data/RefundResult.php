<?php

namespace App\Payments\Data;

final class RefundResult
{
    public function __construct(
        public readonly bool $succeeded,
        public readonly string $rawResponse = '',
        public readonly ?string $failureMessage = null,
    ) {}
}
