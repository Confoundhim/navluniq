<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Veri dosyasında Çanakkale'nin "Ayvacık" ilçesi "Ayvacik" yazılmıştı; kayıtlı ilan etiketleri düzeltilir.
 * Tekrar çalıştırmak güvenlidir.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['scraped_loads' => ['pickup_location', 'delivery_location', 'pickup_district', 'delivery_district'], 'loads' => ['pickup_district', 'delivery_district']] as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column)) {
                    DB::table($table)->where($column, 'like', '%Ayvacik%')->update([$column => DB::raw("REPLACE($column, 'Ayvacik', 'Ayvacık')")]);
                }
            }
        }
    }

    public function down(): void {}
};
