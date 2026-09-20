<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sistem ilanları da dış kaynak ilanlar gibi önce premium şoförlere açılır; süre dolunca
 * herkese ve Telegram kanalına. released_at: ücretsiz üyelere açılış işlendi (bildirim + Telegram).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loads', function (Blueprint $table): void {
            $table->timestamp('available_to_free_at')->nullable()->index()->after('published_at');
            $table->timestamp('released_at')->nullable()->after('available_to_free_at');
            $table->timestamp('telegram_posted_at')->nullable()->after('released_at');
            $table->unsignedTinyInteger('telegram_attempts')->default(0)->after('telegram_posted_at');
        });
    }

    public function down(): void
    {
        Schema::table('loads', function (Blueprint $table): void {
            $table->dropIndex(['available_to_free_at']);
            $table->dropColumn(['available_to_free_at', 'released_at', 'telegram_posted_at', 'telegram_attempts']);
        });
    }
};
