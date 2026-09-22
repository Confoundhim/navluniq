<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kasa/dorse tipi, yük biçimi (komple/parça), istenen araç adedi ve çoklu teslim noktası.
 * Araç sınıfı ile kasa tipi ayrı boyutlardır ("13.60 tenteli" = tır + tenteli).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scraped_loads', function (Blueprint $table) {
            $table->json('body_types')->nullable()->after('vehicle_type_source');
            $table->string('body_type_source', 16)->nullable()->after('body_types');
            $table->string('load_kind', 12)->nullable()->after('body_type_source')->index();
            $table->unsignedTinyInteger('vehicle_count')->nullable()->after('load_kind');
            $table->json('delivery_stops')->nullable()->after('vehicle_count');
        });
        Schema::table('loads', function (Blueprint $table) {
            $table->json('body_types')->nullable()->after('vehicle_type');
            $table->string('load_kind', 12)->nullable()->after('body_types')->index();
            $table->json('delivery_stops')->nullable()->after('load_kind');
        });
        Schema::table('driver_vehicles', function (Blueprint $table) {
            $table->string('body_type', 24)->nullable()->after('vehicle_type')->index();
            $table->string('trailer_length', 12)->nullable()->after('body_type'); // kisa | uzun (yalnız tır)
        });
    }

    public function down(): void
    {
        Schema::table('scraped_loads', fn (Blueprint $t) => $t->dropColumn(['body_types', 'body_type_source', 'load_kind', 'vehicle_count', 'delivery_stops']));
        Schema::table('loads', fn (Blueprint $t) => $t->dropColumn(['body_types', 'load_kind', 'delivery_stops']));
        Schema::table('driver_vehicles', fn (Blueprint $t) => $t->dropColumn(['body_type', 'trailer_length']));
    }
};
