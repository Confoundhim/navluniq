<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** "WhatsApp Destek Hattı" ifadesi kalıyor: bir önceki migration'ın saklanan metinlerde yaptığı değişiklik geri alınır. */
return new class extends Migration
{
    private const REPLACEMENTS = [
        'mesaj hattından yazabilirsiniz' => 'WhatsApp destek hattından mesaj gönderebilirsiniz',
        'mesaj hattımızı' => 'WhatsApp destek hattımızı',
        'Mesaj hattı' => 'WhatsApp Destek Hattı',
        'mesaj hattı' => 'WhatsApp destek hattı',
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
                DB::table($table)->where($column, 'like', '%mesaj hatt%')->orderBy('id')->select(['id', $column])->chunk(100, function ($rows) use ($table, $column): void {
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
