<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dış kaynak ilanları yalnız premium üyelere görünür: saklanan kullanım şartları (2.1) ve SSS metinlerindeki
 * "20 dakika erken erişim / numara kısmen gizli" ifadeleri buna göre düzeltilir. Yayındaki dış kaynak ilanlarında
 * "herkese açılma" zamanı temizlenir (artık kullanılmaz).
 */
return new class extends Migration
{
    private const REPLACEMENTS = [
        'Sürücülere paylaşım izni doğrulanmış dış kaynak ilanlarına plan kapsamında 20 dakikaya kadar erken erişim ve bildirim özellikleri sağlayan' => 'Sürücülere paylaşım izni doğrulanmış dış kaynak ilanlarına erişim, sistem ilanlarına 20 dakikaya kadar erken erişim ve bildirim özellikleri sağlayan',
        'Yük sahiplerinin açtığı sistem ilanları ve onaylı dış kaynak ilanları önce premium üyelere açılır; standart üyelere ve Telegram kanalına 20 dakika sonra düşer. Dış kaynak ilanlarında ilan sahibinin telefon numarasının tamamı da görünür.' => 'Yük sahiplerinin açtığı sistem ilanları önce premium üyelere açılır; standart üyelere ve Telegram kanalına 20 dakika sonra düşer. Onaylı dış kaynak ilanları ise ilan sahibinin telefon numarasıyla birlikte yalnız premium üyelere gösterilir.',
        'Ancak yeni ilanlar (sistem ilanları ve dış kaynak ilanlar) ücretsiz hesaba premium üyelerden 20 dakika sonra açılır ve dış kaynak ilanlarında numara kısmen gizlenir.' => 'Ancak yeni sistem ilanları ücretsiz hesaba premium üyelerden 20 dakika sonra açılır; dış kaynak ilanları ise yalnız premium üyelere görünür.',
    ];

    public function up(): void
    {
        foreach (['cms_contents' => ['value'], 'faqs' => ['answer']] as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                DB::table($table)->where(fn ($q) => $q->where($column, 'like', '%20 dakikaya kadar erken erişim%')->orWhere($column, 'like', '%dış kaynak ilanları önce premium%')->orWhere($column, 'like', '%numara kısmen gizlenir%'))
                    ->orderBy('id')->select(['id', $column])->chunk(100, function ($rows) use ($table, $column): void {
                        foreach ($rows as $row) {
                            $new = strtr((string) $row->{$column}, self::REPLACEMENTS);
                            if ($new !== (string) $row->{$column}) {
                                DB::table($table)->where('id', $row->id)->update([$column => $new]);
                            }
                        }
                    });
            }
        }
        if (Schema::hasTable('scraped_loads')) {
            DB::table('scraped_loads')->whereNotNull('available_to_free_at')->update(['available_to_free_at' => null]);
        }
        Cache::forget('cms_contents.all');
    }

    public function down(): void
    {
        // Metin değişikliği geri alınmaz.
    }
};
