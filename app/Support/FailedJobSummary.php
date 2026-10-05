<?php

namespace App\Support;

/** failed_jobs kaydındaki hata metnini sağlık ekranına sığan, sorunun yerini söyleyen tek satıra indirger. */
class FailedJobSummary
{
    public static function line(?string $exception, int $limit = 160): string
    {
        $first = trim((string) strtok((string) $exception, "\n"));
        if ($first === '') {
            return '';
        }
        // Zincirli hatada ilk satır en içteki hatadır (PDOException); dosya yolu ve "in /var/www/..." kuyruğu atılır.
        $first = preg_replace('/\s+in\s+\/\S+.*$/u', '', $first) ?? $first;
        $first = preg_replace('/^[A-Za-z\\\\]+Exception:\s*/u', '', $first) ?? $first;
        // MySQL 1406: kolon adı mesajın sonundadır; kesilince kaybolmasın diye öne alınır.
        if (preg_match("/Data too long for column '([^']+)'/u", $first, $m)) {
            $first = 'Kolon "'.$m[1].'" için veri çok uzun (1406)';
        }

        return mb_substr($first, 0, $limit);
    }
}
