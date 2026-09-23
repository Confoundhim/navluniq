<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Kaba ve okunur göreli zaman ("az önce", "3 dk önce", "2 sa önce", "dün", "5 gün önce", sonra tarih).
 * Saniye gösterilmez: saniye sayacı ekran çizimine bağlı kalır ve donuk görünür. Aynı kural
 * tarayıcıda da uygulanır (resources/js/app.js, data-ago); etiket sayfa çizilmeden kendi kendine ilerler.
 */
final class TimeAgo
{
    public static function label(CarbonInterface|string|null $at, ?string $empty = null): ?string
    {
        if ($at === null || $at === '') {
            return $empty;
        }
        $at = $at instanceof CarbonInterface ? $at : Carbon::parse($at);
        $seconds = now()->getTimestamp() - $at->getTimestamp();
        $future = $seconds < 0;
        $s = abs($seconds);
        $suffix = $future ? ' sonra' : ' önce';

        if ($s < 60) {
            return $future ? 'birazdan' : 'az önce';
        }
        if ($s < 3600) {
            return intdiv($s, 60).' dk'.$suffix;
        }
        if ($s < 86400) {
            return intdiv($s, 3600).' sa'.$suffix;
        }
        $days = intdiv($s, 86400);
        if ($days === 1) {
            return $future ? 'yarın' : 'dün';
        }
        if ($days < 30) {
            return $days.' gün'.$suffix;
        }

        return $at->format('d.m.Y');
    }
}
