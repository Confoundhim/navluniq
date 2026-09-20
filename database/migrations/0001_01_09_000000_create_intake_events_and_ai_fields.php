<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Telefondan / servislerden gelen her isteğin izi: "mesaj geldi mi, neden elendi" sorusunun cevabı.
        Schema::create('intake_events', function (Blueprint $table) {
            $table->id();
            $table->string('source_name', 160)->nullable()->index();
            $table->string('status', 24)->index(); // created | duplicate | filtered | source_pending | skipped | unauthorized | failed
            $table->string('reason', 120)->nullable();
            $table->string('title', 255)->nullable();
            $table->string('excerpt', 300)->nullable();
            $table->foreignId('scraped_load_id')->nullable()->constrained('scraped_loads')->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->index();
        });

        Schema::table('scraped_loads', function (Blueprint $table) {
            $table->string('ai_status', 16)->nullable()->after('parse_confidence')->index(); // pending | done | failed | skipped
            $table->timestamp('ai_checked_at')->nullable()->after('ai_status');
        });
    }

    public function down(): void
    {
        Schema::table('scraped_loads', function (Blueprint $table) {
            $table->dropIndex(['ai_status']);
            $table->dropColumn(['ai_status', 'ai_checked_at']);
        });
        Schema::dropIfExists('intake_events');
    }
};
