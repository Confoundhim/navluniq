<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ham mesaj kolonu TEXT (64 KB) idi; telefondan 200.000 karaktere kadar metin gelebildiğinden (uzun WhatsApp listesi,
 * ekran dökümü) MySQL strict kipte "1406 Data too long" ile kayıt düşüyordu. MEDIUMTEXT (16 MB) yapılır. Yeniden çalıştırılabilir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasColumn('scraped_loads', 'raw_message')) {
            return;
        }
        $type = DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'scraped_loads')->where('COLUMN_NAME', 'raw_message')->value('DATA_TYPE');
        if (strtolower((string) $type) === 'text') {
            DB::statement('ALTER TABLE scraped_loads MODIFY raw_message MEDIUMTEXT NOT NULL');
        }
    }

    public function down(): void
    {
        // Geri dönüş istenmez: daha dar kolon mevcut kayıtları kesebilir.
    }
};
