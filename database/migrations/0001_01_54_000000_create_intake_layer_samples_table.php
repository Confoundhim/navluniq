<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Okuma katmanı örnekleri: gölgedeki katmanın ne yapacağı ve hakemin (yapay zeka) kararı; yalnız il/ilçe etiketi, ham metin yok. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('intake_layer_samples')) {
            return;
        }
        Schema::create('intake_layer_samples', function (Blueprint $table): void {
            $table->id();
            $table->string('layer', 40);
            $table->string('stage', 12); // örnek alınırken katmanın aşaması
            $table->foreignId('scraped_load_id')->nullable()->constrained('scraped_loads')->nullOnDelete();
            $table->string('predicted_pickup', 120)->nullable();
            $table->string('predicted_delivery', 120)->nullable();
            $table->string('judge_pickup', 120)->nullable();
            $table->string('judge_delivery', 120)->nullable();
            $table->string('verdict', 12); // agree | disagree | unknown
            $table->timestamp('created_at');
            $table->index(['layer', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_layer_samples');
    }
};
