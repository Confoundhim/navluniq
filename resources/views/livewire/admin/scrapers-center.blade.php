<?php

use App\Models\ActivityLog;
use App\Models\ScrapedLoad;
use App\Services\ScrapedLoadService;
use App\Models\Scraper;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $activeTab = 'queue';

    public string $queueFilter = 'pending';

    public string $sourceName = '';

    public string $sourceType = 'whatsapp';

    public string $sourceIdentifier = '';

    public ?int $editingId = null;

    /** @var array<string, mixed> Satır içi düzenleme formu */
    public array $edit = [];

    public function mount(): void
    {
        abort_unless(auth()->user()->can('manage scrapers'), 403);
    }

    public function updatedActiveTab(): void
    {
        $this->resetPage();
    }

    public function updatedQueueFilter(): void
    {
        $this->resetPage();
    }

    public function addSource(): void
    {
        if (! auth()->user()?->can('manage scrapers')) {
            session()->flash('error_message', 'Bu işlem için yetkiniz yok.');

            return;
        }

        $this->validate([
            'sourceName' => 'required|string|min:3|max:120',
            'sourceType' => 'required|in:whatsapp,notification,telegram,web',
            'sourceIdentifier' => ['required', 'string', 'max:255', Rule::unique('scrapers', 'source_identifier')->where('type', $this->sourceType)->whereNull('deleted_at')],
        ], ['sourceIdentifier.unique' => 'Bu kaynak tanımlayıcısı aynı türde zaten kayıtlı.']);

        $scraper = Scraper::create([
            'name' => trim($this->sourceName),
            'type' => $this->sourceType,
            'source_identifier' => trim($this->sourceIdentifier),
            'is_active' => false,
        ]);
        ActivityLog::record('scraper.created', "Kaynak eklendi: {$scraper->name} ({$scraper->type})", auth()->id(), $scraper);
        $this->reset(['sourceName', 'sourceIdentifier']);
        session()->flash('success_message', 'Kaynak pasif olarak eklendi; ilan kabul etmesi için aktif edin.');
    }

    public function toggleSource(int $scraperId): void
    {
        if (! auth()->user()?->can('manage scrapers')) {
            session()->flash('error_message', 'Bu işlem için yetkiniz yok.');

            return;
        }

        $scraper = Scraper::query()->find($scraperId);
        if (! $scraper) {
            return;
        }
        $scraper->update(['is_active' => ! $scraper->is_active]);
        ActivityLog::record('scraper.toggled', "Kaynak {$scraper->name} ".($scraper->is_active ? 'aktif edildi' : 'pasife alındı'), auth()->id(), $scraper);
    }

    public function deleteSource(int $scraperId): void
    {
        if (! auth()->user()?->can('manage scrapers')) {
            session()->flash('error_message', 'Bu işlem için yetkiniz yok.');

            return;
        }

        $scraper = Scraper::query()->find($scraperId);
        if (! $scraper) {
            return;
        }
        $scraper->delete();
        ActivityLog::record('scraper.deleted', "Kaynak silindi: {$scraper->name}", auth()->id());
        session()->flash('success_message', 'Kaynak silindi; mevcut ilan adayları korunur.');
    }

    public function approve(int $loadId): void
    {
        if (! auth()->user()?->can('manage scrapers')) {
            session()->flash('error_message', 'Bu işlem için yetkiniz yok.');

            return;
        }

        $load = ScrapedLoad::query()->find($loadId);
        if (! $load) {
            session()->flash('error_message', 'İlan adayı bulunamadı.');

            return;
        }

        try {
            app(ScrapedLoadService::class)->approve($load, auth()->id());
        } catch (\RuntimeException $e) {
            session()->flash('error_message', $e->getMessage());

            return;
        }

        $delay = app(ScrapedLoadService::class)->freeDelayMinutes();
        session()->flash('success_message', "İlan havuza alındı; premium olmayan şoförlere {$delay} dakika sonra açılır.");
    }

    public function startEdit(int $loadId): void
    {
        $load = ScrapedLoad::query()->find($loadId);
        if (! $load || ! auth()->user()?->can('manage scrapers')) {
            return;
        }
        $this->editingId = $load->id;
        $this->edit = [
            'pickup_province_code' => $load->pickup_province_code,
            'pickup_district' => $load->pickup_district ?? '',
            'delivery_province_code' => $load->delivery_province_code,
            'delivery_district' => $load->delivery_district ?? '',
            'vehicle_type' => $load->vehicle_type ?? '',
            'goods_type' => $load->goods_type ?? '',
            'weight' => $load->weight ? (int) $load->weight : '',
            'price' => $load->price !== null ? (float) $load->price : '',
        ];
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->edit = [];
    }

    public function saveEdit(): void
    {
        $load = $this->editingId ? ScrapedLoad::query()->find($this->editingId) : null;
        if (! $load || ! auth()->user()?->can('manage scrapers')) {
            return;
        }
        $codes = array_column(\App\Support\TurkishLocations::provinces(), 'code');
        $this->validate([
            'edit.pickup_province_code' => ['required', 'integer', Rule::in($codes)],
            'edit.delivery_province_code' => ['required', 'integer', Rule::in($codes)],
            'edit.pickup_district' => ['nullable', 'string', 'max:60'],
            'edit.delivery_district' => ['nullable', 'string', 'max:60'],
            'edit.vehicle_type' => ['nullable', Rule::in(array_keys(\App\Support\VehicleTypes::TYPES))],
            'edit.goods_type' => ['nullable', 'string', 'max:120'],
            'edit.weight' => ['nullable', 'integer', 'min:1', 'max:60000'],
            'edit.price' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
        ], [], [
            'edit.pickup_province_code' => 'kalkış ili', 'edit.delivery_province_code' => 'varış ili', 'edit.vehicle_type' => 'araç tipi',
            'edit.goods_type' => 'yük türü', 'edit.weight' => 'tonaj', 'edit.price' => 'fiyat',
        ]);

        $place = function (int $code, string $district): array {
            $r = \App\Support\TurkishLocations::resolve(trim(\App\Support\TurkishLocations::province($code)['name'].' '.$district));
            $p = \App\Support\TurkishLocations::province($code);

            return [
                'label' => $p['name'].(($r['district'] ?? null) ? ' '.$r['district'] : ''),
                'district' => $r['district'] ?? null,
                'lat' => $r['lat'] ?? $p['lat'],
                'lng' => $r['lng'] ?? $p['lng'],
            ];
        };
        $pickup = $place((int) $this->edit['pickup_province_code'], (string) $this->edit['pickup_district']);
        $delivery = $place((int) $this->edit['delivery_province_code'], (string) $this->edit['delivery_district']);

        $load->forceFill([
            'pickup_location' => $pickup['label'], 'pickup_province_code' => (int) $this->edit['pickup_province_code'], 'pickup_district' => $pickup['district'],
            'pickup_lat' => $pickup['lat'], 'pickup_lng' => $pickup['lng'],
            'delivery_location' => $delivery['label'], 'delivery_province_code' => (int) $this->edit['delivery_province_code'], 'delivery_district' => $delivery['district'],
            'delivery_lat' => $delivery['lat'], 'delivery_lng' => $delivery['lng'],
            'vehicle_type' => $this->edit['vehicle_type'] !== '' ? $this->edit['vehicle_type'] : null,
            'vehicle_type_source' => $this->edit['vehicle_type'] !== '' ? 'admin' : null,
            'goods_type' => trim((string) $this->edit['goods_type']) ?: null,
            'weight' => $this->edit['weight'] !== '' ? (int) $this->edit['weight'] : null,
            'price' => $this->edit['price'] !== '' ? round((float) $this->edit['price'], 2) : null,
            'status' => $load->status === 'parsed_partial' ? 'parsed_success' : $load->status,
            'parse_metadata' => array_merge((array) ($load->parse_metadata ?? []), ['admin_edited' => true, 'warnings' => []]),
        ])->save();
        ActivityLog::record('scraped_load.edited', "Dış kaynak ilanı #{$load->id} düzenlendi", auth()->id(), $load);
        $this->cancelEdit();
        session()->flash('success_message', 'İlan adayı güncellendi.');
    }

    public function reject(int $loadId): void
    {
        if (! auth()->user()?->can('manage scrapers')) {
            session()->flash('error_message', 'Bu işlem için yetkiniz yok.');

            return;
        }

        $load = ScrapedLoad::query()->find($loadId);
        if (! $load) {
            return;
        }
        app(ScrapedLoadService::class)->reject($load, auth()->id());
        session()->flash('success_message', 'İlan adayı reddedildi.');
    }

    public function with(): array
    {
        $data = ['sources' => null, 'queue' => null, 'freeDelay' => app(ScrapedLoadService::class)->freeDelayMinutes(), 'autoApprove' => \App\Support\Settings::bool('scraper_auto_approve'), 'telegramOn' => app(\App\Services\TelegramPublisher::class)->isConfigured()];

        if ($this->activeTab === 'sources') {
            $data['sources'] = Scraper::query()->withCount('scrapedLoads')->latest('id')->paginate(15);
        } else {
            $query = ScrapedLoad::query()->with('scraper');
            match ($this->queueFilter) {
                'published' => $query->where('visibility', 'public'),
                'rejected' => $query->where('status', 'rejected'),
                default => $query->where('visibility', 'private')->where('status', '!=', 'rejected'),
            };
            $data['queue'] = $query->latest('id')->paginate(15);
        }

        return $data;
    }
}; ?>

<div @if(! $editingId) wire:poll.5s @endif class="max-w-7xl mx-auto space-y-6">
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
        <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">Dış Kaynak İlanları</h1>
        <p class="page-subtitle">Dış kaynaklardan gelen ilan adayları burada incelenir; yalnız onaylananlar şoför havuzunda görünür.</p>
    </div>

    <div class="flex p-0.5 bg-neutral-100 dark:bg-neutral-900 rounded-xl">
        <button type="button" wire:click="$set('activeTab', 'queue')" class="flex-1 px-4 py-2 text-xs font-semibold rounded-lg {{ $activeTab === 'queue' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">İnceleme kuyruğu</button>
        <button type="button" wire:click="$set('activeTab', 'sources')" class="flex-1 px-4 py-2 text-xs font-semibold rounded-lg {{ $activeTab === 'sources' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">Kaynaklar</button>
    </div>

    @if($activeTab === 'queue')
        <div class="apple-glass p-3 rounded-2xl">
            <select wire:model.live="queueFilter" class="{{ $input }} sm:w-56">
                <option value="pending">Onay bekleyenler</option>
                <option value="published">Yayındakiler</option>
                <option value="rejected">Reddedilenler</option>
            </select>
        </div>
        <div class="apple-glass rounded-3xl overflow-hidden">
            <div class="responsive-scroll">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-neutral-100 dark:border-neutral-800/50 text-[11px] text-neutral-400">
                            <th class="p-4">Aday</th>
                            <th class="p-4">Güzergah / yük</th>
                            <th class="p-4">Fiyat</th>
                            <th class="p-4">Ham mesaj</th>
                            <th class="p-4">Durum</th>
                            <th class="p-4"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/40">
                        @forelse($queue as $load)
                            <tr class="align-top {{ (int) $load->duplicate_count > 1 ? 'bg-amber-500/5 border-l-4 border-l-amber-500' : '' }}">
                                <td class="p-4"><span class="font-bold">#{{ $load->id }}</span>
                                    @if((int) $load->duplicate_count > 1)
                                        <span class="ml-1 inline-flex items-center justify-center min-w-[1.5rem] h-6 px-1.5 rounded-full bg-amber-500 text-white text-[11px] font-bold align-middle" title="Bu ilan {{ $load->duplicate_count }} kaynakta görüldü: {{ implode(', ', (array) $load->seen_sources) }}">{{ $load->duplicate_count }}</span>
                                    @endif
                                    @if($load->auto_approved_at)
                                        <span class="ml-1 badge bg-sky-500/10 text-sky-600 dark:text-sky-400 align-middle">Otomatik</span>
                                    @endif
                                    @if($load->telegram_posted_at)
                                        <span class="ml-1 badge bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 align-middle" title="Telegram'a gönderildi: {{ $load->telegram_posted_at->format('d.m.Y H:i') }}">Telegram</span>
                                    @endif<div class="text-[11px] text-neutral-400">{{ $load->scraper?->name ?? 'Kaynak silinmiş' }} · {{ $load->created_at?->format('d.m.Y H:i') }}</div><div class="text-[11px] text-neutral-400">Telefon: {{ $load->masked_phone }}</div></td>
                                <td class="p-4">
                                    @php $warnings = (array) $load->meta('warnings', []); @endphp
                                    <div class="font-bold text-neutral-900 dark:text-white text-sm">
                                        @if($load->isUrgent())<span class="badge bg-red-500 text-white mr-1">ACİL</span>@endif
                                        {{ $load->pickup_location ?: '—' }} <span class="text-neutral-400">→</span> {{ $load->delivery_location ?: '—' }}
                                    </div>
                                    <div class="mt-1 flex flex-wrap items-center gap-1">
                                        @if($load->vehicle_type)
                                            <span class="badge {{ in_array($load->vehicle_type_source, ['keyword', 'ai', 'admin'], true) ? 'bg-neutral-900 dark:bg-white text-white dark:text-neutral-900' : 'bg-neutral-100 dark:bg-neutral-800 text-neutral-700 dark:text-neutral-200' }}" title="Kaynak: {{ ['keyword' => 'araç adı', 'hint' => 'kasa ipucu', 'weight' => 'tonaj', 'pallet' => 'palet adedi', 'volume' => 'hacim', 'goods' => 'yük türünden çıkarım', 'ai' => 'yapay zeka', 'admin' => 'yönetici'][$load->vehicle_type_source] ?? $load->vehicle_type_source }}{{ $load->meta('vehicle_evidence') ? ' · '.implode(', ', (array) $load->meta('vehicle_evidence')) : '' }}">{{ $load->vehicleLabel() }}</span>
                                        @else
                                            <span class="badge bg-amber-500/10 text-amber-600">Araç tipi çözülemedi</span>
                                        @endif
                                        @if($load->goods_type)<span class="badge bg-sky-500/10 text-sky-700 dark:text-sky-300">{{ $load->goods_type }}</span>@endif
                                        @if($load->weightLabel())<span class="badge bg-neutral-100 dark:bg-neutral-800 text-neutral-700 dark:text-neutral-200">{{ $load->weightLabel() }}</span>@endif
                                        @foreach($load->traitLabels() as $trait)<span class="badge bg-violet-500/10 text-violet-700 dark:text-violet-300">{{ $trait }}</span>@endforeach
                                        @if($load->meta('pickup_note'))<span class="badge bg-neutral-100 dark:bg-neutral-800 text-neutral-500">Yükleme: {{ $load->meta('pickup_note') }}</span>@endif
                                    </div>
                                    @if(in_array('pickup_unresolved', $warnings, true) || in_array('delivery_unresolved', $warnings, true))
                                        <div class="mt-1 text-[11px] text-red-600 font-semibold">İl çözülemedi; yayın öncesi düzenleyin.</div>
                                    @elseif(in_array('vehicle_inferred', $warnings, true))
                                        <div class="mt-1 text-[11px] text-neutral-400">Araç tipi ilandan çıkarıldı; şoförlere "{{ $load->vehicleLabel() }}" olarak gösterilir.</div>
                                    @endif
                                </td>
                                <td class="p-4 whitespace-nowrap font-semibold">{{ $load->price !== null ? number_format((float) $load->price, 2, ',', '.').' ₺' : '—' }}</td>
                                <td class="p-4 max-w-xs text-neutral-500">{{ \Illuminate\Support\Str::limit($load->raw_message, 160) }}</td>
                                <td class="p-4">
                                    <span class="px-2 py-1 rounded-full text-[10px] font-semibold {{ $load->visibility === 'public' ? 'bg-emerald-500/10 text-emerald-600' : ($load->status === 'rejected' ? 'bg-red-500/10 text-red-600' : 'bg-amber-500/10 text-amber-600') }}">{{ $load->visibility === 'public' ? 'Yayında' : ($load->status === 'rejected' ? 'Reddedildi' : ($load->status === 'parsed_partial' ? 'Eksik ayrıştırma' : 'Onay bekliyor')) }}</span>
                                    @if($load->available_to_free_at)
                                        <div class="text-[11px] text-neutral-400 mt-1">Herkese açılış: {{ \Illuminate\Support\Carbon::parse($load->available_to_free_at)->format('d.m.Y H:i') }}</div>
                                    @endif
                                    @if($load->parse_confidence !== null)
                                        <div class="text-[11px] text-neutral-400">Çözümleme güveni: %{{ number_format((float) $load->parse_confidence * 100, 0) }}</div>
                                    @endif
                                </td>
                                <td class="p-4 whitespace-nowrap space-x-2">
                                    @if($load->visibility !== 'public')
                                        <button type="button" wire:click="approve({{ $load->id }})" class="text-emerald-600 font-semibold">Yayınla</button>
                                    @endif
                                    <button type="button" wire:click="startEdit({{ $load->id }})" class="text-neutral-600 dark:text-neutral-300 font-semibold">Düzenle</button>
                                    @if($load->status !== 'rejected')
                                        <button type="button" wire:click="reject({{ $load->id }})" wire:confirm="İlan adayı reddedilecek ve havuzdan kaldırılacak. Devam edilsin mi?" class="text-red-500 font-semibold">Reddet</button>
                                    @endif
                                </td>
                            </tr>
                            @if($editingId === $load->id)
                                <tr class="bg-neutral-50 dark:bg-neutral-900/60">
                                    <td colspan="6" class="p-4">
                                        <form wire:submit.prevent="saveEdit" class="grid grid-cols-2 md:grid-cols-4 gap-3 text-xs">
                                            <div>
                                                <label class="form-label">Kalkış ili</label>
                                                <select wire:model="edit.pickup_province_code" class="{{ $input }}">
                                                    <option value="">Seçin</option>
                                                    @foreach(\App\Support\TurkishLocations::provinces() as $p)<option value="{{ $p['code'] }}">{{ $p['name'] }}</option>@endforeach
                                                </select>
                                                @error('edit.pickup_province_code')<div class="text-red-500 mt-1">{{ $message }}</div>@enderror
                                            </div>
                                            <div><label class="form-label">Kalkış ilçesi</label><input type="text" wire:model="edit.pickup_district" class="{{ $input }}" placeholder="İsteğe bağlı"></div>
                                            <div>
                                                <label class="form-label">Varış ili</label>
                                                <select wire:model="edit.delivery_province_code" class="{{ $input }}">
                                                    <option value="">Seçin</option>
                                                    @foreach(\App\Support\TurkishLocations::provinces() as $p)<option value="{{ $p['code'] }}">{{ $p['name'] }}</option>@endforeach
                                                </select>
                                                @error('edit.delivery_province_code')<div class="text-red-500 mt-1">{{ $message }}</div>@enderror
                                            </div>
                                            <div><label class="form-label">Varış ilçesi</label><input type="text" wire:model="edit.delivery_district" class="{{ $input }}" placeholder="İsteğe bağlı"></div>
                                            <div>
                                                <label class="form-label">Araç tipi (en küçük uygun)</label>
                                                <select wire:model="edit.vehicle_type" class="{{ $input }}">
                                                    <option value="">Belirsiz</option>
                                                    @foreach(\App\Support\VehicleTypes::labels() as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                                                </select>
                                            </div>
                                            <div><label class="form-label">Yük türü</label><input type="text" wire:model="edit.goods_type" class="{{ $input }}" list="goods-catalog"></div>
                                            <div><label class="form-label">Tonaj (kg)</label><input type="number" wire:model="edit.weight" class="{{ $input }}" min="1" max="60000">@error('edit.weight')<div class="text-red-500 mt-1">{{ $message }}</div>@enderror</div>
                                            <div><label class="form-label">Fiyat (₺)</label><input type="number" step="0.01" wire:model="edit.price" class="{{ $input }}" min="0">@error('edit.price')<div class="text-red-500 mt-1">{{ $message }}</div>@enderror</div>
                                            <datalist id="goods-catalog">@foreach(\App\Support\GoodsCatalog::labels() as $label)<option value="{{ $label }}"></option>@endforeach</datalist>
                                            <div class="col-span-2 md:col-span-4 flex items-center gap-3">
                                                <button type="submit" class="btn-primary text-xs px-4 py-2">Kaydet</button>
                                                <button type="button" wire:click="cancelEdit" class="btn-secondary text-xs px-4 py-2">Vazgeç</button>
                                                <span class="text-neutral-400">Düzenlenen alanlar otomatik standartlaştırmadan etkilenmez.</span>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr><td colspan="6" class="p-10 text-center text-neutral-500">Bu filtrede ilan adayı yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-4 border-t border-neutral-100 dark:border-neutral-800/50 text-xs">{{ $queue->links() }}</div>
        </div>
        <p class="text-[11px] text-neutral-400">Yayınlanan aday, premium şoförlere hemen; diğer şoförlere {{ $freeDelay }} dakika sonra görünür.
            Otomatik onay: <span class="font-semibold {{ $autoApprove ? 'text-emerald-600' : 'text-neutral-500' }}">{{ $autoApprove ? 'açık' : 'kapalı' }}</span> ·
            Telegram paylaşımı: <span class="font-semibold {{ $telegramOn ? 'text-emerald-600' : 'text-neutral-500' }}">{{ $telegramOn ? 'açık' : 'kapalı' }}</span>
            (Sistem Ayarları → Dış kaynak ve Telegram). Sarı çerçeveli satırlar birden fazla kaynakta görülen ilanlardır; sayaç kaynak sayısını gösterir.</p>
    @endif

    @if($activeTab === 'sources')
        <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 items-start">
            <form wire:submit="addSource" class="apple-glass rounded-3xl p-6 space-y-3 text-xs">
                <h2 class="text-sm font-bold text-neutral-900 dark:text-white">Yeni kaynak</h2>
                <div>
                    <label class="form-label">Ad</label>
                    <input type="text" wire:model="sourceName" class="{{ $input }}">
                    @error('sourceName') <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="form-label">Tür</label>
                    <select wire:model="sourceType" class="{{ $input }}">
                        <option value="whatsapp">WhatsApp grubu</option>
                        <option value="notification">WhatsApp grubu (bildirim iletici)</option>
                        <option value="telegram">Telegram kanalı</option>
                        <option value="web">Web sayfası</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Tanımlayıcı (grup kimliği veya adres)</label>
                    <input type="text" wire:model="sourceIdentifier" class="{{ $input }} font-mono">
                    @error('sourceIdentifier') <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror
                </div>
                <button type="submit" wire:loading.attr="disabled" class="btn-apple-brand py-2.5 px-5 text-xs">Kaynağı ekle</button>
                <p class="text-[11px] text-neutral-400">Kaynak, ilgili toplayıcı servisi bu tanımlayıcıyla mesaj gönderdiğinde ilan adayı üretir; pasif kaynaklardan gelen mesajlar kabul edilmez.</p>
            </form>

            <div class="xl:col-span-2 apple-glass rounded-3xl overflow-hidden">
                <div class="responsive-scroll">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="border-b border-neutral-100 dark:border-neutral-800/50 text-[11px] text-neutral-400">
                                <th class="p-4">Kaynak</th>
                                <th class="p-4">Aday</th>
                                <th class="p-4">Son başarı</th>
                                <th class="p-4">Son hata</th>
                                <th class="p-4">Durum</th>
                                <th class="p-4"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/40">
                            @forelse($sources as $source)
                                <tr class="align-top">
                                    <td class="p-4"><div class="font-bold">{{ $source->name }}</div><div class="text-[11px] text-neutral-400">{{ ['whatsapp' => 'WhatsApp', 'notification' => 'Bildirim iletici', 'telegram' => 'Telegram', 'web' => 'Web'][$source->type] ?? $source->type }} · <span class="font-mono">{{ $source->source_identifier }}</span></div></td>
                                    <td class="p-4">{{ $source->scraped_loads_count }}</td>
                                    <td class="p-4 whitespace-nowrap text-neutral-500">{{ $source->last_success_at ? \Illuminate\Support\Carbon::parse($source->last_success_at)->format('d.m.Y H:i') : 'Henüz yok' }}</td>
                                    <td class="p-4 max-w-xs text-red-500">{{ $source->last_error ? \Illuminate\Support\Str::limit($source->last_error, 100) : '—' }}</td>
                                    <td class="p-4"><span class="px-2 py-1 rounded-full text-[10px] font-semibold {{ $source->is_active ? 'bg-emerald-500/10 text-emerald-600' : 'bg-neutral-500/10 text-neutral-500' }}">{{ $source->is_active ? 'Aktif' : 'Pasif' }}</span></td>
                                    <td class="p-4 whitespace-nowrap space-x-2">
                                        <button type="button" wire:click="toggleSource({{ $source->id }})" class="text-brand-500 font-semibold">{{ $source->is_active ? 'Pasife al' : 'Aktif et' }}</button>
                                        <button type="button" wire:click="deleteSource({{ $source->id }})" wire:confirm="Kaynak silinecek. Devam edilsin mi?" class="text-red-500 font-semibold">Sil</button>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="p-10 text-center text-neutral-500">Henüz kaynak tanımlanmadı.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="p-4 border-t border-neutral-100 dark:border-neutral-800/50 text-xs">{{ $sources->links() }}</div>
            </div>
        </div>
    @endif
</div>
