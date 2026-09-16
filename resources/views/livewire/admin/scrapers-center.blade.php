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
                                <td class="p-4">{{ $load->pickup_location ?: '—' }} <span class="text-neutral-400">→</span> {{ $load->delivery_location ?: '—' }}<div class="text-[11px] text-neutral-400">{{ $load->goods_type ?: 'Yük türü belirsiz' }}@if($load->weight) · {{ number_format((int) $load->weight, 0, ',', '.') }} kg @endif</div></td>
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
                                    @if($load->status !== 'rejected')
                                        <button type="button" wire:click="reject({{ $load->id }})" wire:confirm="İlan adayı reddedilecek ve havuzdan kaldırılacak. Devam edilsin mi?" class="text-red-500 font-semibold">Reddet</button>
                                    @endif
                                </td>
                            </tr>
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
