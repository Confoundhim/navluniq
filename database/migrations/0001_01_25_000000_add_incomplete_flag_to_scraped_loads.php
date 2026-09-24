<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Eksik bilgili ilanlar": karar puanı ret ile onay arasında kalan, rotası ve telefonu belli adaylar
 * kuyrukta beklemek yerine eksik bilgili olarak yayınlanır. completed_by: eksiği kim tamamladı (driver | admin).
 * Tekrar çalıştırmak güvenlidir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('scraped_loads')) {
            return;
        }
        Schema::table('scraped_loads', function (Blueprint $t): void {
            if (! Schema::hasColumn('scraped_loads', 'is_incomplete')) {
                $t->boolean('is_incomplete')->default(false)->after('vehicle_any')->index();
            }
            if (! Schema::hasColumn('scraped_loads', 'completed_by')) {
                $t->string('completed_by', 16)->nullable()->after('is_incomplete');
            }
        });
    }

    public function down(): void
    {
        Schema::table('scraped_loads', function (Blueprint $t): void {
            foreach (['is_incomplete', 'completed_by'] as $c) {
                if (Schema::hasColumn('scraped_loads', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
