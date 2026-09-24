<?php

use App\Support\VehicleTypes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Araç taksonomisi revizyonu (2026-09): otomobil ve minivan kalktı; orta/uzun panelvan tek "panelvan" oldu.
 * Eski anahtar taşıyan kayıtlar (şoför araçları, ilanlar, dış kaynak ilanları, sözlük, şablonlar) yeni
 * anahtara çevrilir. Şoför aracına "liftli" (kuyruk lifti) alanı eklenir. Tekrar çalıştırmak güvenlidir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('driver_vehicles') && ! Schema::hasColumn('driver_vehicles', 'has_lift')) {
            Schema::table('driver_vehicles', fn (Blueprint $t) => $t->boolean('has_lift')->nullable()->after('trailer_length'));
        }
        $targets = [
            'driver_vehicles' => 'vehicle_type',
            'loads' => 'vehicle_type',
            'scraped_loads' => 'vehicle_type',
            'ai_templates' => 'vehicle_type',
        ];
        foreach ($targets as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }
            foreach (VehicleTypes::LEGACY as $old => $new) {
                DB::table($table)->where($column, $old)->update([$column => $new]);
            }
        }
        if (Schema::hasTable('ai_lexicon') && Schema::hasColumn('ai_lexicon', 'canonical')) {
            foreach (VehicleTypes::LEGACY as $old => $new) {
                DB::table('ai_lexicon')->where('kind', 'vehicle')->where('canonical', $old)->update(['canonical' => $new]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('driver_vehicles', 'has_lift')) {
            Schema::table('driver_vehicles', fn (Blueprint $t) => $t->dropColumn('has_lift'));
        }
    }
};
