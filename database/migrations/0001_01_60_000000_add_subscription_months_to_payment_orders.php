<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Premium ödeme emrinde seçilen süre (1/3/6/12 ay); ödeme onaylanınca bu kadar ay eklenir. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payment_orders', 'subscription_months')) {
            Schema::table('payment_orders', function (Blueprint $table): void {
                $table->unsignedSmallInteger('subscription_months')->nullable()->after('purpose');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payment_orders', 'subscription_months')) {
            Schema::table('payment_orders', function (Blueprint $table): void {
                $table->dropColumn('subscription_months');
            });
        }
    }
};
