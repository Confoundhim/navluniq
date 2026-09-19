<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Uygulama içi bildirim + e-posta gönderim kaydı. Her bildirim önce buraya yazılır,
        // e-posta ayrıca gönderilir; başarısız e-postalar zamanlanmış görevle yeniden denenir.
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40)->default('general')->index();
            $table->string('title', 160);
            $table->json('lines');
            $table->string('action_url', 500)->nullable();
            $table->string('action_text', 60)->nullable();
            $table->string('mail_status', 16)->default('pending')->index(); // pending | sent | failed | skipped
            $table->unsignedTinyInteger('mail_attempts')->default(0);
            $table->text('mail_error')->nullable();
            $table->timestamp('mail_sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
