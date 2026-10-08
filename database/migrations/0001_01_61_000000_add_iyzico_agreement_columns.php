<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * iyzico pazaryeri kuralı: satıcı (şoför) ve alıcı (yük sahibi) iyzico platform sözleşmesini bir kez dijital olarak onaylar.
 * Onay zamanı saklanır; şoförde alt üye işyeri kaydından, yük sahibinde ilk navlun ödemesinden önce istenir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('driver_profiles', 'iyzico_seller_agreed_at')) {
            Schema::table('driver_profiles', function (Blueprint $table): void {
                $table->timestamp('iyzico_seller_agreed_at')->nullable()->after('tax_office');
            });
        }
        if (! Schema::hasColumn('users', 'iyzico_buyer_agreed_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->timestamp('iyzico_buyer_agreed_at')->nullable()->after('marketing_consent_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('driver_profiles', 'iyzico_seller_agreed_at')) {
            Schema::table('driver_profiles', fn (Blueprint $table) => $table->dropColumn('iyzico_seller_agreed_at'));
        }
        if (Schema::hasColumn('users', 'iyzico_buyer_agreed_at')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('iyzico_buyer_agreed_at'));
        }
    }
};
