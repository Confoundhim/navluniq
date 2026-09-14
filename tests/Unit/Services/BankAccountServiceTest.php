<?php

namespace Tests\Unit\Services;

use App\Services\BankAccountService;
use PHPUnit\Framework\TestCase;

class BankAccountServiceTest extends TestCase
{
    public function test_valid_turkish_iban_passes_mod97(): void
    {
        $this->assertTrue(BankAccountService::isValidTurkishIban('TR330006100519786457841326'));
        $this->assertTrue(BankAccountService::isValidTurkishIban(BankAccountService::normalize('tr33 0006 1005 1978 6457 8413 26')));
    }

    public function test_invalid_iban_fails(): void
    {
        $this->assertFalse(BankAccountService::isValidTurkishIban('TR330006100519786457841327'));
        $this->assertFalse(BankAccountService::isValidTurkishIban('DE89370400440532013000'));
        $this->assertFalse(BankAccountService::isValidTurkishIban('TR12'));
    }
}
