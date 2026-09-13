<?php

use App\Models\ActivityLog;
use App\Models\Load;
use App\Models\Shipment;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $statusFilter = 'all';

    public string $search = '';

    #[Locked]
    public ?int $selectedId = null;

    public string $suspendReason = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('view operations'), 403);
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function select(int $loadId): void
    {
        $this->selectedId = $loadId;
        $this->suspendReason = '';
        $this->resetErrorBag();
    }

    public function closePanel(): void
    {
        $this->selectedId = null;
        $this->suspendReason = '';
    }

    /** Ödemesi alınmamış bir ilanı yönetici kararıyla iptal eder; havuz bakiyesine dokunmaz. */
    public function suspend(): void
    {
        if (! auth()->user()->can('manage operations')) {
            session()->flash('error_message', 'İlan askıya almak için "manage operations" izni gerekir.');

            return;
        }

        $this->validate(['suspendReason' => 'required|string|min:5|max:1000'], [
            'suspendReason.required' => 'Askıya alma gerekçesi zorunludur.',
            'suspendReason.min' => 'Gerekçe en az 5 karakter olmalıdır.',
        ]);

        try {
            $load = DB::transaction(function (): Load {
                $locked = Load::query()->lockForUpdate()->find($this->selectedId);
                if (! $locked) {
                    throw new RuntimeException('İlan bulunamadı.');
                }
                if ($locked->escrow_status !== Load::ESCROW_PENDING || ! in_array($locked->status, [Load::STATUS_ACTIVE, Load::STATUS_ASSIGNED], true)) {
                    throw new RuntimeException('Yalnız ödemesi alınmamış ve henüz yola çıkmamış ilanlar askıya alınabilir.');
                }

                $locked->offers()->whereIn('status', ['pending', 'accepted'])->update(['status' => 'rejected', 'responded_at' => now()]);
                $locked->shipment()->update(['status' => Shipment::STATUS_CANCELLED]);
                $locked->update([
                    'status' => Load::STATUS_CANCELLED,
                    'rejection_reason' => 'Yönetici kararı: '.mb_substr($this->suspendReason, 0, 900),
                    'cancelled_at' => now(),
                    'visibility' => 'private',
                ]);

                ActivityLog::record('load.suspended', "İlan #{$locked->id} yönetici tarafından iptal edildi: {$this->suspendReason}", auth()->id(), $locked);

                return $locked;
            });

            if ($owner = $load->cargoOwnerProfile?->user) {
                app(NotificationService::class)->notify($owner, 'İlanınız yönetici tarafından kaldırıldı',
                    ["#{$load->id} numaralı ilanınız platform kuralları gereği yayından kaldırıldı.", 'Gerekçe: '.$this->suspendReason],
                    route('cargo-owner.loads.index'), 'İlanlarımı gör');
            }

            $this->suspendReason = '';
            session()->flash('success_message', 'İlan iptal edildi, bekleyen teklifler reddedildi.');
        } catch (\RuntimeException $e) {
            session()->flash('error_message', $e->getMessage());
        }
    }

    /** Gizlenmiş bir ilanı yeniden şoför havuzuna açar. */
    public function relist(): void
    {
        if (! auth()->user()->can('manage operations')) {
            session()->flash('error_message', 'İlanı yeniden yayınlamak için "manage operations" izni gerekir.');

            return;
        }

        $load = Load::query()->find($this->selectedId);
        if (! $load || $load->status !== Load::STATUS_ACTIVE) {
            session()->flash('error_message', 'Yalnız teklif bekleyen ilanlar yeniden yayınlanabilir.');

            return;
        }

        $load->update(['visibility' => 'public']);
        ActivityLog::record('load.relisted', "İlan #{$load->id} yeniden havuza açıldı", auth()->id(), $load);
        session()->flash('success_message', 'İlan şoför havuzunda yeniden görünür.');
    }

    public function with(): array
    {
        $query = Load::query()->with(['cargoOwnerProfile.user', 'driverProfile.user']);

        if ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }

        $search = trim($this->search);
        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('pickup_location', 'like', "%{$search}%")
                    ->orWhere('delivery_location', 'like', "%{$search}%")
                    ->orWhere('goods_type', 'like', "%{$search}%");
                if (ctype_digit($search)) {
                    $q->orWhere('id', (int) $search);
                }
            });
        }

        $selected = $this->selectedId
            ? Load::query()->with([
                'cargoOwnerProfile.user', 'driverProfile.user',
                'offers.driverProfile.user', 'shipment.vehicle', 'shipment.latestLocation',
                'paymentOrders', 'disputes',
            ])->find($this->selectedId)
            : null;

        $transit = Shipment::query()->where('status', Shipment::STATUS_IN_TRANSIT)
            ->with(['latestLocation', 'driverProfile.user', 'cargoLoad'])
            ->get()
            ->filter(fn (Shipment $s) => $s->latestLocation !== null)
            ->map(fn (Shipment $s) => [
                'lat' => (float) $s->latestLocation->latitude,
                'lng' => (float) $s->latestLocation->longitude,
                'label' => 'İlan #'.$s->load_id.' · '.($s->driverProfile?->user?->full_name ?? 'Şoför').' · '.($s->cargoLoad ? $s->cargoLoad->pickup_location.' - '.$s->cargoLoad->delivery_location : ''),
                'time' => $s->latestLocation->recorded_at?->format('d.m.Y H:i'),
                'speed' => (float) $s->latestLocation->speed,
            ])->values()->all();

        return [
            'loads' => $query->latest('id')->paginate(15),
            'selected' => $selected,
            'mapPoints' => $transit,
            'canManage' => auth()->user()->can('manage operations'),
        ];
    }
}; ?>

<div class="max-w-7xl mx-auto space-y-6">
    @php
        $input = 'w-full px-3 py-2 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/60 dark:border-neutral-700/40 text-neutral-900 dark:text-white text-xs rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500';
    @endphp

    @if (session()->has('success_message'))
        <div class="p-4 bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200/50 dark:border-emerald-800/30 text-emerald-600 dark:text-emerald-400 text-xs rounded-2xl">{{ session('success_message') }}</div>
    @endif
    @if (session()->has('error_message'))
        <div class="p-4 bg-red-50 dark:bg-red-950/20 border border-red-200/50 dark:border-red-800/30 text-red-600 dark:text-red-400 text-xs rounded-2xl">{{ session('error_message') }}</div>
    @endif

    <div>
        <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">Operasyonlar</h1>
        <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">İlanlar, teklifler, sevkiyatlar ve yoldaki araçların son bildirilen konumları.</p>
    </div>

    <section wire:poll.30s class="apple-glass rounded-3xl p-6 space-y-4">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-bold text-neutral-900 dark:text-white">Yoldaki sevkiyatlar</h2>
            <span class="text-[11px] text-neutral-400">{{ count($mapPoints) }} araç · 30 saniyede bir yenilenir</span>
        </div>
        @if($mapPoints === [])
            <p class="text-xs text-neutral-500">Şu anda konum bildiren yolda sevkiyat yok.</p>
        @else
            <div wire:key="ops-map-{{ md5(json_encode($mapPoints)) }}"
                 data-points='@json($mapPoints)'
                 x-data="{
                    init() {
                        const points = JSON.parse(this.$el.dataset.points || '[]');
                        if (typeof window.L === 'undefined' || points.length === 0) { this.$refs.fallback.hidden = false; return; }
                        const map = L.map(this.$refs.canvas, { zoomControl: true });
                        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 18, attribution: '&copy; OpenStreetMap' }).addTo(map);
                        const bounds = [];
                        points.forEach(p => { L.marker([p.lat, p.lng]).addTo(map).bindPopup(p.label + '<br>' + (p.time || '') + ' · ' + p.speed + ' km/s'); bounds.push([p.lat, p.lng]); });
                        map.fitBounds(bounds, { padding: [30, 30], maxZoom: 12 });
                    }
                 }">
                <div x-ref="canvas" class="h-80 w-full rounded-2xl overflow-hidden border border-neutral-200/60 dark:border-neutral-700/40" wire:ignore></div>
                <p x-ref="fallback" hidden class="text-xs text-amber-600 mt-2">Harita kütüphanesi yüklenemedi; konumlar aşağıda liste olarak gösterilir.</p>
            </div>
            <ul class="text-xs space-y-1">
                @foreach($mapPoints as $p)
                    <li class="flex flex-col sm:flex-row sm:justify-between gap-1 border-b border-neutral-100 dark:border-neutral-800/60 pb-1">
                        <span>{{ $p['label'] }}</span>
                        <span class="text-neutral-400">{{ $p['time'] }} · {{ number_format($p['speed'], 0) }} km/s · {{ $p['lat'] }}, {{ $p['lng'] }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <div class="flex flex-col lg:flex-row gap-3 apple-glass p-3 rounded-2xl">
        <select wire:model.live="statusFilter" class="{{ $input }} lg:w-56">
            <option value="all">Tüm durumlar</option>
            @foreach(\App\Models\Load::STATUS_LABELS as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        <input type="text" wire:model.live.debounce.400ms="search" placeholder="İlan no, güzergah veya yük türü" class="{{ $input }} lg:flex-1">
    </div>

    <div class="grid grid-cols-1 {{ $selected ? 'xl:grid-cols-2' : '' }} gap-6 items-start">
        <div class="apple-glass rounded-3xl overflow-hidden">
            <div class="responsive-scroll">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-neutral-100 dark:border-neutral-800/50 text-[11px] text-neutral-400">
                            <th class="p-4">İlan</th>
                            <th class="p-4">Güzergah</th>
                            <th class="p-4">Yük sahibi</th>
                            <th class="p-4">Şoför</th>
                            <th class="p-4">Navlun</th>
                            <th class="p-4">Durum</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/40">
                        @forelse($loads as $load)
                            <tr wire:click="select({{ $load->id }})" class="cursor-pointer hover:bg-neutral-50/60 dark:hover:bg-neutral-800/30 {{ $selectedId === $load->id ? 'bg-brand-500/5' : '' }}">
                                <td class="p-4 font-bold">#{{ $load->id }}<div class="text-[11px] font-normal text-neutral-400">{{ $load->pickup_date?->format('d.m.Y') }}</div></td>
                                <td class="p-4">{{ $load->pickup_location }} <span class="text-neutral-400">→</span> {{ $load->delivery_location }}</td>
                                <td class="p-4 text-neutral-500">{{ $load->cargoOwnerProfile?->displayName() ?: '—' }}</td>
                                <td class="p-4 text-neutral-500">{{ $load->driverProfile?->user?->full_name ?? '—' }}</td>
                                <td class="p-4 whitespace-nowrap font-semibold">{{ number_format((float) $load->price, 2, ',', '.') }} ₺</td>
                                <td class="p-4"><span class="px-2 py-1 rounded-full text-[10px] font-semibold bg-neutral-500/10 text-neutral-600 dark:text-neutral-300">{{ $load->statusLabel() }}</span><div class="text-[10px] text-neutral-400 mt-1">{{ $load->escrowLabel() }}</div></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="p-10 text-center text-neutral-500">Bu filtreye uyan ilan yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-4 border-t border-neutral-100 dark:border-neutral-800/50 text-xs">{{ $loads->links() }}</div>
        </div>

        @if($selected)
            <div class="apple-glass rounded-3xl p-6 space-y-5 text-xs">
                <div class="flex justify-between items-start border-b border-neutral-100 dark:border-neutral-800/50 pb-4">
                    <div>
                        <h2 class="text-sm font-bold text-neutral-900 dark:text-white">İlan #{{ $selected->id }}</h2>
                        <p class="text-[11px] text-neutral-400">{{ $selected->statusLabel() }} · {{ $selected->escrowLabel() }} · Görünürlük: {{ $selected->visibility === 'public' ? 'Herkese açık' : 'Gizli' }}</p>
                    </div>
                    <button type="button" wire:click="closePanel" class="p-1.5 rounded-full hover:bg-neutral-100 dark:hover:bg-neutral-800">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 bg-neutral-50 dark:bg-neutral-900 p-4 rounded-2xl border border-neutral-200/40 dark:border-neutral-700/40">
                    <div><span class="text-neutral-400 block">Güzergah</span><span class="font-semibold">{{ $selected->pickup_location }} → {{ $selected->delivery_location }}</span></div>
                    <div><span class="text-neutral-400 block">Tarih</span><span class="font-semibold">{{ $selected->pickup_date?->format('d.m.Y') }}@if($selected->delivery_date) – {{ $selected->delivery_date->format('d.m.Y') }}@endif</span></div>
                    <div><span class="text-neutral-400 block">Yük</span><span class="font-semibold">{{ $selected->goods_type }}@if($selected->weight) · {{ number_format((int) $selected->weight, 0, ',', '.') }} kg @endif</span></div>
                    <div><span class="text-neutral-400 block">Araç</span><span class="font-semibold">{{ \App\Models\DriverVehicle::getVehicleTypes()[$selected->vehicle_type] ?? $selected->vehicle_type }}</span></div>
                    <div><span class="text-neutral-400 block">Navlun</span><span class="font-semibold">{{ number_format((float) $selected->price, 2, ',', '.') }} ₺</span></div>
                    <div><span class="text-neutral-400 block">Yük sahibi</span><span class="font-semibold">{{ $selected->cargoOwnerProfile?->displayName() ?: '—' }}</span><span class="block text-[11px] text-neutral-400">{{ $selected->cargoOwnerProfile?->user?->email }}</span></div>
                    <div><span class="text-neutral-400 block">Şoför</span><span class="font-semibold">{{ $selected->driverProfile?->user?->full_name ?? 'Atanmadı' }}</span><span class="block text-[11px] text-neutral-400">{{ $selected->driverProfile?->user?->email }}</span></div>
                    @if($selected->rejection_reason)
                        <div class="sm:col-span-2"><span class="text-neutral-400 block">İptal gerekçesi</span>{{ $selected->rejection_reason }}</div>
                    @endif
                </div>

                <div>
                    <h3 class="text-[11px] font-bold uppercase tracking-wider text-neutral-400 mb-2">Teklifler ({{ $selected->offers->count() }})</h3>
                    @forelse($selected->offers->sortByDesc('id') as $offer)
                        <div class="flex flex-col sm:flex-row sm:justify-between gap-1 border-b border-neutral-100 dark:border-neutral-800/60 py-2">
                            <span>{{ $offer->driverProfile?->user?->full_name ?? 'Şoför' }} · {{ number_format((float) $offer->amount, 2, ',', '.') }} ₺@if($offer->estimated_days) · {{ $offer->estimated_days }} gün @endif</span>
                            <span class="text-neutral-400">{{ \App\Models\Offer::STATUS_LABELS[$offer->status] ?? $offer->status }} · {{ $offer->created_at?->format('d.m.Y H:i') }}</span>
                        </div>
                    @empty
                        <p class="text-neutral-500">Henüz teklif yok.</p>
                    @endforelse
                </div>

                <div>
                    <h3 class="text-[11px] font-bold uppercase tracking-wider text-neutral-400 mb-2">Sevkiyat</h3>
                    @if($selected->shipment)
                        @php $s = $selected->shipment; @endphp
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            <div><span class="text-neutral-400 block">Durum</span>{{ $s->status }}</div>
                            <div><span class="text-neutral-400 block">Araç</span>{{ $s->vehicle?->plate ?? '—' }}</div>
                            <div><span class="text-neutral-400 block">Yola çıkış</span>{{ $s->in_transit_at?->format('d.m.Y H:i') ?? '—' }}</div>
                            <div><span class="text-neutral-400 block">Teslim</span>{{ $s->delivered_at?->format('d.m.Y H:i') ?? '—' }}</div>
                            <div class="sm:col-span-2"><span class="text-neutral-400 block">Son konum</span>{{ $s->latestLocation ? $s->latestLocation->latitude.', '.$s->latestLocation->longitude.' · '.$s->latestLocation->recorded_at?->format('d.m.Y H:i') : 'Konum bildirilmedi' }}</div>
                        </div>
                    @else
                        <p class="text-neutral-500">Sevkiyat oluşturulmadı.</p>
                    @endif
                </div>

                <div>
                    <h3 class="text-[11px] font-bold uppercase tracking-wider text-neutral-400 mb-2">Ödeme emirleri</h3>
                    @forelse($selected->paymentOrders->sortByDesc('id') as $order)
                        <div class="flex flex-col sm:flex-row sm:justify-between gap-1 border-b border-neutral-100 dark:border-neutral-800/60 py-2">
                            <span class="font-mono">{{ $order->merchant_oid }}</span>
                            <span class="text-neutral-400">{{ number_format((float) $order->amount, 2, ',', '.') }} ₺ · {{ $order->status }} · {{ $order->paid_at?->format('d.m.Y H:i') ?? $order->created_at?->format('d.m.Y H:i') }}</span>
                        </div>
                    @empty
                        <p class="text-neutral-500">Ödeme emri yok.</p>
                    @endforelse
                </div>

                @if($selected->disputes->isNotEmpty())
                    <div>
                        <h3 class="text-[11px] font-bold uppercase tracking-wider text-neutral-400 mb-2">Uyuşmazlıklar</h3>
                        @foreach($selected->disputes as $dispute)
                            <div class="border-b border-neutral-100 dark:border-neutral-800/60 py-2">#{{ $dispute->id }} · {{ \App\Models\Dispute::STATUS_LABELS[$dispute->status] ?? $dispute->status }} · {{ $dispute->created_at?->format('d.m.Y H:i') }}</div>
                        @endforeach
                    </div>
                @endif

                @if($canManage)
                    <div class="space-y-3 pt-4 border-t border-neutral-100 dark:border-neutral-800/50">
                        @if($selected->status === \App\Models\Load::STATUS_ACTIVE && $selected->visibility !== 'public')
                            <button type="button" wire:click="relist" wire:loading.attr="disabled" class="btn-apple-secondary py-2 px-4 text-[11px]">Havuzda yeniden yayınla</button>
                        @endif
                        @if($selected->escrow_status === \App\Models\Load::ESCROW_PENDING && in_array($selected->status, [\App\Models\Load::STATUS_ACTIVE, \App\Models\Load::STATUS_ASSIGNED], true))
                            <div class="space-y-2">
                                <label class="text-[11px] font-semibold text-neutral-500">İlanı askıya al (iptal eder, bekleyen teklifleri reddeder)</label>
                                <textarea wire:model="suspendReason" rows="2" placeholder="Gerekçe (yük sahibine iletilir)" class="{{ $input }}"></textarea>
                                @error('suspendReason') <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror
                                <button type="button" wire:click="suspend" wire:confirm="İlan iptal edilecek ve bekleyen teklifler reddedilecek. Devam edilsin mi?" wire:loading.attr="disabled" class="py-2 px-4 rounded-xl bg-red-600 hover:bg-red-700 text-white text-[11px] font-semibold">Askıya al</button>
                            </div>
                        @else
                            <p class="text-[11px] text-neutral-400">Ödemesi alınmış veya yola çıkmış ilanlar buradan iptal edilemez; havuz bakiyesi yalnız uyuşmazlık kararıyla değişir.</p>
                        @endif
                    </div>
                @endif
            </div>
        @endif
    </div>
</div>
