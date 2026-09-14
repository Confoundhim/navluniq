<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cargo_owner_profiles', function (Blueprint $table) {
            $table->char('birth_year', 4)->nullable()->after('tc_no');
        });

        Schema::table('loads', function (Blueprint $table) {
            $table->index(['status', 'visibility']);
        });

        Schema::table('offers', function (Blueprint $table) {
            $table->unique(['load_id', 'driver_profile_id']);
            $table->index(['driver_profile_id', 'status']);
        });

        Schema::table('scraped_loads', function (Blueprint $table) {
            $table->index(['status', 'visibility', 'available_to_free_at'], 'scraped_loads_pool_index');
        });

        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->json('preferences')->nullable()->after('ocr_data');
        });

        // Konumlar sürücüden bağımsız çalışması için düz enlem/boylam kolonlarında tutulur.
        Schema::table('driver_locations', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->dropSpatialIndex(['coordinates']);
            }
            $table->dropColumn('coordinates');
        });
        Schema::table('driver_locations', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->after('shipment_id');
            $table->decimal('longitude', 10, 7)->after('latitude');
        });

        Schema::table('loads', function (Blueprint $table) {
            $table->decimal('pickup_lat', 10, 7)->nullable()->after('delivery_coordinates');
            $table->decimal('pickup_lng', 10, 7)->nullable()->after('pickup_lat');
            $table->decimal('delivery_lat', 10, 7)->nullable()->after('pickup_lng');
            $table->decimal('delivery_lng', 10, 7)->nullable()->after('delivery_lat');
        });

        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->char('iban_hash', 64)->nullable()->after('encrypted_iban')->index();
        });

        Schema::table('offers', function (Blueprint $table) {
            $table->unsignedSmallInteger('estimated_days')->nullable()->after('message');
        });

        Schema::table('loads', function (Blueprint $table) {
            $table->string('e_irsaliye_path')->nullable()->after('e_irsaliye_no');
        });

        Schema::table('disputes', function (Blueprint $table) {
            $table->string('claim_photo_path')->nullable()->after('cargo_owner_claim');
            $table->string('resolution', 32)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('disputes', fn (Blueprint $table) => $table->dropColumn(['claim_photo_path', 'resolution']));
        Schema::table('loads', fn (Blueprint $table) => $table->dropColumn('e_irsaliye_path'));
        Schema::table('offers', fn (Blueprint $table) => $table->dropColumn('estimated_days'));
        Schema::table('bank_accounts', fn (Blueprint $table) => $table->dropColumn('iban_hash'));
        Schema::table('loads', fn (Blueprint $table) => $table->dropColumn(['pickup_lat', 'pickup_lng', 'delivery_lat', 'delivery_lng']));
        Schema::table('driver_locations', fn (Blueprint $table) => $table->dropColumn(['latitude', 'longitude']));
        Schema::table('driver_locations', fn (Blueprint $table) => $table->geometry('coordinates', subtype: 'point', srid: 4326)->nullable());
        Schema::table('driver_profiles', fn (Blueprint $table) => $table->dropColumn('preferences'));
        Schema::table('scraped_loads', fn (Blueprint $table) => $table->dropIndex('scraped_loads_pool_index'));
        Schema::table('offers', function (Blueprint $table) {
            $table->dropUnique(['load_id', 'driver_profile_id']);
            $table->dropIndex(['driver_profile_id', 'status']);
        });
        Schema::table('loads', fn (Blueprint $table) => $table->dropIndex(['status', 'visibility']));
        Schema::table('cargo_owner_profiles', fn (Blueprint $table) => $table->dropColumn('birth_year'));
    }
};
