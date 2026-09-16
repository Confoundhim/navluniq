<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aynı ilanın kaç kaynaktan geldiği, otomatik onay izi ve Telegram paylaşım durumu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scraped_loads', function (Blueprint $table): void {
            $table->unsignedInteger('duplicate_count')->default(1)->after('route_key');
            $table->json('seen_sources')->nullable()->after('duplicate_count');
            $table->timestamp('auto_approved_at')->nullable()->after('available_to_free_at');
            $table->timestamp('telegram_posted_at')->nullable()->after('auto_approved_at')->index();
            $table->unsignedTinyInteger('telegram_attempts')->default(0)->after('telegram_posted_at');
        });
    }

    public function down(): void
    {
        Schema::table('scraped_loads', function (Blueprint $table): void {
            $table->dropIndex(['telegram_posted_at']);
            $table->dropColumn(['duplicate_count', 'seen_sources', 'auto_approved_at', 'telegram_posted_at', 'telegram_attempts']);
        });
    }
};
