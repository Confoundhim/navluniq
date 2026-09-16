<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Araç tipi ve il/ilçe/koordinat alanları: dış kaynak ilanlarında otomatik çıkarım,
 * platform ilanlarında metinden çözümleme, şoför filtrelerinde mesafe ve bölge süzme.
 * Araçta marka/model artık istenmez (mevcut veriler korunur).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scraped_loads', function (Blueprint $table): void {
            $table->string('vehicle_type', 48)->nullable()->after('goods_type')->index();
            $table->string('vehicle_type_source', 16)->nullable()->after('vehicle_type');
            $table->unsignedSmallInteger('pickup_province_code')->nullable()->after('pickup_location')->index();
            $table->string('pickup_district', 80)->nullable()->after('pickup_province_code');
            $table->decimal('pickup_lat', 10, 7)->nullable()->after('pickup_district');
            $table->decimal('pickup_lng', 10, 7)->nullable()->after('pickup_lat');
            $table->unsignedSmallInteger('delivery_province_code')->nullable()->after('delivery_location')->index();
            $table->string('delivery_district', 80)->nullable()->after('delivery_province_code');
            $table->decimal('delivery_lat', 10, 7)->nullable()->after('delivery_district');
            $table->decimal('delivery_lng', 10, 7)->nullable()->after('delivery_lat');
        });

        Schema::table('loads', function (Blueprint $table): void {
            $table->unsignedSmallInteger('pickup_province_code')->nullable()->after('pickup_location')->index();
            $table->string('pickup_district', 80)->nullable()->after('pickup_province_code');
            $table->unsignedSmallInteger('delivery_province_code')->nullable()->after('delivery_location')->index();
            $table->string('delivery_district', 80)->nullable()->after('delivery_province_code');
        });

        Schema::table('driver_vehicles', function (Blueprint $table): void {
            $table->string('brand')->nullable()->change();
            $table->string('model')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('scraped_loads', function (Blueprint $table): void {
            $table->dropIndex(['vehicle_type']);
            $table->dropIndex(['pickup_province_code']);
            $table->dropIndex(['delivery_province_code']);
            $table->dropColumn(['vehicle_type', 'vehicle_type_source', 'pickup_province_code', 'pickup_district', 'pickup_lat', 'pickup_lng', 'delivery_province_code', 'delivery_district', 'delivery_lat', 'delivery_lng']);
        });
        Schema::table('loads', function (Blueprint $table): void {
            $table->dropIndex(['pickup_province_code']);
            $table->dropIndex(['delivery_province_code']);
            $table->dropColumn(['pickup_province_code', 'pickup_district', 'delivery_province_code', 'delivery_district']);
        });
    }
};
