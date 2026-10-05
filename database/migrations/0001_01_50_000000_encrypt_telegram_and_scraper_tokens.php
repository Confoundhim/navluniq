<?php

use App\Models\CmsContent;
use App\Support\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

/**
 * Telegram bot anahtarı ve telefon (bildirim iletici) anahtarı düz metin saklanıyordu (denetim I15/Y20).
 * İkisi Settings::ENCRYPTED_KEYS listesine alındı; bu migration var olan düz değerleri şifreler.
 * Yeniden çalıştırılabilir: zaten şifreli görünen değer bir daha şifrelenmez.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cms_contents')) {
            return;
        }
        foreach (['telegram_bot_token', 'scraper_api_token'] as $key) {
            $raw = CmsContent::getVal($key);
            if ($raw === null || $raw === '' || Settings::looksEncrypted((string) $raw)) {
                continue;
            }
            CmsContent::setVal($key, Crypt::encryptString((string) $raw));
        }
    }

    public function down(): void
    {
        // Geri alma şifreli değeri düz metne çevirmez; anahtar kodda şifreli listede kaldığı sürece okunabilir.
    }
};
