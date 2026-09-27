<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Öğrenme çemberi: yapay zekadan gelen öneriler (source=ai) kaç ayrı ilanda görüldüğünü (hits), son ilanı ve kısa bir
 * açıklamayı taşır; "yok say" denen öneri status=ignored ile bir daha önerilmez.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_lexicon')) {
            return;
        }
        Schema::table('ai_lexicon', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_lexicon', 'note')) {
                $table->string('note', 200)->nullable()->after('sample');
            }
            if (! Schema::hasColumn('ai_lexicon', 'last_load_id')) {
                $table->unsignedBigInteger('last_load_id')->nullable()->after('sample');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_lexicon')) {
            return;
        }
        Schema::table('ai_lexicon', function (Blueprint $table): void {
            foreach (['note', 'last_load_id'] as $column) {
                if (Schema::hasColumn('ai_lexicon', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
