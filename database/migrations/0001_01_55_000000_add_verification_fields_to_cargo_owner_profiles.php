<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Yük sahibi doğrulaması (2026-10-05, karar 3): NVİ sorgu izi ve kurumsal doğrulamanın kim/ne zaman yaptığı. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cargo_owner_profiles', function (Blueprint $table): void {
            if (! Schema::hasColumn('cargo_owner_profiles', 'nvi_checked_at')) {
                $table->timestamp('nvi_checked_at')->nullable()->after('nvi_verified');
            }
            if (! Schema::hasColumn('cargo_owner_profiles', 'nvi_message')) {
                $table->string('nvi_message', 200)->nullable()->after('nvi_checked_at');
            }
            if (! Schema::hasColumn('cargo_owner_profiles', 'gib_verified_at')) {
                $table->timestamp('gib_verified_at')->nullable()->after('gib_verified');
            }
            if (! Schema::hasColumn('cargo_owner_profiles', 'gib_verified_by')) {
                $table->foreignId('gib_verified_by')->nullable()->after('gib_verified_at')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('cargo_owner_profiles', function (Blueprint $table): void {
            foreach (['gib_verified_by', 'gib_verified_at', 'nvi_message', 'nvi_checked_at'] as $col) {
                if (Schema::hasColumn('cargo_owner_profiles', $col)) {
                    $col === 'gib_verified_by' ? $table->dropConstrainedForeignId($col) : $table->dropColumn($col);
                }
            }
        });
    }
};
