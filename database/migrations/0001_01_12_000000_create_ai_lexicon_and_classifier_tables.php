<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dış dünyaya bağımlı olmayan öğrenen çözümleme katmanı:
 * - ai_lexicon: nakliye jargonu sözlüğü (konum kısaltması, araç/yük sözcüğü, "ilan değil" ifadesi…); yönetici girer
 *   ya da sistem yönetici düzeltmelerinden öğrenir.
 * - ai_token_stats: yerel Bayes sınıflandırıcının sözcük sayaçları (ilan / ilan değil).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_lexicon', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 20); // location | vehicle | goods | not_load | load_signal | ignore
            $table->string('term', 120)->nullable(); // normalleştirilmiş (küçük harf, ASCII) sözcük/ifade; öneride boş
            $table->string('canonical', 160)->nullable(); // konum: katalog adı; araç: tip anahtarı; yük: kategori anahtarı
            $table->string('status', 12)->default('active'); // active | suggested
            $table->string('source', 12)->default('admin'); // admin | learned
            $table->unsignedInteger('hits')->default(0);
            $table->text('sample')->nullable(); // önerinin geldiği ham mesaj
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['kind', 'status']);
            $table->index(['kind', 'term']);
        });

        Schema::create('ai_token_stats', function (Blueprint $table): void {
            $table->string('token', 80)->primary();
            $table->unsignedInteger('load_count')->default(0);
            $table->unsignedInteger('other_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_token_stats');
        Schema::dropIfExists('ai_lexicon');
    }
};
