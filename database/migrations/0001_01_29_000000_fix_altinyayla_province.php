<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Veri dosyasında Burdur'un Altınyayla ilçesi yanlışlıkla Muğla'ya bağlıydı (koordinatları Burdur'daki ilçenindi).
 * Kayıtlı ilan etiketleri ve il kodları düzeltilir. Tekrar çalıştırmak güvenlidir.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['scraped_loads', 'loads'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (['pickup', 'delivery'] as $side) {
                if (! Schema::hasColumn($table, "{$side}_district") || ! Schema::hasColumn($table, "{$side}_province_code")) {
                    continue;
                }
                DB::table($table)->where("{$side}_district", 'Altınyayla')->where("{$side}_province_code", 48)->update(["{$side}_province_code" => 15]);
                if (Schema::hasColumn($table, "{$side}_location")) {
                    DB::table($table)->where("{$side}_location", 'like', '%Muğla Altınyayla%')
                        ->update(["{$side}_location" => DB::raw("REPLACE({$side}_location, 'Muğla Altınyayla', 'Burdur Altınyayla')")]);
                }
            }
        }
    }

    public function down(): void {}
};
