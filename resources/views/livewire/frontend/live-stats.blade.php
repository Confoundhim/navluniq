<?php

use App\Models\DriverVehicle;
use App\Services\LoadStatsService;
use Livewire\Volt\Component;

/**
 * Ana sayfa canlı sayaçları. 15 sn'de bir (sekme görünürken) yenilenir; yalnız bu kutu çizilir, sayfanın
 * kalanı kıpırdamaz. Sayı artınca tarayıcıda eski değerden yeniye akarak sayar ve kısa bir vurgu yapar
 * (resources/js/app.js: countUp). Sayaçlar "bugüne kadar" mantığıyla hiç düşmez.
 */
new class extends Component {
    public int $vehicleCount = 0;

    /** @var array<string, mixed> */
    public array $stats = [];

    public function mount(): void
    {
        $this->tick();
    }

    public function tick(): void
    {
        $this->vehicleCount = DriverVehicle::whereHas('driverProfile', fn ($q) => $q->where('kyc_status', 'approved'))->count();
        $this->stats = app(LoadStatsService::class)->summary();
    }
}; ?>

<section class="max-w-7xl mx-auto px-6 md:px-12" wire:poll.15s.visible="tick">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6">
        <div class="apple-glass rounded-3xl p-6 text-center space-y-1 shadow-apple-sm">
            <span class="text-xs font-bold text-neutral-400 uppercase tracking-wider">Aktif Kayıtlı Araç</span>
            <div class="live-counter text-3xl sm:text-4xl font-black text-neutral-950 dark:text-white tabular-nums" x-data="countUp" data-value="{{ $vehicleCount }}" x-text="text">{{ number_format($vehicleCount, 0, ',', '.') }}</div>
            <span class="text-[10px] text-emerald-500 font-bold">Doğrulanmış Filo</span>
        </div>
        <div class="apple-glass rounded-3xl p-6 text-center space-y-1 shadow-apple-sm">
            <span class="text-xs font-bold text-neutral-400 uppercase tracking-wider">Sistem İlanları</span>
            <div class="live-counter text-3xl sm:text-4xl font-black text-brand-500 tabular-nums" x-data="countUp" data-value="{{ $stats['system_total'] ?? 0 }}" x-text="text">{{ number_format($stats['system_total'] ?? 0, 0, ',', '.') }}</div>
            <span class="text-[10px] text-neutral-400 font-medium">Bugüne kadar açılan · şu an açık {{ number_format($stats['system_open'] ?? 0, 0, ',', '.') }}</span>
        </div>
        <div class="apple-glass rounded-3xl p-6 text-center space-y-1 shadow-apple-sm">
            <span class="text-xs font-bold text-neutral-400 uppercase tracking-wider">Dış Kaynak İlanları</span>
            <div class="live-counter text-3xl sm:text-4xl font-black text-neutral-950 dark:text-white tabular-nums" x-data="countUp" data-value="{{ $stats['external_total'] ?? 0 }}" x-text="text">{{ number_format($stats['external_total'] ?? 0, 0, ',', '.') }}</div>
            <span class="text-[10px] text-brand-500 font-bold">Bugün {{ number_format($stats['external_today'] ?? 0, 0, ',', '.') }} yeni · günde ortalama {{ number_format($stats['external_daily_avg'] ?? 0, 0, ',', '.') }}</span>
        </div>
        <div class="apple-glass rounded-3xl p-6 text-center space-y-1 shadow-apple-sm">
            <span class="text-xs font-bold text-neutral-400 uppercase tracking-wider">Başarılı Sevkiyat</span>
            <div class="live-counter text-3xl sm:text-4xl font-black text-emerald-500 tabular-nums" x-data="countUp" data-value="{{ $stats['completed'] ?? 0 }}" x-text="text">{{ number_format($stats['completed'] ?? 0, 0, ',', '.') }}</div>
            <span class="text-[10px] text-emerald-600 font-bold">Onaylı teslimat</span>
        </div>
    </div>
</section>
