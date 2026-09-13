<?php

namespace Tests\Unit\Services;

use App\Support\Phone;
use PHPUnit\Framework\TestCase;

class PhoneTest extends TestCase
{
    public function test_normalizes_common_turkish_formats(): void
    {
        $this->assertSame('5321234567', Phone::normalize('0532 123 45 67'));
        $this->assertSame('5321234567', Phone::normalize('+90 532 123 45 67'));
        $this->assertSame('5321234567', Phone::normalize('905321234567'));
        $this->assertSame('5321234567', Phone::normalize('5321234567'));
    }

    public function test_rejects_non_mobile_numbers(): void
    {
        $this->assertNull(Phone::normalize('0312 123 45 67'));
        $this->assertNull(Phone::normalize('abc'));
        $this->assertNull(Phone::normalize(''));
    }

    public function test_validation_rule_accepts_spaced_and_compact_formats(): void
    {
        $regex = substr(Phone::RULE, 6);
        foreach (['0532 123 45 67', '05321234567', '+90 532 123 45 67', '5321234567', '0532-123-45-67'] as $ok) {
            $this->assertMatchesRegularExpression($regex, $ok, $ok);
        }
        foreach (['0312 123 45 67', '532123456', 'abc'] as $bad) {
            $this->assertDoesNotMatchRegularExpression($regex, $bad, $bad);
        }
    }

    public function test_formats_for_display(): void
    {
        $this->assertSame('0532 123 45 67', Phone::format('5321234567'));
    }
}
