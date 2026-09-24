<?php

use App\Livewire\Concerns\HandlesExternalLoadActions;
use App\Livewire\Concerns\HandlesJobActions;
use App\Models\DriverSavedLoad;
use App\Models\DriverTrip;
use App\Models\ScrapedLoad;
use App\Services\DriverTripService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * İşlerim: şoförün tüm işleri tek listede. NavlunIQ ilanından kabul edilen teklifler ve gruptan
 * "Bu işi aldım" denen ilanlar aynı kartla görünür; kaynak rozetle ayrılır. Açık / Geçmiş sekmesi.
 */
new
#[Layout('components.layouts.driver')]
#[Title('İşlerim')]
class extends Component {
    use HandlesExternalLoadActions;
    use HandlesJobActions;
    use WithPagination;

    public string $tab = 'open';

    public function mount(DriverTripService $trips): void
    {
        if ($profile = Auth::user()->driverProfile) {
            $trips->reconcile($profile); // sefer kaydı olmayan / geride kalmış NavlunIQ işleri düzeltilir
        }
        $this->tab = request()->query('sekme') === 'past' ? 'past' : 'open';
        // Bildirim bağlantısı: ?is=ID (eski bildirimlerde ?sefer=ID)
        $id = (int) request()->query('is', request()->query('sefer', 0));
        if ($id > 0 && ($status = $this->jobsQuery()->whereKey($id)->value('status'))) {
            $this->expandedJob = $id;
            $this->tab = $status === DriverTrip::STATUS_CLOSED ? 'past' : 'open';
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab === 'past' ? 'past' : 'open';
        $this->expandedJob = null;
        $this->resetPage();
    }

    public function with(): array
    {
        $profile = Auth::user()->driverProfile;
        $profileId = $profile?->id ?? 0;
        $past = null;
        if ($this->tab === 'past') {
            $past = $this->jobsQuery()->with(['scrapedLoad', 'cargoLoad.cargoOwnerProfile', 'shipment'])
                ->where('status', DriverTrip::STATUS_CLOSED)->orderByDesc('closed_at')->orderByDesc('id')->paginate(50);
            $trips = $past->getCollection();
        } else {
            $trips = $this->openJobsQuery()->get();
        }
        $returnLoads = $this->tab === 'open' ? $this->returnLoadsForExpanded($trips, app(DriverTripService::class)) : null;
        $takenIds = $this->jobsQuery()->open()->whereNotNull('scraped_load_id')->pluck('scraped_load_id')->map(fn ($v) => (int) $v)->all();

        return [
            'trips' => $trips,
            'past' => $past,
            'returnLoads' => $returnLoads,
            'savedExternalIds' => DriverSavedLoad::query()->where('driver_profile_id', $profileId)->whereNotNull('scraped_load_id')->pluck('scraped_load_id')->map(fn ($v) => (int) $v)->all(),
            'takenExternalIds' => $takenIds,
            'takeLoad' => $this->takeLoadId ? ScrapedLoad::query()->whereKey($this->takeLoadId)->first() : null,
            'radiusKm' => \App\Support\Settings::int('return_load_radius_km'),
            'isPremium' => $profile?->isPremium() ?? false,
        ];
    }
}; ?>

<div class="space-y-6">
    @if (session()->has('success_message'))
        <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-600 dark:text-emerald-400 text-xs font-semibold">{{ session('success_message') }}</div>
    @endif
    @if (session()->has('error_message'))
        <div class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-700 dark:text-rose-300 text-xs font-semibold">{{ session('error_message') }}</div>
    @endif

    <div class="border-b border-neutral-200 dark:border-neutral-800 pb-4 flex flex-col sm:flex-row sm:items-end justify-between gap-3">
        <div>
            <h2 class="page-title">İşlerim</h2>
            <p class="page-subtitle">Kabul edilen teklifleriniz ve "Bu işi aldım" dediğiniz ilanlar. Varış yerinizin çevresinden ({{ $radiusKm }} km ve aynı il) çıkan yeni ilanlar dönüş yükü olarak bildirilir.</p>
        </div>
        <div class="flex flex-wrap gap-2 text-xs">
            @foreach(['open' => 'Açık işler', 'past' => 'Geçmiş'] as $key => $label)
                <button type="button" wire:click="setTab('{{ $key }}')" class="px-4 py-2 rounded-xl font-bold border whitespace-nowrap transition-colors {{ $tab === $key ? 'bg-brand-500/10 border-brand-500/30 text-brand-400' : 'bg-white dark:bg-neutral-900 border-neutral-200 dark:border-neutral-800 text-neutral-500 dark:text-neutral-400 hover:text-neutral-900 dark:hover:text-white' }}">{{ $label }}</button>
            @endforeach
        </div>
    </div>

    <div class="space-y-3">
        @forelse($trips as $trip)
            <x-job-card :trip="$trip" :expanded="$expandedJob === $trip->id" :return-loads="$expandedJob === $trip->id ? $returnLoads : null" :saved-external-ids="$savedExternalIds" :taken-external-ids="$takenExternalIds" :is-premium="$isPremium" wire:key="job-{{ $trip->id }}" />
        @empty
            <div class="p-8 bg-white dark:bg-neutral-900 border border-dashed border-neutral-200 dark:border-neutral-800 rounded-2xl text-center text-xs text-neutral-500 dark:text-neutral-400 space-y-2">
                <div>{{ $tab === 'past' ? 'Kapanmış iş yok.' : 'Açık işiniz yok.' }}</div>
                @if($tab === 'open')<div>Bir teklifiniz kabul edilince ya da gruptan derlenen bir ilanda <strong>Bu işi aldım</strong> deyince iş burada açılır.</div>
                <a href="{{ route('driver.loads.index') }}" wire:navigate class="inline-block text-brand-400 font-bold hover:underline">İlan havuzuna git</a>@endif
            </div>
        @endforelse
        @if($past && $past->hasPages())
            <div class="pt-2 text-xs">{{ $past->links() }}</div>
        @endif
    </div>
    <x-take-trip-modal :load="$takeModalOpen ? $takeLoad : null" />
</div>
