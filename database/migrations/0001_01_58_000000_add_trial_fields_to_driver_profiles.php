<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Premium deneme süresi: her şoföre bir kez; başlangıç damgası tekrarını engeller. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('driver_profiles', 'trial_started_at')) {
            Schema::table('driver_profiles', function (Blueprint $table): void {
                $table->timestamp('trial_started_at')->nullable()->after('premium_until');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('driver_profiles', 'trial_started_at')) {
            Schema::table('driver_profiles', function (Blueprint $table): void {
                $table->dropColumn('trial_started_at');
            });
        }
    }
};
