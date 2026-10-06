<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canlı takip paketi (2026-10-06): yolda takılan sevkiyat uyarısı (bir kez) ve otomatik onaydan önce yük sahibine hatırlatma (bir kez).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('loads') && ! Schema::hasColumn('loads', 'transit_overdue_notified_at')) {
            Schema::table('loads', fn (Blueprint $t) => $t->timestamp('transit_overdue_notified_at')->nullable()->after('no_show_notified_at'));
        }
        if (Schema::hasTable('shipments') && ! Schema::hasColumn('shipments', 'approval_reminded_at')) {
            Schema::table('shipments', fn (Blueprint $t) => $t->timestamp('approval_reminded_at')->nullable()->after('auto_approval_due_at'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('loads', 'transit_overdue_notified_at')) {
            Schema::table('loads', fn (Blueprint $t) => $t->dropColumn('transit_overdue_notified_at'));
        }
        if (Schema::hasColumn('shipments', 'approval_reminded_at')) {
            Schema::table('shipments', fn (Blueprint $t) => $t->dropColumn('approval_reminded_at'));
        }
    }
};
