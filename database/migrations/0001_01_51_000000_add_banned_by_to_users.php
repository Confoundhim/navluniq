<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Engelleme kararını kimin verdiği (denetim Y7). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'banned_by')) {
            Schema::table('users', fn (Blueprint $t) => $t->unsignedBigInteger('banned_by')->nullable()->after('ban_reason'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'banned_by')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('banned_by'));
        }
    }
};
