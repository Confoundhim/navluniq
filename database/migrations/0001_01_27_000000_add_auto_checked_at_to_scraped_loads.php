<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Otomatik onay taraması adayı en son ne zaman değerlendirdi: değişmeyen aday 10 dakikada birden sık
 * yeniden hesaplanmaz (her dakika tüm kuyruğu taramak sunucuyu meşgul ediyordu). Tekrar çalıştırmak güvenlidir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('scraped_loads') && ! Schema::hasColumn('scraped_loads', 'auto_checked_at')) {
            Schema::table('scraped_loads', fn (Blueprint $t) => $t->timestamp('auto_checked_at')->nullable()->after('auto_approved_at')->index());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('scraped_loads', 'auto_checked_at')) {
            Schema::table('scraped_loads', fn (Blueprint $t) => $t->dropColumn('auto_checked_at'));
        }
    }
};
