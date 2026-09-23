<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dış kaynak ilanının yayına giriş anı. Sayaçlar "o ana kadar yayınlanmış" ilanı sayar; arşivlenen
 * (listeden düşen) ilanlar da sayımda kalır. Eski kayıtlar: yayındakiler onay/ güncelleme anıyla,
 * arşivlenmiş çözümlenmiş ilanlar oluşturulma anıyla doldurulur.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('scraped_loads', 'published_at')) {
            Schema::table('scraped_loads', function (Blueprint $table) {
                $table->timestamp('published_at')->nullable()->after('auto_approved_at')->index();
            });
        }
        DB::table('scraped_loads')->whereNull('published_at')->where('visibility', 'public')
            ->update(['published_at' => DB::raw('COALESCE(auto_approved_at, updated_at, created_at)')]);
        DB::table('scraped_loads')->whereNull('published_at')->whereNotNull('deleted_at')->where('status', 'parsed_success')
            ->update(['published_at' => DB::raw('COALESCE(auto_approved_at, created_at)')]);
    }

    public function down(): void
    {
        Schema::table('scraped_loads', fn (Blueprint $t) => $t->dropColumn('published_at'));
    }
};
