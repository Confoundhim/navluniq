<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Şirket şoförü için vergi dairesi: iyzico alt üye işyeri kaydında (LIMITED_OR_JOINT_STOCK_COMPANY) zorunlu alan. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('driver_profiles', 'tax_office')) {
            Schema::table('driver_profiles', function (Blueprint $table): void {
                $table->string('tax_office', 120)->nullable()->after('tax_number');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('driver_profiles', 'tax_office')) {
            Schema::table('driver_profiles', function (Blueprint $table): void {
                $table->dropColumn('tax_office');
            });
        }
    }
};
