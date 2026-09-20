<?php

use App\Models\ActivityLog;
use App\Models\IntakeEvent;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Services\AiParserService;
use App\Services\ScrapedLoadService;
use App\Support\Settings;
use App\Support\TurkishLocations;
use App\Support\VehicleTypes;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Dış kaynak ilanları: inceleme kuyruğu, yayındakiler, reddedilenler, canlı akış (gelen istekler) ve kaynaklar.
 * Toplu işlemler, filtreler, yapay zeka ile yeniden çözümleme, telefon bağlantı anahtarı.
 */
new class extends Component {
    use WithFileUploads, WithPagination;

    /** Telefondan dışa aktarılan MacroDroid makrosu (.macro) */
    public $macroFile = null;

    #[Url(as: 'sekme')]
    public string $activeTab = 'queue';

    #[Url(as: 'ara')]
    public string $search = '';

    public string $sourceId = '';

    public string $vehicle = '';

    public string $period = '7';

    public string $flag = '';

    public string $sort = 'newest';

    /** @var array<int, string> seçili aday kimlikleri */
    public array $selected = [];

    public bool $selectPage = false;

    public string $sourceName = '';

    public string $sourceType = 'notification';

    public string $sourceIdentifier = '';

    public ?int $editingId = null;

    /** @var array<string, mixed> Satır içi düzenleme formu */
    public array $edit = [];

    public function mount(): void
    {
        abort_unless(auth()->user()->can('manage scrapers'), 403);
        if (! in_array($this->activeTab, ['queue', 'published', 'rejected', 'events', 'sources'], true)) {
            $this->activeTab = 'queue';
        }
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['activeTab', 'search', 'sourceId', 'vehicle', 'period', 'flag', 'sort'], true)) {
            $this->resetPage();
            $this->selected = [];
            $this->selectPage = false;
        }
        if ($name === 'selectPage') {
            $this->selected = $this->selectPage ? array_map('strval', $this->currentQuery()->pluck('id')->all()) : [];
        }
    }

    private function can(): bool
    {
        if (auth()->user()?->can('manage scrapers')) {
            return true;
        }
        session()->flash('error_message', 'Bu işlem için yetkiniz yok.');

        return false;
    }

    /** Aktif sekme ve filtrelere göre aday sorgusu (sayfalama öncesi). */
    private function currentQuery()
    {
        $q = ScrapedLoad::query()->with('scraper');
        match ($this->activeTab) {
            'published' => $q->where('visibility', 'public'),
            'rejected' => $q->where('status', 'rejected'),
            default => $q->where('visibility', 'private')->where('status', '!=', 'rejected'),
        };
        if ($this->search !== '') {
            $term = '%'.trim($this->search).'%';
            $q->where(fn ($w) => $w->where('raw_message', 'like', $term)->orWhere('pickup_location', 'like', $term)->orWhere('delivery_location', 'like', $term)->orWhere('goods_type', 'like', $term)->orWhere('id', (int) trim($this->search, '# ')));
        }
        if ($this->sourceId !== '') {
            $q->where('scraper_id', (int) $this->sourceId);
        }
        if ($this->vehicle !== '') {
            $this->vehicle === 'none' ? $q->whereNull('vehicle_type') : $q->where('vehicle_type', $this->vehicle);
        }
        if ($this->period !== 'all') {
            $q->where('created_at', '>=', now()->subDays((int) $this->period));
        }
        match ($this->flag) {
            'unresolved' => $q->where(fn ($w) => $w->whereNull('pickup_province_code')->orWhereNull('delivery_province_code')),
            'priced' => $q->where('price', '>', 0),
            'unpriced' => $q->where(fn ($w) => $w->whereNull('price')->orWhere('price', '<=', 0)),
            'ai' => $q->where('ai_status', 'done'),
            'ai_pending' => $q->where('ai_status', 'pending'),
            'conflict' => $q->whereNotNull('parse_metadata->ai_conflict'),
            'urgent' => $q->where('parse_metadata->urgent', true),
            'duplicates' => $q->where('duplicate_count', '>', 1),
            default => null,
        };
        match ($this->sort) {
            'oldest' => $q->oldest('id'),
            'price_desc' => $q->orderByDesc('price')->orderByDesc('id'),
            'weight_desc' => $q->orderByDesc('weight')->orderByDesc('id'),
            default => $q->latest('id'),
        };

        return $q;
    }

    /** @return \Illuminate\Support\Collection<int, ScrapedLoad> */
    private function selectedLoads()
    {
        return ScrapedLoad::query()->whereIn('id', array_map('intval', $this->selected))->get();
    }

    // ---- Tekil işlemler ----

    public function approve(int $loadId): void
    {
        if (! $this->can()) {
            return;
        }
        $load = ScrapedLoad::query()->find($loadId);
        if (! $load) {
            return;
        }
        try {
            app(ScrapedLoadService::class)->approve($load, auth()->id());
            session()->flash('success_message', "#{$load->id} yayınlandı; premium olmayan şoförlere ".app(ScrapedLoadService::class)->freeDelayMinutes().' dakika sonra açılır.');
        } catch (\RuntimeException $e) {
            session()->flash('error_message', "#{$load->id}: ".$e->getMessage());
        }
    }

    public function reject(int $loadId): void
    {
        if (! $this->can()) {
            return;
        }
        if ($load = ScrapedLoad::query()->find($loadId)) {
            app(ScrapedLoadService::class)->reject($load, auth()->id());
            session()->flash('success_message', "#{$load->id} reddedildi.");
        }
    }

    public function restore(int $loadId): void
    {
        if (! $this->can()) {
            return;
        }
        if ($load = ScrapedLoad::query()->find($loadId)) {
            $load->update(['status' => ($load->pickup_province_code && $load->delivery_province_code) ? 'parsed_success' : 'parsed_partial', 'visibility' => 'private']);
            ActivityLog::record('scraped_load.restored', "Dış kaynak ilanı #{$load->id} kuyruğa geri alındı", auth()->id(), $load);
            session()->flash('success_message', "#{$load->id} inceleme kuyruğuna geri alındı.");
        }
    }

    public function delete(int $loadId): void
    {
        if (! $this->can()) {
            return;
        }
        if ($load = ScrapedLoad::query()->withTrashed()->find($loadId)) {
            app(ScrapedLoadService::class)->delete($load, auth()->id());
            session()->flash('success_message', "#{$loadId} kalıcı olarak silindi.");
        }
    }

    public function reparse(int $loadId): void
    {
        if (! $this->can()) {
            return;
        }
        $load = ScrapedLoad::query()->find($loadId);
        if (! $load) {
            return;
        }
        $parser = app(AiParserService::class);
        if (! $parser->isConfigured()) {
            session()->flash('error_message', 'Yapay zeka anahtarı tanımlı değil (Sistem Ayarları → Dış kaynak ve Telegram → Yapay zeka).');

            return;
        }
        $ok = app(ScrapedLoadService::class)->reparseWithAi($load, $parser, true);
        $errors = collect($parser->lastErrors())->map(fn ($e, $p) => (AiParserService::PROVIDERS[$p]['label'] ?? $p).': '.AiParserService::humanizeError($e['message']))->implode(' · ');
        session()->flash($ok ? 'success_message' : 'error_message', $ok ? "#{$load->id} yapay zeka ile yeniden çözümlendi." : "#{$load->id} çözümlenemedi. ".($errors !== '' ? $errors : 'Sağlayıcı yanıt vermedi (kota/ağ).').' Ayarlar → Yapay zeka bölümünde "Bağlantıyı sına" ile ayrıntı görebilirsiniz; 5 dakika içinde otomatik yeniden denenir.');
    }

    // ---- Toplu işlemler ----

    public function bulk(string $action): void
    {
        if (! $this->can() || $this->selected === []) {
            return;
        }
        $service = app(ScrapedLoadService::class);
        $ok = 0;
        $errors = [];
        foreach ($this->selectedLoads() as $load) {
            try {
                match ($action) {
                    'approve' => $service->approve($load, auth()->id()),
                    'reject' => $service->reject($load, auth()->id()),
                    'delete' => $service->delete($load, auth()->id()),
                    'reparse' => $service->reparseWithAi($load, null, true) ?: throw new \RuntimeException('yapay zeka yanıt vermedi'),
                    'restore' => $this->restore($load->id),
                    default => throw new \RuntimeException('bilinmeyen işlem'),
                };
                $ok++;
            } catch (\Throwable $e) {
                $errors[] = "#{$load->id}: ".$e->getMessage();
            }
        }
        $labels = ['approve' => 'yayınlandı', 'reject' => 'reddedildi', 'delete' => 'silindi', 'reparse' => 'yapay zeka ile çözümlendi', 'restore' => 'kuyruğa alındı'];
        $this->selected = [];
        $this->selectPage = false;
        session()->flash($errors === [] ? 'success_message' : 'error_message', "{$ok} aday {$labels[$action]}.".($errors !== [] ? ' Atlanan: '.implode(' · ', array_slice($errors, 0, 5)) : ''));
    }

    public function purgeRejected(): void
    {
        if (! $this->can()) {
            return;
        }
        $n = app(ScrapedLoadService::class)->purgeRejected(0, auth()->id());
        session()->flash('success_message', "{$n} reddedilmiş aday kalıcı olarak silindi.");
    }

    // ---- Düzenleme ----

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
        $codes = array_column(TurkishLocations::provinces(), 'code');
        $this->validate([
            'edit.pickup_province_code' => ['required', 'integer', Rule::in($codes)],
            'edit.delivery_province_code' => ['required', 'integer', Rule::in($codes)],
            'edit.pickup_district' => ['nullable', 'string', 'max:60'],
            'edit.delivery_district' => ['nullable', 'string', 'max:60'],
            'edit.vehicle_type' => ['nullable', Rule::in(array_keys(VehicleTypes::TYPES))],
            'edit.goods_type' => ['nullable', 'string', 'max:120'],
            'edit.weight' => ['nullable', 'integer', 'min:1', 'max:60000'],
            'edit.price' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
        ], [], [
            'edit.pickup_province_code' => 'kalkış ili', 'edit.delivery_province_code' => 'varış ili', 'edit.vehicle_type' => 'araç tipi',
            'edit.goods_type' => 'yük türü', 'edit.weight' => 'tonaj', 'edit.price' => 'fiyat',
        ]);

        $place = function (int $code, string $district): array {
            $r = TurkishLocations::resolve(trim(TurkishLocations::province($code)['name'].' '.$district));
            $p = TurkishLocations::province($code);

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
        session()->flash('success_message', "#{$load->id} güncellendi.");
    }

    // ---- Kaynaklar ve telefon bağlantısı ----

    public function addSource(): void
    {
        if (! $this->can()) {
            return;
        }
        $this->validate([
            'sourceName' => 'required|string|min:3|max:120',
            'sourceType' => 'required|in:whatsapp,notification,telegram,web',
            'sourceIdentifier' => ['required', 'string', 'max:255', Rule::unique('scrapers', 'source_identifier')->where('type', $this->sourceType)->whereNull('deleted_at')],
        ], ['sourceIdentifier.unique' => 'Bu kaynak tanımlayıcısı aynı türde zaten kayıtlı.']);

        $scraper = Scraper::create(['name' => trim($this->sourceName), 'type' => $this->sourceType, 'source_identifier' => trim($this->sourceIdentifier), 'is_active' => true]);
        ActivityLog::record('scraper.created', "Kaynak eklendi: {$scraper->name} ({$scraper->type})", auth()->id(), $scraper);
        $this->reset(['sourceName', 'sourceIdentifier']);
        session()->flash('success_message', 'Kaynak eklendi ve aktif.');
    }

    public function toggleSource(int $scraperId): void
    {
        if (! $this->can()) {
            return;
        }
        if ($scraper = Scraper::query()->find($scraperId)) {
            $scraper->update(['is_active' => ! $scraper->is_active]);
            ActivityLog::record('scraper.toggled', "Kaynak {$scraper->name} ".($scraper->is_active ? 'aktif edildi' : 'pasife alındı'), auth()->id(), $scraper);
        }
    }

    public function deleteSource(int $scraperId): void
    {
        if (! $this->can()) {
            return;
        }
        if ($scraper = Scraper::query()->find($scraperId)) {
            $scraper->delete();
            ActivityLog::record('scraper.deleted', "Kaynak silindi: {$scraper->name}", auth()->id());
            session()->flash('success_message', 'Kaynak silindi; mevcut ilan adayları korunur.');
        }
    }

    public function regenerateToken(): void
    {
        if (! $this->can()) {
            return;
        }
        ScrapedLoadService::regenerateApiToken(auth()->id());
        session()->flash('success_message', 'Bağlantı anahtarı yenilendi; telefonlardaki makro gövdesini yeni metinle değiştirin.');
    }

    public function regenerateSetupCode(): void
    {
        if (! $this->can()) {
            return;
        }
        ScrapedLoadService::regenerateSetupCode(auth()->id());
        session()->flash('success_message', 'Kurulum bağlantısı yenilendi; eski bağlantı artık açılmaz.');
    }

    public function uploadMacro(): void
    {
        if (! $this->can()) {
            return;
        }
        $this->validate(['macroFile' => 'required|file|max:2048'], ['macroFile.required' => 'Önce .macro dosyasını seçin.', 'macroFile.max' => 'Dosya 2 MB\'tan küçük olmalı.']);
        try {
            $info = ScrapedLoadService::storeMacroTemplate((string) file_get_contents($this->macroFile->getRealPath()), auth()->id());
        } catch (\InvalidArgumentException $e) {
            $this->addError('macroFile', $e->getMessage());

            return;
        }
        $this->macroFile = null;
        session()->flash('success_message', $info['token'] ? 'Makro şablonu yüklendi; kurulum bağlantısından indirilen dosya her zaman güncel anahtarı taşır.' : 'Makro şablonu yüklendi ancak içinde anahtar bulunamadı; indirilen dosyada anahtar değiştirilemez. Makroda gövdeyi paneldeki metinle güncelleyip yeniden dışa aktarın.');
    }

    public function removeMacro(): void
    {
        if (! $this->can()) {
            return;
        }
        ScrapedLoadService::deleteMacroTemplate(auth()->id());
        session()->flash('success_message', 'Makro şablonu kaldırıldı; kurulum sayfası elle kurulum adımlarını gösterir.');
    }

    public function with(): array
    {
        $service = app(ScrapedLoadService::class);
        $parser = app(AiParserService::class);
        $todayEvents = IntakeEvent::query()->where('created_at', '>=', now()->startOfDay());
        $schedulerAge = ScrapedLoadService::schedulerAgeSeconds();

        $data = [
            'freeDelay' => $service->freeDelayMinutes(),
            'autoApprove' => Settings::bool('scraper_auto_approve'),
            'schedulerAge' => $schedulerAge,
            'schedulerOk' => $schedulerAge !== null && $schedulerAge < 180,
            'ai' => ['enabled' => $parser->isEnabled(), 'configured' => $parser->isConfigured(), 'mode' => AiParserService::MODES[$parser->mode()] ?? $parser->mode(), 'provider' => $parser->provider(), 'model' => $parser->model()],
            'stats' => [
                'received' => (clone $todayEvents)->count(),
                'created' => (clone $todayEvents)->where('status', 'created')->count(),
                'filtered' => (clone $todayEvents)->whereIn('status', ['filtered', 'skipped'])->count(),
                'pending' => ScrapedLoad::query()->where('visibility', 'private')->where('status', '!=', 'rejected')->count(),
                'published' => ScrapedLoad::query()->where('visibility', 'public')->count(),
                'rejected' => ScrapedLoad::query()->where('status', 'rejected')->count(),
            ],
            'rejectedRetention' => max(0, Settings::int('scraper_rejected_retention_days')),
            'sourcesList' => Scraper::query()->orderBy('name')->get(['id', 'name']),
            'queue' => null, 'events' => null, 'sources' => null, 'blockers' => [],
            'tokenBody' => '', 'webhookUrl' => url('/api/v1/webhook/notification'), 'setupUrl' => '', 'setupQr' => '', 'pingUrl' => '', 'phoneParams' => [], 'macro' => ['has' => false, 'at' => '', 'token_ok' => false],
        ];

        if ($this->activeTab === 'events') {
            $q = IntakeEvent::query()->with('scrapedLoad')->latest('id');
            if ($this->search !== '') {
                $term = '%'.trim($this->search).'%';
                $q->where(fn ($w) => $w->where('excerpt', 'like', $term)->orWhere('source_name', 'like', $term)->orWhere('title', 'like', $term));
            }
            $data['events'] = $q->paginate(30);
        } elseif ($this->activeTab === 'sources') {
            $data['sources'] = Scraper::query()->withCount('scrapedLoads')->latest('id')->paginate(15);
            $data['tokenBody'] = ScrapedLoadService::phoneRequestBody();
            $data['setupUrl'] = ScrapedLoadService::setupUrl();
            $data['setupQr'] = ScrapedLoadService::setupQrSvg();
            $data['pingUrl'] = ScrapedLoadService::pingUrl();
            $data['phoneParams'] = ScrapedLoadService::phoneRequestParams();
            $data['macro'] = ['has' => ScrapedLoadService::hasMacroTemplate(), 'at' => Settings::string('macrodroid_template_at'), 'token_ok' => Settings::string('macrodroid_template_token') !== ''];
        } else {
            $data['queue'] = $this->currentQuery()->paginate(20);
            if ($this->activeTab === 'queue') {
                foreach ($data['queue'] as $load) {
                    $data['blockers'][$load->id] = $service->autoApprovalBlocker($load);
                }
            }
        }

        return $data;
    }
}; ?>

<div @if(! $editingId && $selected === []) wire:poll.5s @endif class="max-w-7xl mx-auto space-y-5">
    @php
        $input = 'w-full px-3 py-2 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/60 dark:border-neutral-700/40 text-neutral-900 dark:text-white text-xs rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500';
        $tabs = ['queue' => 'İnceleme kuyruğu', 'published' => 'Yayında', 'rejected' => 'Reddedilenler', 'events' => 'Canlı akış', 'sources' => 'Kaynaklar ve telefon'];
        $tabCount = ['queue' => $stats['pending'], 'published' => $stats['published'], 'rejected' => $stats['rejected'], 'events' => $stats['received'], 'sources' => null];
        $blockerLabels = ['durum' => 'Durum uygun değil', 'kaynak pasif' => 'Kaynak pasif', 'rota eksik' => 'Rota eksik', 'il çözülemedi' => 'İl çözülemedi', 'araç tipi yok' => 'Araç tipi yok', 'telefon yok' => 'Telefon yok', 'fiyat yok' => 'Fiyat yok', 'tonaj yok' => 'Tonaj yok'];
    @endphp

    @if (session()->has('success_message'))
        <div class="p-4 bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200/50 dark:border-emerald-800/30 text-emerald-600 dark:text-emerald-400 text-xs rounded-2xl">{{ session('success_message') }}</div>
    @endif
    @if (session()->has('error_message'))
        <div class="p-4 bg-red-50 dark:bg-red-950/20 border border-red-200/50 dark:border-red-800/30 text-red-600 dark:text-red-400 text-xs rounded-2xl">{{ session('error_message') }}</div>
    @endif

    <div class="flex flex-col lg:flex-row lg:items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">Dış Kaynak İlanları</h1>
            <p class="page-subtitle">WhatsApp gruplarından gelen ilan adayları burada standartlaştırılır, incelenir ve yayınlanır. Yayınlananlar şoförlerin ilan listesine düşer.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-[11px]">
            <span class="badge {{ $schedulerOk ? 'bg-emerald-500/10 text-emerald-600' : 'bg-red-500/10 text-red-600' }}" title="{{ $schedulerAge === null ? 'Zamanlayıcıdan hiç nabız gelmedi' : 'Son nabız '.$schedulerAge.' sn önce' }}">Zamanlayıcı: {{ $schedulerOk ? 'çalışıyor' : ($schedulerAge === null ? 'nabız yok' : 'durmuş ('.floor($schedulerAge / 60).' dk)') }}</span>
            <span class="badge {{ $autoApprove ? 'bg-emerald-500/10 text-emerald-600' : 'bg-neutral-100 dark:bg-neutral-800 text-neutral-500' }}">Otomatik onay: {{ $autoApprove ? 'açık' : 'kapalı' }}</span>
            <span class="badge {{ $ai['enabled'] && $ai['configured'] ? 'bg-violet-500/10 text-violet-600' : 'bg-neutral-100 dark:bg-neutral-800 text-neutral-500' }}" title="{{ $ai['provider'] }} · {{ $ai['model'] }}">Yapay zeka: {{ ! $ai['enabled'] ? 'kapalı' : ($ai['configured'] ? $ai['mode'] : 'anahtar yok') }}</span>

            <a href="{{ route('admin.settings') }}" class="text-brand-500 font-semibold hover:underline">Ayarlar →</a>
        </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-xs">
        <div class="apple-glass rounded-2xl p-4"><span class="text-neutral-400 block">Bugün gelen istek</span><span class="text-xl font-black text-neutral-900 dark:text-white">{{ $stats['received'] }}</span><span class="text-[11px] text-neutral-400 block">{{ $stats['created'] }} kuyruğa · {{ $stats['filtered'] }} elendi</span></div>
        <div class="apple-glass rounded-2xl p-4"><span class="text-neutral-400 block">Onay bekleyen</span><span class="text-xl font-black text-amber-600">{{ $stats['pending'] }}</span></div>
        <div class="apple-glass rounded-2xl p-4"><span class="text-neutral-400 block">Yayında</span><span class="text-xl font-black text-emerald-600">{{ $stats['published'] }}</span></div>
        <div class="apple-glass rounded-2xl p-4"><span class="text-neutral-400 block">Reddedilen</span><span class="text-xl font-black text-neutral-500">{{ $stats['rejected'] }}</span><span class="text-[11px] text-neutral-400 block">{{ $rejectedRetention }} gün sonra silinir</span></div>
    </div>

    <div class="flex p-0.5 bg-neutral-100 dark:bg-neutral-900 rounded-xl overflow-x-auto">
        @foreach($tabs as $key => $label)
            <button type="button" wire:click="$set('activeTab', '{{ $key }}')" class="flex-1 whitespace-nowrap px-4 py-2 text-xs font-semibold rounded-lg {{ $activeTab === $key ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">{{ $label }}@if($tabCount[$key] !== null) <span class="ml-1 text-[10px] text-neutral-400">{{ $tabCount[$key] }}</span>@endif</button>
        @endforeach
    </div>

    @if(in_array($activeTab, ['queue', 'published', 'rejected'], true))
        <div class="apple-glass p-3 rounded-2xl grid grid-cols-2 md:grid-cols-6 gap-2 text-xs">
            <input type="search" wire:model.live.debounce.400ms="search" class="{{ $input }} md:col-span-2" placeholder="Ara: rota, yük, ham mesaj, #no">
            <select wire:model.live="sourceId" class="{{ $input }}"><option value="">Tüm kaynaklar</option>@foreach($sourcesList as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select>
            <select wire:model.live="vehicle" class="{{ $input }}"><option value="">Tüm araçlar</option><option value="none">Araç tipi yok</option>@foreach(VehicleTypes::labels() as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
            <select wire:model.live="flag" class="{{ $input }}"><option value="">Tüm adaylar</option><option value="unresolved">İl çözülemeyenler</option><option value="priced">Fiyatlı</option><option value="unpriced">Fiyatsız</option><option value="urgent">Acil</option><option value="duplicates">Birden fazla kaynakta</option><option value="ai">Yapay zeka ile çözülen</option><option value="ai_pending">Yapay zeka bekleyen</option><option value="conflict">Kural / yapay zeka çelişen</option></select>
            <div class="flex gap-2">
                <select wire:model.live="period" class="{{ $input }}"><option value="1">Bugün</option><option value="7">7 gün</option><option value="30">30 gün</option><option value="all">Tümü</option></select>
                <select wire:model.live="sort" class="{{ $input }}"><option value="newest">Yeni</option><option value="oldest">Eski</option><option value="price_desc">Fiyat</option><option value="weight_desc">Tonaj</option></select>
            </div>
        </div>

        @if($selected !== [])
            <div class="sticky top-16 z-20 apple-glass rounded-2xl p-3 flex flex-wrap items-center gap-2 text-xs border border-brand-500/30">
                <span class="font-bold text-neutral-900 dark:text-white mr-2">{{ count($selected) }} seçili</span>
                @if($activeTab !== 'published')<button type="button" wire:click="bulk('approve')" class="btn-primary py-1.5 px-3 text-xs">Yayınla</button>@endif
                @if($activeTab !== 'rejected')<button type="button" wire:click="bulk('reject')" wire:confirm="Seçili adaylar reddedilecek." class="btn-secondary py-1.5 px-3 text-xs">Reddet</button>@endif
                @if($activeTab === 'rejected')<button type="button" wire:click="bulk('restore')" class="btn-secondary py-1.5 px-3 text-xs">Kuyruğa geri al</button>@endif
                <button type="button" wire:click="bulk('reparse')" class="btn-secondary py-1.5 px-3 text-xs" title="{{ $ai['configured'] ? $ai['provider'].' · '.$ai['model'] : 'Yapay zeka anahtarı tanımlı değil' }}">Yapay zeka ile çözümle</button>
                <button type="button" wire:click="bulk('delete')" wire:confirm="Seçili adaylar KALICI olarak silinecek; geri alınamaz." class="py-1.5 px-3 text-xs font-semibold text-red-600 hover:bg-red-500/10 rounded-xl">Kalıcı sil</button>
                <button type="button" wire:click="$set('selected', [])" class="ml-auto text-neutral-400 hover:text-neutral-600">Seçimi temizle</button>
            </div>
        @endif

        @if($activeTab === 'rejected' && $stats['rejected'] > 0)
            <div class="flex items-center justify-between gap-3 text-[11px] text-neutral-400 px-1">
                <span>Reddedilenler {{ $rejectedRetention }} gün sonra otomatik olarak kalıcı silinir (Sistem Ayarları → Dış kaynak). Yanlış reddedileni "Kuyruğa geri al" ile kurtarabilirsiniz.</span>
                <button type="button" wire:click="purgeRejected" wire:confirm="Tüm reddedilen adaylar KALICI olarak silinecek." class="font-semibold text-red-600 hover:underline whitespace-nowrap">Reddedilenlerin tümünü sil</button>
            </div>
        @endif

        <div class="apple-glass rounded-3xl overflow-hidden">
            <div class="responsive-scroll">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-neutral-100 dark:border-neutral-800/50 text-[11px] text-neutral-400">
                            <th class="p-3 w-8"><input type="checkbox" wire:model.live="selectPage" class="rounded" title="Sayfadakilerin tümünü seç"></th>
                            <th class="p-3">Aday</th>
                            <th class="p-3">Güzergah / yük</th>
                            <th class="p-3">Fiyat</th>
                            <th class="p-3">Ham mesaj</th>
                            <th class="p-3">Durum</th>
                            <th class="p-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/40">
                        @forelse($queue as $load)
                            @php $warnings = (array) $load->meta('warnings', []); $blocker = $blockers[$load->id] ?? null; $aiMeta = (array) $load->meta('ai', []); @endphp
                            <tr wire:key="load-{{ $load->id }}" class="align-top {{ in_array((string) $load->id, $selected, true) ? 'bg-brand-500/5' : ((int) $load->duplicate_count > 1 ? 'bg-amber-500/5' : '') }}">
                                <td class="p-3"><input type="checkbox" wire:model.live="selected" value="{{ $load->id }}" class="rounded mt-1"></td>
                                <td class="p-3">
                                    <div class="font-bold">#{{ $load->id }}
                                        @if((int) $load->duplicate_count > 1)<span class="ml-1 inline-flex items-center justify-center min-w-[1.5rem] h-5 px-1.5 rounded-full bg-amber-500 text-white text-[10px] font-bold align-middle" title="{{ implode(', ', (array) $load->seen_sources) }}">{{ $load->duplicate_count }}</span>@endif
                                        @if($load->auto_approved_at)<span class="ml-1 badge bg-sky-500/10 text-sky-600 align-middle">Otomatik</span>@endif
                                        
                                    </div>
                                    <div class="text-[11px] text-neutral-400">{{ $load->scraper?->name ?? 'Kaynak silinmiş' }}<br>{{ $load->created_at?->format('d.m.Y H:i') }}<br>{{ $load->masked_phone }}</div>
                                </td>
                                <td class="p-3">
                                    <div class="font-bold text-neutral-900 dark:text-white text-sm">
                                        @if($load->isUrgent())<span class="badge bg-red-500 text-white mr-1">ACİL</span>@endif
                                        {{ $load->pickup_location ?: '—' }} <span class="text-neutral-400">→</span> {{ $load->delivery_location ?: '—' }}
                                    </div>
                                    <div class="mt-1 flex flex-wrap items-center gap-1">
                                        @if($load->vehicle_type)
                                            <span class="badge {{ in_array($load->vehicle_type_source, ['keyword', 'ai', 'admin'], true) ? 'bg-neutral-900 dark:bg-white text-white dark:text-neutral-900' : 'bg-neutral-100 dark:bg-neutral-800 text-neutral-700 dark:text-neutral-200' }}" title="Kaynak: {{ ['keyword' => 'araç adı', 'hint' => 'kasa ipucu', 'weight' => 'tonaj', 'pallet' => 'palet adedi', 'volume' => 'hacim', 'goods' => 'yük türünden çıkarım', 'ai' => 'yapay zeka', 'admin' => 'yönetici'][$load->vehicle_type_source] ?? $load->vehicle_type_source }}">{{ $load->vehicleLabel() }}</span>
                                        @else
                                            <span class="badge bg-amber-500/10 text-amber-600">Araç tipi yok</span>
                                        @endif
                                        @if($load->goods_type)<span class="badge bg-sky-500/10 text-sky-700 dark:text-sky-300">{{ $load->goods_type }}</span>@endif
                                        @if($load->weightLabel())<span class="badge bg-neutral-100 dark:bg-neutral-800 text-neutral-700 dark:text-neutral-200">{{ $load->weightLabel() }}</span>@endif
                                        @foreach($load->traitLabels() as $trait)<span class="badge bg-violet-500/10 text-violet-700 dark:text-violet-300">{{ $trait }}</span>@endforeach
                                        @if($load->meta('pickup_note'))<span class="badge bg-neutral-100 dark:bg-neutral-800 text-neutral-500">Yükleme: {{ $load->meta('pickup_note') }}</span>@endif
                                    </div>
                                    <div class="mt-1 text-[11px] text-neutral-400">
                                        Çözümleme: <span class="font-semibold {{ str_contains((string) $load->parsed_by_llm, 'claude') || str_contains((string) $load->parsed_by_llm, 'gemini') ? 'text-violet-600' : '' }}">{{ ['regex_verified' => 'kural', 'regex' => 'kural'][$load->parsed_by_llm] ?? ($load->parsed_by_llm ?: '—') }}</span>
                                        @if($load->parse_confidence !== null) · güven %{{ number_format((float) $load->parse_confidence * 100, 0) }}@endif
                                        @if(! empty($aiMeta['notes'])) · <span title="{{ $aiMeta['notes'] }}">{{ \Illuminate\Support\Str::limit($aiMeta['notes'], 60) }}</span>@endif
                                        @if($load->ai_status === 'pending') · <span class="text-amber-600">yapay zeka sırada</span>@endif
                                    </div>
                                    @if(in_array('pickup_unresolved', $warnings, true) || in_array('delivery_unresolved', $warnings, true))
                                        <div class="mt-1 text-[11px] text-red-600 font-semibold">İl çözülemedi; yayın öncesi düzenleyin ya da yapay zeka ile çözümleyin.</div>
                                    @endif
                                </td>
                                <td class="p-3 whitespace-nowrap font-semibold">{{ $load->price !== null && (float) $load->price > 0 ? number_format((float) $load->price, 0, ',', '.').' ₺' : '—' }}</td>
                                <td class="p-3 max-w-xs text-neutral-500"><span title="{{ $load->raw_message }}">{{ \Illuminate\Support\Str::limit($load->raw_message, 140) }}</span></td>
                                <td class="p-3">
                                    <span class="px-2 py-1 rounded-full text-[10px] font-semibold {{ $load->visibility === 'public' ? 'bg-emerald-500/10 text-emerald-600' : ($load->status === 'rejected' ? 'bg-red-500/10 text-red-600' : 'bg-amber-500/10 text-amber-600') }}">{{ $load->visibility === 'public' ? 'Yayında' : ($load->status === 'rejected' ? 'Reddedildi' : 'Onay bekliyor') }}</span>
                                    @if($activeTab === 'queue')
                                        <div class="text-[11px] mt-1 {{ $blocker ? 'text-amber-600' : 'text-emerald-600' }}">{{ $autoApprove ? 'Otomatik onay: ' : 'Otomatik onay kapalı · ' }}{{ $blocker ? ($blockerLabels[$blocker] ?? $blocker) : 'uygun' }}</div>
                                    @endif
                                    @if($load->available_to_free_at)<div class="text-[11px] text-neutral-400 mt-1">Herkese: {{ \Illuminate\Support\Carbon::parse($load->available_to_free_at)->format('d.m H:i') }}</div>@endif
                                </td>
                                <td class="p-3 whitespace-nowrap">
                                    <div class="flex flex-col gap-1 items-start">
                                        @if($load->visibility !== 'public' && $load->status !== 'rejected')<button type="button" wire:click="approve({{ $load->id }})" class="text-emerald-600 font-semibold">Yayınla</button>@endif
                                        @if($load->status === 'rejected')<button type="button" wire:click="restore({{ $load->id }})" class="text-emerald-600 font-semibold">Kuyruğa geri al</button>@endif
                                        <button type="button" wire:click="startEdit({{ $load->id }})" class="text-neutral-600 dark:text-neutral-300 font-semibold">Düzenle</button>
                                        <button type="button" wire:click="reparse({{ $load->id }})" class="text-violet-600 font-semibold">Yapay zeka ile çözümle</button>
                                        @if($load->status !== 'rejected')<button type="button" wire:click="reject({{ $load->id }})" wire:confirm="Aday reddedilecek." class="text-red-500 font-semibold">Reddet</button>@endif
                                        <button type="button" wire:click="delete({{ $load->id }})" wire:confirm="Aday KALICI olarak silinecek; geri alınamaz." class="text-neutral-400 hover:text-red-600">Sil</button>
                                    </div>
                                </td>
                            </tr>
                            @if($editingId === $load->id)
                                <tr class="bg-neutral-50 dark:bg-neutral-900/60">
                                    <td colspan="7" class="p-4">
                                        <form wire:submit.prevent="saveEdit" class="grid grid-cols-2 md:grid-cols-4 gap-3 text-xs">
                                            <div>
                                                <label class="form-label">Kalkış ili</label>
                                                <select wire:model="edit.pickup_province_code" class="{{ $input }}"><option value="">Seçin</option>@foreach(TurkishLocations::provinces() as $p)<option value="{{ $p['code'] }}">{{ $p['name'] }}</option>@endforeach</select>
                                                @error('edit.pickup_province_code')<div class="text-red-500 mt-1">{{ $message }}</div>@enderror
                                            </div>
                                            <div><label class="form-label">Kalkış ilçesi</label><input type="text" wire:model="edit.pickup_district" class="{{ $input }}" placeholder="İsteğe bağlı"></div>
                                            <div>
                                                <label class="form-label">Varış ili</label>
                                                <select wire:model="edit.delivery_province_code" class="{{ $input }}"><option value="">Seçin</option>@foreach(TurkishLocations::provinces() as $p)<option value="{{ $p['code'] }}">{{ $p['name'] }}</option>@endforeach</select>
                                                @error('edit.delivery_province_code')<div class="text-red-500 mt-1">{{ $message }}</div>@enderror
                                            </div>
                                            <div><label class="form-label">Varış ilçesi</label><input type="text" wire:model="edit.delivery_district" class="{{ $input }}" placeholder="İsteğe bağlı"></div>
                                            <div>
                                                <label class="form-label">Araç tipi (en küçük uygun)</label>
                                                <select wire:model="edit.vehicle_type" class="{{ $input }}"><option value="">Belirsiz</option>@foreach(VehicleTypes::labels() as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
                                            </div>
                                            <div><label class="form-label">Yük türü</label><input type="text" wire:model="edit.goods_type" class="{{ $input }}" list="goods-catalog"></div>
                                            <div><label class="form-label">Tonaj (kg)</label><input type="number" wire:model="edit.weight" class="{{ $input }}" min="1" max="60000">@error('edit.weight')<div class="text-red-500 mt-1">{{ $message }}</div>@enderror</div>
                                            <div><label class="form-label">Fiyat (₺)</label><input type="number" step="0.01" wire:model="edit.price" class="{{ $input }}" min="0">@error('edit.price')<div class="text-red-500 mt-1">{{ $message }}</div>@enderror</div>
                                            <datalist id="goods-catalog">@foreach(\App\Support\GoodsCatalog::labels() as $label)<option value="{{ $label }}"></option>@endforeach</datalist>
                                            <div class="col-span-2 md:col-span-4 flex items-center gap-3">
                                                <button type="submit" class="btn-primary text-xs px-4 py-2">Kaydet</button>
                                                <button type="button" wire:click="cancelEdit" class="btn-secondary text-xs px-4 py-2">Vazgeç</button>
                                                <span class="text-neutral-400">Elle düzenlenen aday otomatik standartlaştırmadan ve yapay zeka birleştirmesinden etkilenmez.</span>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr><td colspan="7" class="p-10 text-center text-neutral-500">Bu filtrede aday yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-4 border-t border-neutral-100 dark:border-neutral-800/50 text-xs">{{ $queue->links() }}</div>
        </div>
        <p class="text-[11px] text-neutral-400">Yayınlanan aday premium şoförlere hemen, diğerlerine {{ $freeDelay }} dakika sonra görünür. Sarı zemin: birden fazla kaynakta görülen ilan. "Otomatik onay" satırı adayın neden kendiliğinden yayınlanmadığını söyler.</p>
    @endif

    @if($activeTab === 'events')
        <div class="apple-glass p-3 rounded-2xl flex flex-col sm:flex-row gap-2 text-xs">
            <input type="search" wire:model.live.debounce.400ms="search" class="{{ $input }} sm:max-w-md" placeholder="Ara: kaynak, mesaj">
            <span class="text-[11px] text-neutral-400 self-center">Telefondan gelen her istek burada görünür; "kuyruğa alındı" dışındakiler neden elendiğini söyler. 5 sn'de bir yenilenir.</span>
        </div>
        <div class="apple-glass rounded-3xl overflow-hidden">
            <div class="responsive-scroll">
                <table class="w-full text-left text-xs">
                    <thead><tr class="border-b border-neutral-100 dark:border-neutral-800/50 text-[11px] text-neutral-400"><th class="p-3">Zaman</th><th class="p-3">Kaynak</th><th class="p-3">Sonuç</th><th class="p-3">Mesaj</th><th class="p-3">Aday</th></tr></thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/40">
                        @forelse($events as $e)
                            @php $tone = ['created' => 'bg-emerald-500/10 text-emerald-600', 'duplicate' => 'bg-sky-500/10 text-sky-600', 'source_pending' => 'bg-amber-500/10 text-amber-600', 'unauthorized' => 'bg-red-500/10 text-red-600', 'failed' => 'bg-red-500/10 text-red-600'][$e->status] ?? 'bg-neutral-100 dark:bg-neutral-800 text-neutral-500'; @endphp
                            <tr class="align-top">
                                <td class="p-3 whitespace-nowrap text-neutral-500">{{ $e->created_at->format('d.m H:i:s') }}</td>
                                <td class="p-3">{{ $e->source_name ?: ($e->title ?: '—') }}</td>
                                <td class="p-3"><span class="badge {{ $tone }}">{{ $e->statusLabel() }}</span>@if($e->reason)<div class="text-[11px] text-neutral-400 mt-1">{{ ['phone_missing' => 'telefon numarası yok', 'no_logistics_signal' => 'rota/tonaj/araç/yük işareti yok', 'route_missing' => 'kalkış-varış çözülemedi', 'regex_required_fields_missing' => 'kalkış-varış çözülemedi', 'ai_not_load' => 'yapay zeka: yük ilanı değil', 'token_missing' => 'istekte anahtar yok', 'token_mismatch' => 'anahtar sunucudakiyle uyuşmuyor', 'summary_notification' => 'özet bildirim (N yeni mesaj)', 'empty' => 'başlık ya da metin boş', 'not_whatsapp' => 'WhatsApp dışı uygulama'][$e->reason] ?? $e->reason }}</div>@endif</td>
                                <td class="p-3 max-w-md text-neutral-600 dark:text-neutral-300"><span title="{{ $e->excerpt }}">{{ \Illuminate\Support\Str::limit($e->excerpt, 160) }}</span></td>
                                <td class="p-3 whitespace-nowrap">@if($e->scraped_load_id)<button type="button" wire:click="$set('search', '#{{ $e->scraped_load_id }}'); $set('activeTab', 'queue')" class="text-brand-500 font-semibold">#{{ $e->scraped_load_id }}</button>@else —@endif</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="p-10 text-center text-neutral-500">Henüz istek gelmedi. Telefondaki makro çalışınca her deneme burada görünür.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-4 border-t border-neutral-100 dark:border-neutral-800/50 text-xs">{{ $events->links() }}</div>
        </div>
    @endif

    @if($activeTab === 'sources')
        <div class="apple-glass rounded-3xl p-6 space-y-3 text-xs" x-data="{ copied: '' , copy(text, key) { navigator.clipboard.writeText(text).then(() => { this.copied = key; setTimeout(() => this.copied = '', 2000); }); } }">
            <h2 class="text-sm font-bold text-neutral-900 dark:text-white">Telefon bağlantısı (MacroDroid)</h2>
            <p class="text-[11px] text-neutral-400">Bu adres ve gövde hangi telefona yazılırsa o telefon sunucuya ilan iletmeye başlar; anahtar gövdenin içinde hazırdır, başka ayar gerekmez. En kolayı: aşağıdaki <strong>kurulum bağlantısını</strong> telefon sahibine gönderin (ya da QR'ı okutun); sayfa adım adım anlatır ve hazır makro dosyasını indirtir.</p>

            <div class="grid grid-cols-1 md:grid-cols-[1fr_auto] gap-4 items-start p-4 rounded-2xl bg-brand-50/60 dark:bg-brand-950/20 border border-brand-200/50 dark:border-brand-900/40">
                <div class="space-y-2 min-w-0">
                    <span class="font-bold text-neutral-900 dark:text-white">Kurulum bağlantısı (telefon sahibine gönderin)</span>
                    <code class="block px-3 py-2 rounded-xl bg-white dark:bg-neutral-900 border border-neutral-200/60 dark:border-neutral-700/40 font-mono break-all">{{ $setupUrl }}</code>
                    <div class="flex flex-wrap items-center gap-3">
                        <button type="button" @click="copy(@js($setupUrl), 'setup')" class="btn-primary py-2 px-3 text-xs" x-text="copied === 'setup' ? 'Kopyalandı' : 'Bağlantıyı kopyala'"></button>
                        <a href="https://wa.me/?text={{ urlencode('NavlunIQ ilan iletici kurulumu (5 dk): '.$setupUrl) }}" target="_blank" rel="noopener" class="btn-secondary py-2 px-3 text-xs">WhatsApp ile gönder</a>
                        <button type="button" wire:click="regenerateSetupCode" wire:confirm="Eski bağlantı artık açılmaz. Devam edilsin mi?" class="text-red-600 font-semibold hover:underline">Bağlantıyı yenile</button>
                    </div>
                    <div class="pt-2 space-y-2">
                        <span class="font-bold text-neutral-900 dark:text-white">Hazır makro dosyası</span>
                        @if($macro['has'])
                            <p class="text-[11px] text-neutral-500">Yüklü ({{ $macro['at'] }}). {{ $macro['token_ok'] ? 'İndirilen dosya her zaman güncel anahtarı ve adresi taşır; anahtarı yenileseniz de telefona yeniden yükleme yeter.' : 'Dosyada anahtar bulunamadı; telefondaki makro gövdesini paneldekiyle güncelleyip yeniden dışa aktarın.' }}</p>
                            <div class="flex flex-wrap gap-3">
                                <a href="{{ route('phone-setup.macro', ['code' => \App\Services\ScrapedLoadService::setupCode()]) }}" class="btn-secondary py-2 px-3 text-xs">NavlunIQ.macro indir</a>
                                <button type="button" wire:click="removeMacro" wire:confirm="Şablon kaldırılsın mı?" class="text-red-600 font-semibold hover:underline">Kaldır</button>
                            </div>
                        @else
                            <p class="text-[11px] text-neutral-500">Çalışan telefonda MacroDroid → makro → <strong>Dışa aktar</strong> ile alınan <em>.macro</em> dosyasını bir kez yükleyin; kurulum sayfası bunu diğer telefonlara güncel anahtarla indirtir.</p>
                        @endif
                        <form wire:submit="uploadMacro" class="flex flex-wrap items-center gap-2">
                            <input type="file" wire:model="macroFile" accept=".macro,.json,.txt,application/json" class="text-[11px] file:mr-2 file:rounded-lg file:border-0 file:bg-neutral-100 dark:file:bg-neutral-800 file:px-3 file:py-1.5 file:text-xs file:font-semibold">
                            <button type="submit" wire:loading.attr="disabled" class="btn-secondary py-2 px-3 text-xs">{{ $macro['has'] ? 'Şablonu değiştir' : 'Şablonu yükle' }}</button>
                            <span wire:loading wire:target="macroFile,uploadMacro" class="text-[11px] text-neutral-400">Yükleniyor…</span>
                        </form>
                        @error('macroFile') <span class="text-red-500 text-[11px] block">{{ $message }}</span> @enderror
                    </div>
                </div>
                <div class="justify-self-center md:justify-self-end w-36 h-36 p-2 bg-white rounded-2xl border border-neutral-200/60 [&>svg]:w-full [&>svg]:h-full" title="Telefonla okutun">{!! $setupQr !!}</div>
            </div>

            <div class="flex flex-wrap items-center gap-3 p-3 rounded-2xl bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/60 dark:border-neutral-700/40">
                <span class="font-bold text-neutral-900 dark:text-white">Sorun giderme</span>
                <span class="text-[11px] text-neutral-500">Telefon istek atmıyor gibi görünüyorsa: bu bağlantıyı <strong>telefonun tarayıcısında</strong> açın; Canlı akışa "Bağlantı sınaması" düşerse ağ ve anahtar tamamdır, sorun MacroDroid tetikleyicisindedir (bildirim erişimi, sessize alınmış grup, kendi yazdığınız mesaj bildirim üretmez).</span>
                <button type="button" @click="copy(@js($pingUrl), 'ping')" class="btn-secondary py-2 px-3 text-xs" x-text="copied === 'ping' ? 'Kopyalandı' : 'Sınama bağlantısını kopyala'"></button>
                <a href="{{ $pingUrl }}" target="_blank" rel="noopener" class="text-brand-600 font-semibold hover:underline text-xs">Buradan aç</a>
            </div>

            <details class="text-xs">
                <summary class="cursor-pointer font-semibold text-neutral-700 dark:text-neutral-200">Elle kurulum için adres ve alanlar</summary>
                <p class="text-[11px] text-neutral-500 mt-2">Önerilen: içerik türü <strong>application/x-www-form-urlencoded</strong>, "Parametreler" bölümüne şu alanlar (mesajdaki tırnak/satır sonu JSON'u bozabilir, form alanlarını bozamaz):</p>
                <table class="text-[11px] font-mono mt-1">
                    @foreach($phoneParams as $k => $v)<tr><td class="pr-3 font-bold">{{ $k }}</td><td class="break-all">{{ $v }}</td></tr>@endforeach
                </table>
                <div class="grid grid-cols-1 md:grid-cols-[auto_1fr_auto] gap-2 items-center mt-3">
                    <span class="text-neutral-400">Adres (POST)</span>
                    <code class="block px-3 py-2 rounded-xl bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/60 dark:border-neutral-700/40 font-mono break-all">{{ $webhookUrl }}</code>
                    <button type="button" @click="copy(@js($webhookUrl), 'url')" class="btn-secondary py-2 px-3 text-xs" x-text="copied === 'url' ? 'Kopyalandı' : 'Kopyala'"></button>
                    <span class="text-neutral-400">Alternatif: JSON gövde</span>
                    <code class="block px-3 py-2 rounded-xl bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/60 dark:border-neutral-700/40 font-mono break-all">{{ $tokenBody }}</code>
                    <button type="button" @click="copy(@js($tokenBody), 'body')" class="btn-primary py-2 px-3 text-xs" x-text="copied === 'body' ? 'Kopyalandı' : 'Kopyala'"></button>
                </div>
                <div class="flex items-center gap-3 pt-2">
                    <button type="button" wire:click="regenerateToken" wire:confirm="Anahtar yenilenince telefonlardaki eski gövde çalışmaz (hazır makro dosyası yeni anahtarla indirilir). Devam edilsin mi?" class="text-red-600 font-semibold hover:underline">Anahtarı yenile</button>
                    <span class="text-[11px] text-neutral-400">İçerik türü: application/json · Zaman aşımı: 20 sn · "Yanıtı değişkene kaydet" gerekmez.</span>
                </div>
            </details>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 items-start">
            <form wire:submit="addSource" class="apple-glass rounded-3xl p-6 space-y-3 text-xs">
                <h2 class="text-sm font-bold text-neutral-900 dark:text-white">Yeni kaynak</h2>
                <p class="text-[11px] text-neutral-400">Telefondan ilk mesaj geldiğinde grup kendiliğinden pasif kaynak olarak eklenir; burada elle de tanımlayabilirsiniz.</p>
                <div><label class="form-label">Ad</label><input type="text" wire:model="sourceName" class="{{ $input }}">@error('sourceName') <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror</div>
                <div><label class="form-label">Tür</label>
                    <select wire:model="sourceType" class="{{ $input }}"><option value="notification">WhatsApp grubu (bildirim iletici)</option><option value="whatsapp">WhatsApp grubu (servis)</option><option value="telegram">Telegram kanalı</option><option value="web">Web sayfası</option></select>
                </div>
                <div><label class="form-label">Tanımlayıcı</label><input type="text" wire:model="sourceIdentifier" class="{{ $input }} font-mono" placeholder="notif:grup-adi">@error('sourceIdentifier') <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror</div>
                <button type="submit" wire:loading.attr="disabled" class="btn-apple-brand py-2.5 px-5 text-xs">Kaynağı ekle</button>
            </form>

            <div class="xl:col-span-2 apple-glass rounded-3xl overflow-hidden">
                <div class="responsive-scroll">
                    <table class="w-full text-left text-xs">
                        <thead><tr class="border-b border-neutral-100 dark:border-neutral-800/50 text-[11px] text-neutral-400"><th class="p-4">Kaynak</th><th class="p-4">Aday</th><th class="p-4">Son mesaj</th><th class="p-4">Durum</th><th class="p-4"></th></tr></thead>
                        <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/40">
                            @forelse($sources as $source)
                                <tr class="align-top {{ ! $source->is_active ? 'bg-amber-500/5' : '' }}">
                                    <td class="p-4"><div class="font-bold">{{ $source->name }}</div><div class="text-[11px] text-neutral-400">{{ ['whatsapp' => 'WhatsApp servis', 'notification' => 'Bildirim iletici', 'telegram' => 'Telegram', 'web' => 'Web'][$source->type] ?? $source->type }} · <span class="font-mono">{{ $source->source_identifier }}</span></div></td>
                                    <td class="p-4">{{ $source->scraped_loads_count }}</td>
                                    <td class="p-4 whitespace-nowrap text-neutral-500">{{ $source->last_success_at ? \Illuminate\Support\Carbon::parse($source->last_success_at)->diffForHumans() : 'Henüz yok' }}</td>
                                    <td class="p-4"><span class="px-2 py-1 rounded-full text-[10px] font-semibold {{ $source->is_active ? 'bg-emerald-500/10 text-emerald-600' : 'bg-amber-500/10 text-amber-600' }}">{{ $source->is_active ? 'Aktif' : 'Onay bekliyor' }}</span></td>
                                    <td class="p-4 whitespace-nowrap space-x-2">
                                        <button type="button" wire:click="toggleSource({{ $source->id }})" class="{{ $source->is_active ? 'text-neutral-500' : 'text-emerald-600' }} font-semibold">{{ $source->is_active ? 'Pasife al' : 'Aktif et' }}</button>
                                        <button type="button" wire:click="deleteSource({{ $source->id }})" wire:confirm="Kaynak silinecek. Devam edilsin mi?" class="text-red-500 font-semibold">Sil</button>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="p-10 text-center text-neutral-500">Henüz kaynak yok. Telefondan ilk mesaj gelince grup burada belirir.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="p-4 border-t border-neutral-100 dark:border-neutral-800/50 text-xs">{{ $sources->links() }}</div>
            </div>
        </div>
    @endif
</div>
