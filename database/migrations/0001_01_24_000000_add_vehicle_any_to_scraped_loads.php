<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Araç fark etmez": ilan sahibi her araca açık. Araç tipi boş kalır (her şoför görür), otomatik onay
 * "araç tipi yok" diye engellemez, kartta "Araç fark etmez" yazar. Tekrar çalıştırmak güvenlidir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('scraped_loads') && ! Schema::hasColumn('scraped_loads', 'vehicle_any')) {
            Schema::table('scraped_loads', fn (Blueprint $t) => $t->boolean('vehicle_any')->default(false)->after('vehicle_type_source'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('scraped_loads', 'vehicle_any')) {
            Schema::table('scraped_loads', fn (Blueprint $t) => $t->dropColumn('vehicle_any'));
        }
    }
};
