<?php

namespace Tests;

use App\Support\Settings;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Yük sahibi doğrulama zorunluluğu canlıda açık (Settings::DEFAULTS). Testlerin çoğu doğrulanmamış yük sahibiyle teklif kabul eder;
        // zorunluluk burada kapatılır, doğrulama paketini sınayan testler kendi setUp'ında açar (VerificationTest).
        if (Schema::hasTable('cms_contents')) {
            Settings::set('cargo_owner_verification_required', '0');
            // Canlıda navlun doğrudan taraflar arasında ödenir (Settings::DEFAULTS 'direct'). Ödeme/hakediş/iade testleri platform kipini
            // sınar; doğrudan kipi DirectFreightPaymentTest kendi setUp'ında açar.
            Settings::set('freight_payment_mode', 'platform');
        }
    }
}
