<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Herkese açık telefon kurulum sayfası kaldırıldı; gizli kodu tutan ayar silinir. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cms_contents')) {
            DB::table('cms_contents')->where('key', 'scraper_setup_code')->delete();
            Cache::forget('cms_contents.all');
        }
    }

    public function down(): void
    {
        // Kod gerektiğinde yeniden üretilirdi; geri alma gerekmez.
    }
};
