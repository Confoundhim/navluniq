<?php

namespace Tests\Unit\Services;

use App\Services\GibService;
use PHPUnit\Framework\TestCase;

class GibServiceTest extends TestCase
{
    public function test_checksum_valid_vkn_is_accepted(): void
    {
        $this->assertTrue((new GibService)->verifyTax('6301481858')['is_match']);
    }

    public function test_wrong_length_or_checksum_is_rejected(): void
    {
        $service = new GibService;
        $this->assertFalse($service->verifyTax('630148185')['is_match']);
        $this->assertFalse($service->verifyTax('6301481859')['is_match']);
        $this->assertFalse($service->verifyTax('abcdefghij')['is_match']);
    }
}
