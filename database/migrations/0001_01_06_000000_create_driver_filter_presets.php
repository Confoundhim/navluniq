<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Şoförün kalıcı ilan filtreleri: birden fazla profil, biri varsayılan olabilir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_filter_presets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('driver_profile_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->boolean('is_default')->default(false);
            $table->boolean('notify')->default(false);
            $table->json('filters');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->index(['driver_profile_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_filter_presets');
    }
};
