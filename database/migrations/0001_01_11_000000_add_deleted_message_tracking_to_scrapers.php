<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Silinmiş kaynaktan gelmeye devam eden mesajlar sayılır; yönetici "geri al / kalıcı sil" kararını buna göre verir. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scrapers', function (Blueprint $table): void {
            $table->unsignedInteger('messages_since_deleted')->default(0)->after('last_error');
            $table->timestamp('last_message_at')->nullable()->after('messages_since_deleted');
        });
    }

    public function down(): void
    {
        Schema::table('scrapers', function (Blueprint $table): void {
            $table->dropColumn(['messages_since_deleted', 'last_message_at']);
        });
    }
};
