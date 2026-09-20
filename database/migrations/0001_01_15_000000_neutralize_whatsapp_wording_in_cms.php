<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sitede dış kaynak ilanları için "WhatsApp grubu" ibaresi kullanılmaz; veritabanında saklanan (yöneticinin CMS'den
 * kaydettiği ya da eski varsayılanla yazılmış) metinler de tarafsız ifadeye çevrilir. Destek hattı bağlantıları
 * (wa.me) ve kurulum sayfası bu değişiklikten etkilenmez.
 */
return new class extends Migration
{
    private const REPLACEMENTS = [
        'İzinli WhatsApp gruplarından derlenen' => 'İzinli gruplardan ve web mecralarından derlenen',
        'WhatsApp gruplarında paylaşılan' => 'Gruplarda ve webde paylaşılan',
        'WhatsApp gruplarından derlenen' => 'gruplardan derlenen',
        'WhatsApp grupları ve web mecralarından' => 'gruplar ve web mecralarından',
        'WhatsApp grubundaki' => 'gruptaki',
        'WhatsApp gruplarındaki' => 'gruplardaki',
        'WhatsApp gruplarından' => 'gruplardan',
        'WhatsApp gruplarında' => 'gruplarda',
        'WhatsApp grupları' => 'ilan grupları',
        'WhatsApp grubu' => 'ilan grubu',
    ];

    public function up(): void
    {
        $targets = [
            'cms_contents' => ['value'],
            'faqs' => ['question', 'answer'],
            'pages' => ['title', 'content', 'excerpt', 'meta_description'],
        ];
        foreach ($targets as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }
                DB::table($table)->where($column, 'like', '%WhatsApp grup%')->orderBy('id')->select(['id', $column])->chunk(100, function ($rows) use ($table, $column): void {
                    foreach ($rows as $row) {
                        $new = strtr((string) $row->{$column}, self::REPLACEMENTS);
                        if ($new !== (string) $row->{$column}) {
                            DB::table($table)->where('id', $row->id)->update([$column => $new]);
                        }
                    }
                });
            }
        }
        Cache::forget('cms_contents.all');
    }

    public function down(): void
    {
        // Metin değişikliği geri alınmaz.
    }
};
