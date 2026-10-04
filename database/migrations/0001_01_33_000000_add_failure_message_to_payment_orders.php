<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ödeme emrine gerekçe alanı: tutar uyuşmazlığı ve reddedilen iade (refund_pending) finans ekranında okunabilsin.
 * Yeniden çalıştırılabilir (hasColumn koruması).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payment_orders', 'failure_message')) {
            Schema::table('payment_orders', function (Blueprint $table): void {
                $table->string('failure_message', 500)->nullable()->after('refunded_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payment_orders', 'failure_message')) {
            Schema::table('payment_orders', fn (Blueprint $table) => $table->dropColumn('failure_message'));
        }
    }
};
