<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Yönetici dış kaynak sayfası ve şoför listeleri her açılışta "kuyrukta kaç aday / yayında kaç ilan / bugün kaç
 * mesaj" gibi sayımlar yapar. Tek sütunlu indekslerle MySQL her eşleşen satırı diskten okuyup durumuna bakmak
 * zorundaydı; yavaş diskte bu dakikalar sürüyordu. Bileşik indeksler sayımı indeksin içinden cevaplar.
 * Yeniden çalıştırılabilir: var olan indeks atlanır.
 */
return new class extends Migration
{
    private const INDEXES = [
        'scraped_loads' => [
            'scraped_loads_visibility_status_deleted_idx' => ['visibility', 'status', 'deleted_at'],
            'scraped_loads_status_deleted_idx' => ['status', 'deleted_at'],
            'scraped_loads_ai_status_visibility_idx' => ['ai_status', 'visibility', 'status'],
            'scraped_loads_published_visibility_idx' => ['published_at', 'visibility', 'status'],
        ],
        'intake_events' => [
            'intake_events_created_status_idx' => ['created_at', 'status'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($indexes as $name => $columns) {
                if (Schema::hasIndex($table, $name) || ! Schema::hasColumns($table, $columns)) {
                    continue;
                }
                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (array_keys($indexes) as $name) {
                if (Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }
};
