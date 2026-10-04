<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** E-posta değişikliği yeni adrese gönderilen kodla doğrulanır; onaya kadar yeni adres burada bekler. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'pending_email')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('pending_email')->nullable()->after('email');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'pending_email')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('pending_email'));
        }
    }
};
