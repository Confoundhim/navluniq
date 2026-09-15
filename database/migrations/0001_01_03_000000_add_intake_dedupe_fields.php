<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dış kaynak ilanlarında yapay zekaya gitmeden tekrar ayıklama için metin ve rota anahtarları.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scraped_loads', function (Blueprint $table): void {
            $table->char('normalized_hash', 64)->nullable()->after('content_hash')->index();
            $table->string('route_key', 191)->nullable()->after('normalized_hash')->index();
        });
    }

    public function down(): void
    {
        Schema::table('scraped_loads', function (Blueprint $table): void {
            $table->dropIndex(['normalized_hash']);
            $table->dropIndex(['route_key']);
            $table->dropColumn(['normalized_hash', 'route_key']);
        });
    }
};
