<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** İletişim kanalı adı yerine "mesaj hattı": saklanan SSS ve CMS metinleri de çevrilir. */
return new class extends Migration
{
    private const REPLACEMENTS = [
        'WhatsApp destek hattından mesaj gönderebilirsiniz' => 'mesaj hattından yazabilirsiniz',
        'WhatsApp destek hattımızı' => 'mesaj hattımızı',
        'WhatsApp destek hattı' => 'mesaj hattı',
        'WhatsApp Destek Hattı' => 'Mesaj hattı',
    ];

    public function up(): void
    {
        foreach (['cms_contents' => ['value'], 'faqs' => ['question', 'answer'], 'pages' => ['title', 'content']] as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }
                DB::table($table)->where($column, 'like', '%WhatsApp destek%')->orderBy('id')->select(['id', $column])->chunk(100, function ($rows) use ($table, $column): void {
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
