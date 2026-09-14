<?php

namespace Tests\Feature\Smoke;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class TranslationTest extends TestCase
{
    public function test_validation_messages_are_translated_to_turkish(): void
    {
        $validator = Validator::make(['upload_file' => null], ['upload_file' => 'required|file']);

        $message = $validator->errors()->first('upload_file');

        $this->assertStringNotContainsString('validation.', $message);
        $this->assertSame('belge alanı zorunludur.', $message);
    }

    public function test_every_default_validation_key_has_a_turkish_message(): void
    {
        $tr = require base_path('lang/tr/validation.php');
        $en = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');

        $missing = array_diff(array_keys($en), array_keys($tr));

        $this->assertSame([], array_values($missing), 'Türkçe karşılığı olmayan doğrulama anahtarları var.');
    }
}
