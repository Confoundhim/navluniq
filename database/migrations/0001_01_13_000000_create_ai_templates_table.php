<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Şablon hafızası: aynı gönderenin (numara) aynı yazım kalıbıyla attığı ilan bir kez doğrulandıysa
 * (yapay zeka ya da yönetici), sonrakiler kalıptan yapay zekasız çözülür.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_templates', function (Blueprint $table): void {
            $table->id();
            $table->char('phone_hash', 64); // numaranın SHA-256'sı (düz numara saklanmaz)
            $table->char('signature_hash', 64); // kalıp özeti (yer adları {yer}, sayılar {n})
            $table->string('signature', 500)->nullable();
            $table->boolean('is_load')->default(true);
            $table->unsignedTinyInteger('pickup_index')->nullable(); // kalıptaki kaçıncı yer adı kalkış
            $table->unsignedTinyInteger('delivery_index')->nullable();
            $table->string('vehicle_type', 40)->nullable();
            $table->string('goods_category', 40)->nullable();
            $table->decimal('confidence', 4, 3)->default(0.9);
            $table->unsignedInteger('uses')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedBigInteger('source_load_id')->nullable();
            $table->timestamps();
            $table->unique(['phone_hash', 'signature_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_templates');
    }
};
