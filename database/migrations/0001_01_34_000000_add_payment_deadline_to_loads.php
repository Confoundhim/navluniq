<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Teklif kabulünden sonra ödeme için son tarih ve hatırlatma izi: ödenmeyen ilan süre dolunca yeniden havuza döner,
 * şoför sonsuza kadar "ödeme bekleniyor" diye beklemez. Yeniden çalıştırılabilir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loads', function (Blueprint $table): void {
            if (! Schema::hasColumn('loads', 'payment_due_at')) {
                $table->timestamp('payment_due_at')->nullable()->after('cancelled_at')->index();
            }
            if (! Schema::hasColumn('loads', 'payment_reminded_at')) {
                $table->timestamp('payment_reminded_at')->nullable()->after('payment_due_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('loads', function (Blueprint $table): void {
            foreach (['payment_due_at', 'payment_reminded_at'] as $col) {
                if (Schema::hasColumn('loads', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
