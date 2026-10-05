<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Para ve sevkiyat mantığı (canlıya hazırlık incelemesi §3, 2026-10-05):
 *  - Şoför alt üye işyeri kimliği (TC / VKN, tüzel kişilik türü), ödeme sonrası vazgeçme sayacı, IBAN değişiklik izi.
 *  - Ödeme emrinde komisyon anlık görüntüsü (oran, tutar, şoföre kalan): hakediş ve pazaryeri payı aynı rakamı kullanır.
 *  - İlanda "şoför gelmedi" uyarısının bir kez gönderildiği zaman.
 *  - payouts.load_id ve driver_trips.shipment_id benzersiz (önce tekrarlar temizlenir, en düşük kimlik kalır).
 * Yeniden çalıştırılabilir (hasColumn / index adı denetimi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table): void {
            if (! Schema::hasColumn('driver_profiles', 'legal_type')) {
                $table->string('legal_type', 16)->default('individual')->after('payout_provider'); // individual | company
            }
            if (! Schema::hasColumn('driver_profiles', 'identity_number')) {
                $table->string('identity_number', 11)->nullable()->after('legal_type'); // TC kimlik no (bireysel)
            }
            if (! Schema::hasColumn('driver_profiles', 'tax_number')) {
                $table->string('tax_number', 10)->nullable()->after('identity_number'); // VKN (şirket)
            }
            if (! Schema::hasColumn('driver_profiles', 'withdrawals_after_payment')) {
                $table->unsignedInteger('withdrawals_after_payment')->default(0)->after('tax_number');
            }
            if (! Schema::hasColumn('driver_profiles', 'bank_account_changed_at')) {
                $table->timestamp('bank_account_changed_at')->nullable()->after('withdrawals_after_payment');
            }
        });

        Schema::table('payment_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('payment_orders', 'commission_rate')) {
                $table->decimal('commission_rate', 6, 3)->nullable()->after('service_fee_amount'); // yüzde
            }
            if (! Schema::hasColumn('payment_orders', 'commission_amount')) {
                $table->decimal('commission_amount', 19, 4)->nullable()->after('commission_rate');
            }
            if (! Schema::hasColumn('payment_orders', 'driver_net_amount')) {
                $table->decimal('driver_net_amount', 19, 4)->nullable()->after('commission_amount');
            }
        });

        Schema::table('loads', function (Blueprint $table): void {
            if (! Schema::hasColumn('loads', 'no_show_notified_at')) {
                $table->timestamp('no_show_notified_at')->nullable()->after('payment_reminded_at');
            }
        });

        // Aynı ilana açılmış birden fazla hakediş: en düşük kimlik kalır, diğerleri kalıcı silinir (benzersiz indeks için).
        $dupPayouts = DB::table('payouts')->select('load_id', DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as n'))
            ->groupBy('load_id')->havingRaw('COUNT(*) > 1')->get();
        foreach ($dupPayouts as $row) {
            DB::table('payouts')->where('load_id', $row->load_id)->where('id', '!=', $row->keep_id)->delete();
        }
        if (! $this->hasIndex('payouts', 'payouts_load_id_unique')) {
            Schema::table('payouts', fn (Blueprint $table) => $table->unique('load_id', 'payouts_load_id_unique'));
        }

        // Aynı sevkiyata bağlı birden fazla sefer: en düşük kimlik kalır.
        $dupTrips = DB::table('driver_trips')->whereNotNull('shipment_id')
            ->select('shipment_id', DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as n'))
            ->groupBy('shipment_id')->havingRaw('COUNT(*) > 1')->get();
        foreach ($dupTrips as $row) {
            $ids = DB::table('driver_trips')->where('shipment_id', $row->shipment_id)->where('id', '!=', $row->keep_id)->pluck('id');
            DB::table('driver_trip_matches')->whereIn('driver_trip_id', $ids)->delete();
            DB::table('driver_trips')->whereIn('id', $ids)->delete();
        }
        if (! $this->hasIndex('driver_trips', 'driver_trips_shipment_id_unique')) {
            Schema::table('driver_trips', fn (Blueprint $table) => $table->unique('shipment_id', 'driver_trips_shipment_id_unique'));
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('payouts', 'payouts_load_id_unique')) {
            Schema::table('payouts', fn (Blueprint $table) => $table->dropUnique('payouts_load_id_unique'));
        }
        if ($this->hasIndex('driver_trips', 'driver_trips_shipment_id_unique')) {
            Schema::table('driver_trips', fn (Blueprint $table) => $table->dropUnique('driver_trips_shipment_id_unique'));
        }
        Schema::table('driver_profiles', function (Blueprint $table): void {
            foreach (['legal_type', 'identity_number', 'tax_number', 'withdrawals_after_payment', 'bank_account_changed_at'] as $col) {
                if (Schema::hasColumn('driver_profiles', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        Schema::table('payment_orders', function (Blueprint $table): void {
            foreach (['commission_rate', 'commission_amount', 'driver_net_amount'] as $col) {
                if (Schema::hasColumn('payment_orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        if (Schema::hasColumn('loads', 'no_show_notified_at')) {
            Schema::table('loads', fn (Blueprint $table) => $table->dropColumn('no_show_notified_at'));
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn (array $i) => ($i['name'] ?? '') === $index);
    }
};
