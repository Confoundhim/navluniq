<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Yöneticinin "panel değiştir" ile açtığı şoför/yük sahibi profilleri gerçek kullanıcı sayılmaz (istatistik, bildirim, listeler). */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['driver_profiles', 'cargo_owner_profiles'] as $table) {
            if (! Schema::hasColumn($table, 'is_staff_view')) {
                Schema::table($table, fn (Blueprint $t) => $t->boolean('is_staff_view')->default(false)->after('user_id'));
            }
        }
    }

    public function down(): void
    {
        foreach (['driver_profiles', 'cargo_owner_profiles'] as $table) {
            if (Schema::hasColumn($table, 'is_staff_view')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('is_staff_view'));
            }
        }
    }
};
