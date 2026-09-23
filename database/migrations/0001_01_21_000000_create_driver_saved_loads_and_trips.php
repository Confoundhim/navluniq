<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Şoförün kaydettiği ilanlar (favori), "Bu işi aldım" sefer kayıtları ve sefer başına
 * bildirilmiş dönüş yükü eşleşmeleri (aynı ilan iki kez bildirilmez).
 */
return new class extends Migration
{
    public function up(): void
    {
        // MariaDB'de tablo oluşturma işlemsel değildir: yarım kalmış bir denemeden sonra yeniden çalıştırmak güvenli olsun.
        Schema::hasTable('driver_saved_loads') || Schema::create('driver_saved_loads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('load_id')->nullable()->constrained('loads')->cascadeOnDelete();
            $table->foreignId('scraped_load_id')->nullable()->constrained('scraped_loads')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['driver_profile_id', 'load_id']);
            $table->unique(['driver_profile_id', 'scraped_load_id']);
        });

        Schema::hasTable('driver_trips') || Schema::create('driver_trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_profile_id')->constrained()->cascadeOnDelete();
            $table->string('source', 12)->default('external'); // external (gruptan) | system (NavlunIQ ilanı)
            $table->foreignId('scraped_load_id')->nullable()->constrained('scraped_loads')->nullOnDelete();
            $table->foreignId('load_id')->nullable()->constrained('loads')->nullOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained('shipments')->nullOnDelete();
            $table->string('pickup_location', 160)->nullable();
            $table->unsignedSmallInteger('pickup_province_code')->nullable();
            $table->string('delivery_location', 160)->nullable();
            $table->unsignedSmallInteger('delivery_province_code')->nullable()->index();
            $table->decimal('delivery_lat', 10, 7)->nullable();
            $table->decimal('delivery_lng', 10, 7)->nullable();
            $table->date('pickup_date')->nullable();
            $table->date('delivery_date')->nullable();
            $table->string('status', 16)->default('planned')->index(); // planned | on_the_way | delivered | closed
            $table->boolean('notify_return')->default(true);
            $table->timestamp('last_scanned_at')->nullable();
            $table->timestamp('last_mailed_at')->nullable();
            $table->unsignedInteger('match_count')->default(0);
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::hasTable('driver_trip_matches') || Schema::create('driver_trip_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_trip_id')->constrained('driver_trips')->cascadeOnDelete();
            $table->string('kind', 8); // system | external
            $table->unsignedBigInteger('matched_id');
            $table->timestamp('created_at')->nullable();
            $table->unique(['driver_trip_id', 'kind', 'matched_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_trip_matches');
        Schema::dropIfExists('driver_trips');
        Schema::dropIfExists('driver_saved_loads');
    }
};
