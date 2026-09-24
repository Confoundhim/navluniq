<?php

namespace App\Services;

use App\Models\Load;
use App\Models\ScrapedLoad;
use App\Support\TurkishLocations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Tanıtım ve yönetim sayaçları. "Bugüne kadar" sayıları hiç düşmez: arşivlenen (listeden kalkan) dış
 * kaynak ilanları da sayılır; yalnız reddedilen adaylar (ilan olmayan mesajlar) sayılmaz.
 */
class LoadStatsService
{
    public const CACHE_KEY = 'load_stats.summary';

    /**
     * @return array{
     *   external_total:int, external_today:int, external_7d:int, external_30d:int, external_daily_avg:float, external_open:int, external_since:?string,
     *   system_total:int, system_open:int, completed:int, top_provinces: list<array{name:string,count:int}>
     * }
     */
    public function summary(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addMinute(), fn () => $this->compute());
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function compute(): array
    {
        $published = fn () => ScrapedLoad::withTrashed()->whereNotNull('published_at');
        $first = $published()->min('published_at');
        $days = $first ? max(1, (int) Carbon::parse($first)->startOfDay()->diffInDays(now()->startOfDay()) + 1) : 1; // ilk yayın günü dahil
        $total = $published()->count();

        $top = ScrapedLoad::withTrashed()->whereNotNull('published_at')->where('published_at', '>=', now()->subDays(30))
            ->whereNotNull('pickup_province_code')
            ->selectRaw('pickup_province_code, COUNT(*) AS c')->groupBy('pickup_province_code')->orderByDesc('c')->limit(5)->get()
            ->map(fn ($r) => ['name' => TurkishLocations::province((int) $r->pickup_province_code)['name'] ?? (string) $r->pickup_province_code, 'count' => (int) $r->c])
            ->all();

        return [
            'external_total' => $total,
            'external_today' => $published()->where('published_at', '>=', now()->startOfDay())->count(),
            'external_7d' => $published()->where('published_at', '>=', now()->subDays(7))->count(),
            'external_30d' => $published()->where('published_at', '>=', now()->subDays(30))->count(),
            'external_daily_avg' => round($total / $days, 1),
            'external_open' => ScrapedLoad::query()->where('visibility', 'public')->where('status', 'parsed_success')->count(),
            'external_since' => $first ? Carbon::parse($first)->format('d.m.Y') : null,
            'system_total' => Load::query()->count(),
            'system_open' => Load::query()->whereIn('status', [Load::STATUS_ACTIVE, Load::STATUS_ASSIGNED, Load::STATUS_ON_THE_WAY])->count(),
            'completed' => Load::query()->whereIn('status', [Load::STATUS_DELIVERED, Load::STATUS_COMPLETED])->count(),
            'top_provinces' => $top,
        ];
    }
}
