<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Yük sahibi paneli Paket 3 (2026-10-06, plan E6/E7): ilanın herkese açık yüzü il/ilçe kalır (`pickup_location`),
 * açık adres, yükleme yetkilisi ve şoföre not yalnız yük sahibi, ödemesi alınmış atanan şoför ve yönetici görür.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loads', function (Blueprint $table): void {
            if (! Schema::hasColumn('loads', 'pickup_address_private')) {
                $table->text('pickup_address_private')->nullable()->after('pickup_district');
            }
            if (! Schema::hasColumn('loads', 'delivery_address_private')) {
                $table->text('delivery_address_private')->nullable()->after('delivery_district');
            }
            if (! Schema::hasColumn('loads', 'pickup_contact_name')) {
                $table->string('pickup_contact_name', 120)->nullable()->after('delivery_address_private');
            }
            if (! Schema::hasColumn('loads', 'pickup_contact_phone')) {
                $table->string('pickup_contact_phone', 20)->nullable()->after('pickup_contact_name');
            }
            if (! Schema::hasColumn('loads', 'notes')) {
                $table->text('notes')->nullable()->after('pickup_contact_phone');
            }
        });
    }

    public function down(): void
    {
        Schema::table('loads', function (Blueprint $table): void {
            foreach (['notes', 'pickup_contact_phone', 'pickup_contact_name', 'delivery_address_private', 'pickup_address_private'] as $col) {
                if (Schema::hasColumn('loads', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
