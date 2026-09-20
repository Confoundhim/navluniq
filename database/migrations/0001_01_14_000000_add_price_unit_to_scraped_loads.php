<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dış kaynak ilanlarında fiyat birimi: toplam navlun (total) ya da ton başına (per_ton). Dökme yüklerde
 * ("1000+kdv dökme üzüm", "950+basar") fiyat ton başınadır; toplam gibi gösterilmesin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scraped_loads', function (Blueprint $table): void {
            $table->string('price_unit', 8)->nullable()->after('currency'); // total | per_ton
        });
    }

    public function down(): void
    {
        Schema::table('scraped_loads', function (Blueprint $table): void {
            $table->dropColumn('price_unit');
        });
    }
};
