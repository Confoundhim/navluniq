<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            // Pazaryeri modelinde ödeme kuruluşundaki alt üye işyeri (sub-merchant) kimliği
            $table->string('payout_provider_ref', 120)->nullable()->after('premium_until');
            $table->string('payout_provider', 32)->nullable()->after('payout_provider_ref');
        });
        Schema::table('payouts', function (Blueprint $table) {
            $table->string('channel', 24)->default('manual')->after('status'); // manual | gateway
            $table->text('failure_reason')->nullable()->after('reference_no');
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn(['payout_provider_ref', 'payout_provider']);
        });
        Schema::table('payouts', function (Blueprint $table) {
            $table->dropColumn(['channel', 'failure_reason']);
        });
    }
};
