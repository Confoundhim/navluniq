<?php

use App\Models\ActivityLog;
use App\Models\AiLexicon;
use App\Models\IntakeEvent;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Services\AiParserService;
use App\Services\LocalClassifier;
use App\Services\ScrapedLoadService;
use App\Support\GoodsCatalog;
use App\Support\Lexicon;
use App\Support\Settings;
use App\Support\TurkishLocations;
use App\Support\VehicleTypes;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Dış kaynak ilanları: inceleme kuyruğu, yayındakiler, reddedilenler, canlı akış (gelen istekler) ve kaynaklar.
 * Toplu işlemler, filtreler, yapay zeka ile yeniden çözümleme, telefon bağlantı anahtarı.
 */
new class extends Component {
    use WithPagination;

    #[Url(as: 'sekme')]
    public string $activeTab = 'queue';

    /** Kaynaklar sekmesi alt listesi: active | pending | deleted */
    #[Url(as: 'kaynak')]
    public string $sourceState = 'active';

    public string $sourceSearch = '';

    /** Kaynaklar sekmesinde seçili kaynak kimlikleri (toplu işlem). */
    public array $selectedSources = [];

    public bool $selectSourcePage = false;

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
        if (! in_array($this->activeTab, ['queue', 'published', 'rejected', 'events', 'sources', 'lexicon'], true)) {
            $this->activeTab = 'queue';
        }
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['activeTab', 'search', 'sourceId', 'vehicle', 'period', 'flag', 'sort', 'sourceState', 'sourceSearch'], true)) {
            $this->resetPage();
            $this->selected = [];
            $this->selectPage = false;
            $this->selectedSources = [];
            $this->selectSourcePage = false;
        }
        if ($name === 'selectSourcePage') {
            $this->selectedSources = $this->selectSourcePage ? array_map('strval', $this->sourceQuery()->limit(20)->pluck('id')->all()) : [];
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

    /** Filtreye uyan TÜM adayları seçer (sayfa sınırı olmadan; en çok 2.000). */
    public function selectAllMatching(): void
    {
        $this->selected = array_map('strval', $this->currentQuery()->limit(2000)->pluck('id')->all());
        $this->selectPage = true;
    }

    /** Kaynaklar sekmesindeki listeyi (alt sekme + arama) veren sorgu. */
    private function sourceQuery()
    {
        if (! in_array($this->sourceState, ['active', 'pending', 'deleted'], true)) {
            $this->sourceState = 'active';
        }
        $term = trim($this->sourceSearch);
        $q = $this->sourceState === 'deleted'
            ? Scraper::onlyTrashed()->latest('deleted_at')
            : Scraper::query()->where('is_active', $this->sourceState === 'active')->orderByDesc('last_message_at')->latest('id');

        return $q->when($term !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%{$term}%")->orWhere('source_identifier', 'like', "%{$term}%")));
    }

    public function selectAllSources(): void
    {
        $this->selectedSources = array_map('strval', $this->sourceQuery()->limit(2000)->pluck('id')->all());
        $this->selectSourcePage = true;
    }

    /** Seçili kaynaklara toplu işlem: activate | deactivate | delete | restore | purge. */
    public function bulkSources(string $action): void
    {
        if (! $this->can() || $this->selectedSources === []) {
            return;
        }
        $ids = array_map('intval', $this->selectedSources);
        $ok = 0;
        $service = app(ScrapedLoadService::class);
        foreach (Scraper::withTrashed()->whereIn('id', $ids)->get() as $scraper) {
            $done = match ($action) {
                'activate' => ! $scraper->trashed() && ! $scraper->is_active && $scraper->update(['is_active' => true]),
                'deactivate' => ! $scraper->trashed() && $scraper->is_active && $scraper->update(['is_active' => false]),
                'delete' => ! $scraper->trashed() && $scraper->forceFill(['is_active' => false, 'messages_since_deleted' => 0])->save() && $scraper->delete(),
                'restore' => $scraper->trashed() && $scraper->restore() && $scraper->forceFill(['is_active' => false, 'messages_since_deleted' => 0])->save(),
                'purge' => (bool) ($service->purgeSource($scraper, auth()->id()) + 1),
                default => false,
            };
            if ($done) {
                $ok++;
                if ($action !== 'purge') {
                    ActivityLog::record('scraper.'.['activate' => 'toggled', 'deactivate' => 'toggled', 'delete' => 'deleted', 'restore' => 'restored'][$action], "Kaynak {$scraper->name}: toplu ".['activate' => 'aktif edildi', 'deactivate' => 'pasife alındı', 'delete' => 'silindi', 'restore' => 'geri alındı'][$action], auth()->id());
                }
            }
        }
        $labels = ['activate' => 'aktif edildi', 'deactivate' => 'pasife alındı', 'delete' => 'silindi (Silinenler listesinde)', 'restore' => 'geri alındı; onay bekliyor', 'purge' => 'adaylarıyla birlikte kalıcı silindi'];
        $this->selectedSources = [];
        $this->selectSourcePage = false;
        session()->flash('success_message', "{$ok} kaynak ".($labels[$action] ?? 'işlendi').'.');
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
            'price_unit' => $load->price_unit === 'per_ton' ? 'per_ton' : 'total',
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
            'edit.price_unit' => ['required', 'in:total,per_ton'],
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

        $before = $load->only(['pickup_location', 'delivery_location', 'pickup_province_code', 'delivery_province_code', 'vehicle_type', 'goods_type']);
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
            'price_unit' => $this->edit['price'] !== '' ? $this->edit['price_unit'] : null,
            'status' => $load->status === 'parsed_partial' ? 'parsed_success' : $load->status,
            'parse_metadata' => array_merge((array) ($load->parse_metadata ?? []), ['admin_edited' => true, 'warnings' => []]),
        ])->save();
        ActivityLog::record('scraped_load.edited', "Dış kaynak ilanı #{$load->id} düzenlendi", auth()->id(), $load);
        app(\App\Services\LearningService::class)->onEdited($load, $before, auth()->id()); // düzeltmeden öğren (konum sözlüğü, araç/yük önerisi)
        $this->cancelEdit();
        session()->flash('success_message', "#{$load->id} güncellendi.");
    }

    // ---- Sözlük ve öğrenme ----

    /** @var array<string, string> Yeni sözlük girdisi formu */
    public array $lex = ['kind' => 'location', 'term' => '', 'canonical' => ''];

    /** @var array<int, string> Öneri kimliği → yöneticinin yazdığı sözcük */
    public array $suggestTerm = [];

    public function addLexicon(): void
    {
        abort_unless(auth()->user()?->can('manage scrapers'), 403);
        $this->validate([
            'lex.kind' => ['required', Rule::in(array_keys(AiLexicon::KINDS))],
            'lex.term' => ['required', 'string', 'min:2', 'max:120'],
            'lex.canonical' => ['nullable', 'string', 'max:160'],
        ], [], ['lex.term' => 'sözcük', 'lex.canonical' => 'karşılık']);
        $kind = $this->lex['kind'];
        $canonical = trim((string) $this->lex['canonical']);
        $error = match (true) {
            $kind === 'location' && ($canonical === '' || TurkishLocations::resolve($canonical) === null) => 'Karşılık katalogda bulunan bir il ya da "İl İlçe" olmalı (ör. "Ankara" ya da "Kocaeli Gebze").',
            $kind === 'vehicle' && ! VehicleTypes::isValid($canonical) => 'Araç tipi seçin.',
            $kind === 'goods' && GoodsCatalog::label($canonical) === null => 'Yük kategorisi seçin.',
            default => null,
        };
        if ($error !== null) {
            $this->addError('lex.canonical', $error);

            return;
        }
        if ($kind === 'location') {
            $r = TurkishLocations::resolve($canonical);
            $canonical = $r['province'].(($r['district'] ?? null) && $r['district'] !== 'Merkez' ? ' '.$r['district'] : '');
        }
        AiLexicon::updateOrCreate(
            ['kind' => $kind, 'term' => Lexicon::normalize((string) $this->lex['term'])],
            ['canonical' => in_array($kind, ['location', 'vehicle', 'goods'], true) ? $canonical : null, 'status' => 'active', 'source' => 'admin', 'created_by' => auth()->id()]
        );
        Lexicon::flush();
        $this->lex = ['kind' => $kind, 'term' => '', 'canonical' => ''];
        session()->flash('success_message', 'Sözlüğe eklendi; bundan sonraki mesajlarda kural doğrudan uygular.');
    }

    public function deleteLexicon(int $id): void
    {
        abort_unless(auth()->user()?->can('manage scrapers'), 403);
        AiLexicon::whereKey($id)->delete();
        Lexicon::flush();
    }

    /** Öneriyi yöneticinin yazdığı sözcükle etkinleştirir. */
    public function acceptSuggestion(int $id): void
    {
        abort_unless(auth()->user()?->can('manage scrapers'), 403);
        $row = AiLexicon::query()->where('status', 'suggested')->find($id);
        $term = Lexicon::normalize((string) ($this->suggestTerm[$id] ?? ''));
        if (! $row || mb_strlen($term) < 2) {
            $this->addError('suggestTerm.'.$id, 'Öğretilecek sözcüğü yazın (mesajda geçtiği gibi).');

            return;
        }
        AiLexicon::query()->where('kind', $row->kind)->where('term', $term)->where('id', '!=', $row->id)->delete();
        $row->update(['term' => $term, 'status' => 'active', 'created_by' => auth()->id()]);
        Lexicon::flush();
        unset($this->suggestTerm[$id]);
    }

    /** Kural ile dene: yapay zeka çağırmadan mesajın hangi parçalara ayrıldığını ve kuralın ne çözdüğünü gösterir. */
    public string $tryText = '';

    /** @var list<array<string, mixed>> */
    public array $tryResult = [];

    public function tryRules(): void
    {
        abort_unless(auth()->user()?->can('manage scrapers'), 403);
        $raw = trim($this->tryText);
        $this->tryResult = [];
        if ($raw === '') {
            return;
        }
        $parser = app(AiParserService::class);
        $standardizer = app(\App\Services\LoadStandardizer::class);
        $classifier = app(LocalClassifier::class);
        $gate = match (true) {
            ! \App\Services\LoadIntakeService::hasPhone($raw) => 'telefon yok → elenir',
            Lexicon::isNotLoad($raw) => 'sözlük "ilan değil" ifadesi → elenir',
            ! \App\Services\LoadIntakeService::looksLikeLoad($raw) => 'lojistik işaret yok → kural kipinde elenir (yapay zeka kipinde yapay zekaya sorulur)',
            default => 'ön elemeyi geçti',
        };
        $local = $classifier->score($raw);
        $this->tryResult[] = ['gate' => $gate, 'local' => $local];
        foreach (\App\Services\LoadIntakeService::splitSegments($raw) as $segment) {
            $parsed = $parser->parseCheap($segment['text']);
            $std = ($parsed['success'] ?? false) ? $standardizer->standardize($segment['text'], $parsed) : null;
            $this->tryResult[] = [
                'text' => $segment['text'], 'phones' => $segment['phones'],
                'success' => (bool) ($parsed['success'] ?? false),
                'pickup' => $std['pickup_location'] ?? ($parsed['pickup_location'] ?? null), 'pickup_ok' => (bool) ($std['pickup_province_code'] ?? false),
                'delivery' => $std['delivery_location'] ?? ($parsed['delivery_location'] ?? null), 'delivery_ok' => (bool) ($std['delivery_province_code'] ?? false),
                'vehicle' => $std['vehicle_type'] ?? null, 'vehicle_source' => $std['vehicle_type_source'] ?? null,
                'goods' => $std['goods_type'] ?? null, 'weight' => $std['weight'] ?? null, 'price' => $std['price'] ?? null, 'price_unit' => $std['price_unit'] ?? null,
                'needs_ai' => $parser->shouldUseAi($parsed) || ! $std || ! $std['pickup_province_code'] || ! $std['delivery_province_code'],
            ];
        }
    }

    public function rebuildClassifier(): void
    {
        abort_unless(auth()->user()?->can('manage scrapers'), 403);
        $r = app(LocalClassifier::class)->rebuild();
        session()->flash('success_message', "Yeniden öğrenildi: {$r['load']} ilan, {$r['other']} ilan-değil örneği.");
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
            $scraper->forceFill(['is_active' => false, 'messages_since_deleted' => 0])->save();
            $scraper->delete();
            ActivityLog::record('scraper.deleted', "Kaynak silindi: {$scraper->name}", auth()->id());
            session()->flash('success_message', 'Kaynak "Silinenler" listesine taşındı; gelen mesajları yok sayılır ve sayılır. Oradan geri alabilir ya da kalıcı silebilirsiniz.');
        }
    }

    public function restoreSource(int $scraperId): void
    {
        if (! $this->can()) {
            return;
        }
        if ($scraper = Scraper::onlyTrashed()->find($scraperId)) {
            $scraper->restore();
            $scraper->forceFill(['is_active' => false, 'messages_since_deleted' => 0])->save();
            ActivityLog::record('scraper.restored', "Kaynak geri alındı: {$scraper->name}", auth()->id());
            session()->flash('success_message', "\"{$scraper->name}\" geri alındı; onay bekliyor. Aktif edince mesajlar işlenir.");
        }
    }

    public function purgeSource(int $scraperId): void
    {
        if (! $this->can()) {
            return;
        }
        if ($scraper = Scraper::withTrashed()->find($scraperId)) {
            $n = app(ScrapedLoadService::class)->purgeSource($scraper, auth()->id());
            session()->flash('success_message', "\"{$scraper->name}\" ve ondan gelen {$n} aday kalıcı silindi; grup hiç okunmamış gibi.");
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
            'tokenBody' => '', 'webhookUrl' => url('/api/v1/webhook/notification'), 'pingUrl' => '', 'phoneParams' => [], 'sourceCounts' => ['active' => 0, 'pending' => 0, 'deleted' => 0], 'sourceTotal' => 0,
            'lexicon' => collect(), 'suggestions' => collect(), 'classifier' => null,
        ];

        if ($this->activeTab === 'lexicon') {
            $data['lexicon'] = AiLexicon::query()->where('status', 'active')->orderBy('kind')->orderByDesc('hits')->orderBy('term')->get();
            $data['suggestions'] = AiLexicon::query()->where('status', 'suggested')->latest('id')->limit(50)->get();
            $data['classifier'] = app(LocalClassifier::class)->stats() + ['templates' => \App\Models\AiTemplate::query()->count(), 'template_uses' => (int) \App\Models\AiTemplate::query()->sum('uses')];
        }

        if ($this->activeTab === 'events') {
            $q = IntakeEvent::query()->with('scrapedLoad')->latest('id');
            if ($this->search !== '') {
                $term = '%'.trim($this->search).'%';
                $q->where(fn ($w) => $w->where('excerpt', 'like', $term)->orWhere('source_name', 'like', $term)->orWhere('title', 'like', $term));
            }
            $data['events'] = $q->paginate(30);
        } elseif ($this->activeTab === 'sources') {
            $data['sourceCounts'] = [
                'active' => Scraper::query()->where('is_active', true)->count(),
                'pending' => Scraper::query()->where('is_active', false)->count(),
                'deleted' => Scraper::onlyTrashed()->count(),
            ];
            $data['sources'] = $this->sourceQuery()->withCount('scrapedLoads')->paginate(20);
            $data['sourceTotal'] = $this->sourceQuery()->count();
            $data['tokenBody'] = ScrapedLoadService::phoneRequestBody();
            $data['pingUrl'] = ScrapedLoadService::pingUrl();
            $data['phoneParams'] = ScrapedLoadService::phoneRequestParams();
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

<div @if(! $editingId && $selected === [] && $selectedSources === []) wire:poll.5s @endif class="max-w-7xl mx-auto space-y-5">
    @php
        $input = 'w-full px-3 py-2 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/60 dark:border-neutral-700/40 text-neutral-900 dark:text-white text-xs rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500';
        $tabs = ['queue' => 'İnceleme kuyruğu', 'published' => 'Yayında', 'rejected' => 'Reddedilenler', 'events' => 'Canlı akış', 'sources' => 'Kaynaklar ve telefon', 'lexicon' => 'Sözlük ve öğrenme'];
        $tabCount = ['queue' => $stats['pending'], 'published' => $stats['published'], 'rejected' => $stats['rejected'], 'events' => $stats['received'], 'sources' => null, 'lexicon' => null];
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
                <button type="button" wire:click="selectAllMatching" class="text-brand-600 font-semibold hover:underline mr-2">Filtreye uyan tümünü seç</button>
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
                                    <div class="text-[11px] text-neutral-400">{{ $load->scraper?->name ?? 'Kaynak silinmiş' }}<br>{{ $load->created_at?->format('d.m.Y H:i') }}<br>{{ $load->masked_phone }}@if(($extra = $load->extraPhones()) !== [])<br><span class="text-brand-500 font-semibold" title="{{ implode(', ', array_map(fn ($p) => \App\Support\Phone::format($p), $extra)) }}">+{{ count($extra) }} numara</span>@endif@if($load->meta('message_part'))<br><span title="Aynı mesajdan ayrılan ilanlardan biri">mesajın {{ (int) $load->meta('message_part')['index'] + 1 }}/{{ $load->meta('message_part')['count'] }}. ilanı</span>@endif</div>
                                </td>
                                <td class="p-3">
                                    <div class="font-bold text-neutral-900 dark:text-white text-sm">
                                        @if($load->isUrgent())<span class="badge bg-red-500 text-white mr-1">ACİL</span>@endif
                                        {{ $load->pickup_location ?: '—' }} <span class="text-neutral-400">→</span> {{ $load->delivery_location ?: '—' }}
                                    </div>
                                    <div class="mt-1 flex flex-wrap items-center gap-1">
                                        @if($load->vehicle_type)
                                            <span class="badge {{ in_array($load->vehicle_type_source, ['keyword', 'ai', 'admin'], true) ? 'bg-neutral-900 dark:bg-white text-white dark:text-neutral-900' : 'bg-neutral-100 dark:bg-neutral-800 text-neutral-700 dark:text-neutral-200' }}" title="Kaynak: {{ ['keyword' => 'araç adı', 'hint' => 'kasa ipucu', 'weight' => 'tonaj', 'pallet' => 'palet adedi', 'volume' => 'hacim', 'goods' => 'yük türünden çıkarım', 'ai' => 'yapay zeka', 'admin' => 'yönetici', 'template' => 'doğrulanmış kalıp'][$load->vehicle_type_source] ?? $load->vehicle_type_source }}">{{ $load->vehicleLabel() }}</span>
                                        @else
                                            <span class="badge bg-amber-500/10 text-amber-600">Araç tipi yok</span>
                                        @endif
                                        @if($load->goods_type)<span class="badge bg-sky-500/10 text-sky-700 dark:text-sky-300">{{ $load->goods_type }}</span>@endif
                                        @if($load->weightLabel())<span class="badge bg-neutral-100 dark:bg-neutral-800 text-neutral-700 dark:text-neutral-200">{{ $load->weightLabel() }}</span>@endif
                                        @foreach($load->traitLabels() as $trait)<span class="badge bg-violet-500/10 text-violet-700 dark:text-violet-300">{{ $trait }}</span>@endforeach
                                        @if($load->meta('pickup_note'))<span class="badge bg-neutral-100 dark:bg-neutral-800 text-neutral-500">Yükleme: {{ $load->meta('pickup_note') }}</span>@endif
                                    </div>
                                    <div class="mt-1 text-[11px] text-neutral-400">
                                        Çözümleme: <span class="font-semibold {{ str_contains((string) $load->parsed_by_llm, 'claude') || str_contains((string) $load->parsed_by_llm, 'gemini') ? 'text-violet-600' : '' }}">{{ ['regex_verified' => 'kural', 'regex' => 'kural', 'template' => 'şablon (doğrulanmış kalıp)'][$load->parsed_by_llm] ?? ($load->parsed_by_llm ?: '—') }}</span>
                                        @if($load->parse_confidence !== null) · güven %{{ number_format((float) $load->parse_confidence * 100, 0) }}@endif
                                        @if(! empty($aiMeta['notes'])) · <span title="{{ $aiMeta['notes'] }}">{{ \Illuminate\Support\Str::limit($aiMeta['notes'], 60) }}</span>@endif
                                        @if($load->ai_status === 'pending') · <span class="text-amber-600">yapay zeka sırada</span>@endif
                                        @if(is_numeric($load->meta('local_confidence'))) · <span title="Yerel öğrenen sınıflandırıcı (dış servisten bağımsız)">yerel %{{ number_format((float) $load->meta('local_confidence') * 100, 0) }}</span>@endif
                                    </div>
                                    @if(in_array('pickup_unresolved', $warnings, true) || in_array('delivery_unresolved', $warnings, true))
                                        <div class="mt-1 text-[11px] text-red-600 font-semibold">İl çözülemedi; yayın öncesi düzenleyin ya da yapay zeka ile çözümleyin.</div>
                                    @endif
                                </td>
                                <td class="p-3 whitespace-nowrap font-semibold">{{ $load->priceLabel() ?? '—' }}</td>
                                <td class="p-3 max-w-xs text-neutral-500"><span title="{{ $load->raw_message }}">{{ \Illuminate\Support\Str::limit($load->raw_message, 140) }}</span></td>
                                <td class="p-3">
                                    <span class="px-2 py-1 rounded-full text-[10px] font-semibold {{ $load->visibility === 'public' ? 'bg-emerald-500/10 text-emerald-600' : ($load->status === 'rejected' ? 'bg-red-500/10 text-red-600' : 'bg-amber-500/10 text-amber-600') }}">{{ $load->visibility === 'public' ? 'Yayında' : ($load->status === 'rejected' ? 'Reddedildi' : 'Onay bekliyor') }}</span>
                                    @if($load->meta('duplicate_of'))<div class="text-[11px] text-neutral-400 mt-1">Tekrar: #{{ $load->meta('duplicate_of') }} yayında</div>@endif
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
                                            <div><label class="form-label">Fiyat (₺)</label><div class="flex gap-2"><input type="number" step="0.01" wire:model="edit.price" class="{{ $input }}" min="0"><select wire:model="edit.price_unit" class="{{ $input }} w-32 shrink-0"><option value="total">Toplam</option><option value="per_ton">Ton başına</option></select></div>@error('edit.price')<div class="text-red-500 mt-1">{{ $message }}</div>@enderror</div>
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
                                <td class="p-3"><span class="badge {{ $tone }}">{{ $e->statusLabel() }}</span>@if($e->reason)<div class="text-[11px] text-neutral-400 mt-1">{{ ['phone_missing' => 'telefon numarası yok', 'no_logistics_signal' => 'rota/tonaj/araç/yük işareti yok', 'route_missing' => 'kalkış-varış çözülemedi', 'regex_required_fields_missing' => 'kalkış-varış çözülemedi', 'ai_not_load' => 'yapay zeka: yük ilanı değil', 'template_not_load' => 'şablon: gönderenin bu kalıbı ilan değil', 'lexicon_not_load' => 'sözlük: "ilan değil" ifadesi', 'foreign_script' => 'yabancı alfabe (Rusça/Arapça)', 'not_load_pattern' => 'ilan değil: boş araç / şoför ilanı / reklam / satılık', 'template_not_load' => 'şablon: gönderenin bu kalıbı ilan değil', 'local_not_load' => 'yerel sınıflandırıcı: ilan değil', 'token_missing' => 'istekte anahtar yok', 'token_mismatch' => 'anahtar sunucudakiyle uyuşmuyor', 'summary_notification' => 'özet bildirim (N yeni mesaj)', 'empty' => 'başlık ya da metin boş', 'not_whatsapp' => 'WhatsApp dışı uygulama'][$e->reason] ?? $e->reason }}</div>@endif</td>
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
            <h2 class="text-sm font-bold text-neutral-900 dark:text-white">Telefon bağlantısı (bildirim iletici)</h2>
            <p class="text-[11px] text-neutral-400">Aşağıdaki adres ve alanlar hangi telefondaki bildirim iletici uygulamasına yazılırsa o telefon sunucuya ilan iletmeye başlar; anahtar alanların içinde hazırdır, sunucu tarafında telefon başına ayar yoktur. Adım adım kurulum: <span class="font-mono">docs/BILDIRIM_ILETICI_KURULUM.md</span>.</p>

            <div class="flex flex-wrap items-center gap-3 p-3 rounded-2xl bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/60 dark:border-neutral-700/40">
                <span class="font-bold text-neutral-900 dark:text-white">Sorun giderme</span>
                <span class="text-[11px] text-neutral-500">Telefon istek atmıyor gibi görünüyorsa: sınama bağlantısını <strong>telefonun tarayıcısında</strong> açın; Canlı akışa "Bağlantı sınaması" düşerse ağ ve anahtar tamamdır, sorun telefondaki makro tetikleyicisindedir (bildirim erişimi, sessize alınmış grup, kendi yazdığınız mesaj bildirim üretmez).</span>
                <button type="button" @click="copy(@js($pingUrl), 'ping')" class="btn-secondary py-2 px-3 text-xs" x-text="copied === 'ping' ? 'Kopyalandı' : 'Sınama bağlantısını kopyala'"></button>
                <a href="{{ $pingUrl }}" target="_blank" rel="noopener" class="text-brand-600 font-semibold hover:underline text-xs">Buradan aç</a>
            </div>

            <details class="text-xs" open>
                <summary class="cursor-pointer font-semibold text-neutral-700 dark:text-neutral-200">Kurulum için adres ve alanlar</summary>
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
                    <button type="button" wire:click="regenerateToken" wire:confirm="Anahtar yenilenince telefonlardaki eski alanlar çalışmaz; yeni anahtarı telefonlara yeniden girmeniz gerekir. Devam edilsin mi?" class="text-red-600 font-semibold hover:underline">Anahtarı yenile</button>
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
                <div class="p-4 border-b border-neutral-100 dark:border-neutral-800/50 flex flex-wrap items-center gap-2">
                    @foreach(['active' => 'Aktif', 'pending' => 'Onay bekleyen', 'deleted' => 'Silinen'] as $stateKey => $stateLabel)
                        <button type="button" wire:click="$set('sourceState', '{{ $stateKey }}')" class="tab-pill {{ $sourceState === $stateKey ? 'tab-pill-active' : '' }}">{{ $stateLabel }} <span class="ml-1 inline-flex items-center justify-center min-w-[1.25rem] h-4 px-1 rounded-full text-[10px] {{ $sourceState === $stateKey ? 'bg-white/20' : ($stateKey === 'pending' && $sourceCounts['pending'] > 0 ? 'bg-amber-500 text-white' : 'bg-neutral-200/70 dark:bg-neutral-700') }}">{{ $sourceCounts[$stateKey] }}</span></button>
                    @endforeach
                    <input type="text" wire:model.live.debounce.400ms="sourceSearch" placeholder="Kaynak adı ara" class="{{ $input }} ml-auto w-full sm:w-56">
                </div>
                @if($selectedSources !== [])
                    <div class="p-3 border-b border-brand-500/30 bg-brand-500/5 flex flex-wrap items-center gap-2 text-xs">
                        <span class="font-bold text-neutral-900 dark:text-white mr-2">{{ count($selectedSources) }} kaynak seçili</span>
                        @if(count($selectedSources) < $sourceTotal)<button type="button" wire:click="selectAllSources" class="text-brand-600 font-semibold hover:underline mr-2">Listedeki tümünü seç ({{ $sourceTotal }})</button>@endif
                        @if($sourceState === 'pending')<button type="button" wire:click="bulkSources('activate')" class="btn-primary py-1.5 px-3 text-xs">Aktif et</button>@endif
                        @if($sourceState === 'active')<button type="button" wire:click="bulkSources('deactivate')" wire:confirm="Seçili kaynaklar pasife alınacak; mesajları işlenmez." class="btn-secondary py-1.5 px-3 text-xs">Pasife al</button>@endif
                        @if($sourceState !== 'deleted')<button type="button" wire:click="bulkSources('delete')" wire:confirm="Seçili kaynaklar Silinenler listesine taşınacak." class="btn-secondary py-1.5 px-3 text-xs">Sil</button>@endif
                        @if($sourceState === 'deleted')<button type="button" wire:click="bulkSources('restore')" class="btn-secondary py-1.5 px-3 text-xs">Geri al</button>@endif
                        @if($sourceState === 'deleted')<button type="button" wire:click="bulkSources('purge')" wire:confirm="Seçili kaynaklar ve onlardan gelen TÜM adaylar kalıcı silinecek; geri alınamaz." class="py-1.5 px-3 text-xs font-semibold text-red-600 hover:bg-red-500/10 rounded-xl">Kalıcı sil</button>@endif
                        <button type="button" wire:click="$set('selectedSources', [])" class="ml-auto text-neutral-400 hover:text-neutral-600">Seçimi temizle</button>
                    </div>
                @endif
                @if($sourceState === 'deleted')
                    <div class="p-4 border-b border-neutral-100 dark:border-neutral-800/50">
                        <h2 class="text-sm font-bold text-neutral-900 dark:text-white">Silinen kaynaklar</h2>
                        <p class="text-[11px] text-neutral-400">Bu gruplardan gelen mesajlar yok sayılır ama sayılır. Mesaj atmaya devam eden grubu <strong>Geri al</strong> ile onaya alabilir; <strong>Kalıcı sil</strong> ile grubu ve ondan gelen tüm adayları hiç okunmamış gibi silebilirsiniz.</p>
                    </div>
                    <div class="responsive-scroll">
                        <table class="w-full text-left text-xs">
                            <thead><tr class="border-b border-neutral-100 dark:border-neutral-800/50 text-[11px] text-neutral-400"><th class="p-4 w-8"><input type="checkbox" wire:model.live="selectSourcePage" class="rounded" title="Sayfadakilerin tümünü seç"></th><th class="p-4">Kaynak</th><th class="p-4">Silinme</th><th class="p-4">Silindikten sonra gelen</th><th class="p-4">Aday</th><th class="p-4"></th></tr></thead>
                            <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/40">
                                @forelse($sources as $source)
                                    <tr wire:key="src-{{ $source->id }}" class="align-top {{ in_array((string) $source->id, $selectedSources, true) ? 'bg-brand-500/5' : '' }}">
                                        <td class="p-4"><input type="checkbox" wire:model.live="selectedSources" value="{{ $source->id }}" class="rounded"></td>
                                        <td class="p-4"><div class="font-bold">{{ $source->name }}</div><div class="text-[11px] text-neutral-400 font-mono">{{ $source->source_identifier }}</div></td>
                                        <td class="p-4 whitespace-nowrap text-neutral-500">{{ $source->deleted_at?->diffForHumans() }}</td>
                                        <td class="p-4">
                                            @if($source->messages_since_deleted > 0)
                                                <span class="badge bg-amber-500/10 text-amber-600">{{ $source->messages_since_deleted }} mesaj</span>
                                                <span class="text-[11px] text-neutral-400">son: {{ $source->last_message_at?->diffForHumans() }}</span>
                                            @else
                                                <span class="text-neutral-400">yok</span>
                                            @endif
                                        </td>
                                        <td class="p-4">{{ $source->scraped_loads_count }}</td>
                                        <td class="p-4 whitespace-nowrap space-x-2">
                                            <button type="button" wire:click="restoreSource({{ $source->id }})" class="text-emerald-600 font-semibold">Geri al</button>
                                            <button type="button" wire:click="purgeSource({{ $source->id }})" wire:confirm="Kaynak ve ondan gelen {{ $source->scraped_loads_count }} aday kalıcı silinecek; geri alınamaz. Devam edilsin mi?" class="text-red-600 font-semibold">Kalıcı sil</button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="p-10 text-center text-neutral-500">Silinmiş kaynak yok.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="responsive-scroll">
                        <table class="w-full text-left text-xs">
                            <thead><tr class="border-b border-neutral-100 dark:border-neutral-800/50 text-[11px] text-neutral-400"><th class="p-4 w-8"><input type="checkbox" wire:model.live="selectSourcePage" class="rounded" title="Sayfadakilerin tümünü seç"></th><th class="p-4">Kaynak</th><th class="p-4">Aday</th><th class="p-4">Son mesaj</th><th class="p-4">Durum</th><th class="p-4"></th></tr></thead>
                            <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/40">
                                @forelse($sources as $source)
                                    <tr wire:key="src-{{ $source->id }}" class="align-top {{ in_array((string) $source->id, $selectedSources, true) ? 'bg-brand-500/5' : (! $source->is_active ? 'bg-amber-500/5' : '') }}">
                                        <td class="p-4"><input type="checkbox" wire:model.live="selectedSources" value="{{ $source->id }}" class="rounded"></td>
                                        <td class="p-4"><div class="font-bold">{{ $source->name }}</div><div class="text-[11px] text-neutral-400">{{ ['whatsapp' => 'WhatsApp servis', 'notification' => 'Bildirim iletici', 'telegram' => 'Telegram', 'web' => 'Web'][$source->type] ?? $source->type }} · <span class="font-mono">{{ $source->source_identifier }}</span></div></td>
                                        <td class="p-4">{{ $source->scraped_loads_count }}</td>
                                        <td class="p-4 whitespace-nowrap text-neutral-500">{{ $source->last_message_at ? $source->last_message_at->diffForHumans() : ($source->last_success_at ? \Illuminate\Support\Carbon::parse($source->last_success_at)->diffForHumans() : 'Henüz yok') }}</td>
                                        <td class="p-4"><span class="px-2 py-1 rounded-full text-[10px] font-semibold {{ $source->is_active ? 'bg-emerald-500/10 text-emerald-600' : 'bg-amber-500/10 text-amber-600' }}">{{ $source->is_active ? 'Aktif' : 'Onay bekliyor' }}</span></td>
                                        <td class="p-4 whitespace-nowrap space-x-2">
                                            <button type="button" wire:click="toggleSource({{ $source->id }})" class="{{ $source->is_active ? 'text-neutral-500' : 'text-emerald-600' }} font-semibold">{{ $source->is_active ? 'Pasife al' : 'Aktif et' }}</button>
                                            <button type="button" wire:click="deleteSource({{ $source->id }})" wire:confirm="Kaynak silinecek. Devam edilsin mi?" class="text-red-500 font-semibold">Sil</button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="p-10 text-center text-neutral-500">{{ $sourceState === 'active' ? 'Aktif kaynak yok. Onay bekleyenlerden "Aktif et" ile açın.' : 'Onay bekleyen kaynak yok. Telefondan yeni bir gruptan ilk mesaj gelince burada belirir.' }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @endif
                <div class="p-4 border-t border-neutral-100 dark:border-neutral-800/50 text-xs">{{ $sources->links() }}</div>
            </div>
        </div>
    @endif
    @if($activeTab === 'lexicon')
        @php $kinds = \App\Models\AiLexicon::KINDS; @endphp
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="apple-glass rounded-3xl p-6 space-y-3 text-xs">
                <h2 class="text-sm font-bold text-neutral-900 dark:text-white">Yerel sınıflandırıcı</h2>
                <p class="text-[11px] text-neutral-400">Dış servise bağlı değildir. Siz "Yayınla" dedikçe ilan örneği, "Reddet" dedikçe ilan-değil örneği öğrenir; yapay zeka doğrulamalı otomatik onaylar da ilan örneğidir. Her sınıfta en az {{ \App\Services\LocalClassifier::MIN_DOCS }} örnek olunca karar vermeye başlar; dış yapay zeka kotası dolduğunda otomatik onayı bu karar sürdürür. "İlan değil" diye eleme en az {{ \App\Services\LocalClassifier::MIN_OTHER_DOCS_FOR_FILTER }} ret örneğinden sonra başlar. Grup dışa aktarımlarından toplu öğretmek için sunucuda <code>php artisan intake:analyze /klasör --learn</code> (belgede anlatılır).</p>
                @if($classifier)
                    <div class="grid grid-cols-3 gap-2 text-center">
                        <div class="p-3 rounded-2xl bg-neutral-50 dark:bg-neutral-900"><div class="text-lg font-bold text-emerald-600">{{ $classifier['docs_load'] }}</div><div class="text-[10px] text-neutral-400">ilan örneği</div></div>
                        <div class="p-3 rounded-2xl bg-neutral-50 dark:bg-neutral-900"><div class="text-lg font-bold text-rose-600">{{ $classifier['docs_other'] }}</div><div class="text-[10px] text-neutral-400">ilan-değil örneği</div></div>
                        <div class="p-3 rounded-2xl bg-neutral-50 dark:bg-neutral-900"><div class="text-lg font-bold text-neutral-900 dark:text-white">{{ $classifier['tokens'] }}</div><div class="text-[10px] text-neutral-400">öğrenilen sözcük</div></div>
                    </div>
                    <div class="badge {{ $classifier['ready'] ? 'bg-emerald-500/10 text-emerald-600' : 'bg-amber-500/10 text-amber-600' }}">{{ $classifier['ready'] ? 'Karar veriyor' : 'Henüz yeterli örnek yok' }}</div>
                    <p class="text-[11px] text-neutral-400 pt-1"><strong class="text-neutral-700 dark:text-neutral-200">Şablon hafızası:</strong> {{ $classifier['templates'] }} doğrulanmış gönderen kalıbı, {{ $classifier['template_uses'] }} kez yapay zekasız çözüm. Aynı numaradan aynı kalıpla gelen ilan bir kez doğrulanınca (yapay zeka ya da sizin onayınız) sonrakiler kalıptan okunur; reddettiğiniz bir kalıp silinir.</p>
                @endif
                <button type="button" wire:click="rebuildClassifier" wire:confirm="Sayaçlar sıfırlanıp yayınlanan / reddedilen tüm adaylardan yeniden öğrenilecek." class="btn-secondary py-1.5 px-3 text-xs">Geçmişten yeniden öğren</button>
            </div>

            <div class="apple-glass rounded-3xl p-6 space-y-3 text-xs lg:col-span-2">
                <h2 class="text-sm font-bold text-neutral-900 dark:text-white">Jargon sözlüğüne ekle</h2>
                <p class="text-[11px] text-neutral-400">Tırcıların dilini siz öğretirsiniz: "ostim" → Ankara Ostim, "tenteli mega" → TIR, "salça" → Gıda, "satılık" → ilan değil. Girilen sözcük sonraki her mesajda kural tarafından anında uygulanır; yapay zekaya gerek kalmaz.</p>
                <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-end">
                    <div><label class="form-label">Tür</label>
                        <select wire:model.live="lex.kind" class="{{ $input }}">@foreach($kinds as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                    <div><label class="form-label">Sözcük / ifade (mesajda geçtiği gibi)</label><input type="text" wire:model="lex.term" class="{{ $input }}" placeholder="ör. ostim, tenteli mega, satılık">@error('lex.term')<p class="text-rose-500 text-[11px] mt-1">{{ $message }}</p>@enderror</div>
                    <div><label class="form-label">Karşılığı</label>
                        @if($lex['kind'] === 'vehicle')
                            <select wire:model="lex.canonical" class="{{ $input }}"><option value="">Seçin</option>@foreach(\App\Support\VehicleTypes::labels() as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                        @elseif($lex['kind'] === 'goods')
                            <select wire:model="lex.canonical" class="{{ $input }}"><option value="">Seçin</option>@foreach(\App\Support\GoodsCatalog::labels() as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                        @elseif($lex['kind'] === 'location')
                            <input type="text" wire:model="lex.canonical" class="{{ $input }}" placeholder="İl ya da İl İlçe (ör. Kocaeli Gebze)">
                        @else
                            <input type="text" class="{{ $input }}" value="—" disabled>
                        @endif
                        @error('lex.canonical')<p class="text-rose-500 text-[11px] mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div><button type="button" wire:click="addLexicon" class="btn-primary py-2 px-4 text-xs w-full">Ekle</button></div>
                </div>

                @if($suggestions->isNotEmpty())
                    <h3 class="text-xs font-bold text-neutral-900 dark:text-white pt-2">Düzeltmelerinizden öneriler ({{ $suggestions->count() }})</h3>
                    <p class="text-[11px] text-neutral-400">Bir adayın araç tipini ya da yükünü düzelttiniz; mesajdaki hangi sözcüğün bu karşılığı taşıdığını yazarsanız sistem bir daha sormaz.</p>
                    <div class="space-y-2">
                        @foreach($suggestions as $sg)
                            <div wire:key="sg-{{ $sg->id }}" class="p-3 rounded-2xl border border-amber-200/60 dark:border-amber-900/40 bg-amber-50/40 dark:bg-amber-950/10 space-y-2">
                                <div class="text-[11px]"><span class="badge bg-amber-500/10 text-amber-700">{{ $kinds[$sg->kind] ?? $sg->kind }}</span> → <strong>{{ $sg->kind === 'vehicle' ? \App\Support\VehicleTypes::label($sg->canonical) : ($sg->kind === 'goods' ? (\App\Support\GoodsCatalog::label($sg->canonical) ?? $sg->canonical) : $sg->canonical) }}</strong></div>
                                <div class="text-[11px] text-neutral-500 dark:text-neutral-400">{{ \Illuminate\Support\Str::limit($sg->sample, 200) }}</div>
                                <div class="flex flex-wrap gap-2 items-center">
                                    <input type="text" wire:model="suggestTerm.{{ $sg->id }}" class="{{ $input }} sm:max-w-xs" placeholder="mesajdaki sözcük">
                                    <button type="button" wire:click="acceptSuggestion({{ $sg->id }})" class="btn-primary py-1.5 px-3 text-xs">Öğret</button>
                                    <button type="button" wire:click="deleteLexicon({{ $sg->id }})" class="text-neutral-500 text-[11px] hover:underline">Yok say</button>
                                </div>
                                @error('suggestTerm.'.$sg->id)<p class="text-rose-500 text-[11px]">{{ $message }}</p>@enderror
                            </div>
                        @endforeach
                    </div>
                @endif

                <h3 class="text-xs font-bold text-neutral-900 dark:text-white pt-2">Kural ile dene (yapay zeka çağrılmaz)</h3>
                <p class="text-[11px] text-neutral-400">Gruptan bir mesajı yapıştırın: ön elemeden geçip geçmediğini, kaç ilana ayrıldığını, kuralın il/araç/yük/tonaj/fiyatı çözüp çözmediğini ve yapay zekaya gerek kalıp kalmadığını görürsünüz. Çözülmeyen sözcüğü yukarıdan sözlüğe ekleyip yeniden deneyin.</p>
                <textarea wire:model="tryText" rows="4" class="{{ $input }}" placeholder="Örn: Ostimden Gebze OSB'ye 24 ton mega tenteli 0532 123 45 67"></textarea>
                <button type="button" wire:click="tryRules" class="btn-secondary py-1.5 px-3 text-xs">Dene</button>
                @if($tryResult !== [])
                    @php $head = $tryResult[0]; @endphp
                    <div class="text-[11px]"><span class="badge {{ str_contains($head['gate'], 'elenir') ? 'bg-rose-500/10 text-rose-600' : 'bg-emerald-500/10 text-emerald-600' }}">{{ $head['gate'] }}</span>@if($head['local'] !== null) <span class="text-neutral-500">· yerel sınıflandırıcı %{{ number_format($head['local'] * 100, 0) }}</span>@else <span class="text-neutral-400">· yerel sınıflandırıcı henüz karar vermiyor</span>@endif</div>
                    @foreach(array_slice($tryResult, 1) as $i => $seg)
                        <div class="p-3 rounded-2xl bg-neutral-50 dark:bg-neutral-900 text-[11px] space-y-1">
                            <div class="font-semibold text-neutral-900 dark:text-white">İlan {{ $i + 1 }} · {{ implode(', ', array_map(fn ($p) => \App\Support\Phone::format($p), $seg['phones'])) ?: 'numara yok' }}</div>
                            <div class="text-neutral-500">{{ \Illuminate\Support\Str::limit(str_replace("\n", ' ⏎ ', $seg['text']), 220) }}</div>
                            <div class="flex flex-wrap gap-1.5">
                                <span class="badge {{ $seg['pickup_ok'] ? 'bg-emerald-500/10 text-emerald-600' : 'bg-rose-500/10 text-rose-600' }}">Kalkış: {{ $seg['pickup'] ?: '—' }}</span>
                                <span class="badge {{ $seg['delivery_ok'] ? 'bg-emerald-500/10 text-emerald-600' : 'bg-rose-500/10 text-rose-600' }}">Varış: {{ $seg['delivery'] ?: '—' }}</span>
                                <span class="badge {{ $seg['vehicle'] ? 'bg-neutral-900 dark:bg-white text-white dark:text-neutral-900' : 'bg-amber-500/10 text-amber-600' }}">Araç: {{ $seg['vehicle'] ? \App\Support\VehicleTypes::label($seg['vehicle']).' ('.$seg['vehicle_source'].')' : 'yok' }}</span>
                                @if($seg['goods'])<span class="badge bg-sky-500/10 text-sky-700">{{ $seg['goods'] }}</span>@endif
                                @if($seg['weight'])<span class="badge bg-neutral-100 dark:bg-neutral-800 text-neutral-700 dark:text-neutral-200">{{ number_format($seg['weight'], 0, ',', '.') }} kg</span>@endif
                                @if($seg['price'])<span class="badge bg-neutral-100 dark:bg-neutral-800 text-neutral-700 dark:text-neutral-200">{{ number_format($seg['price'], 0, ',', '.') }} ₺{{ ($seg['price_unit'] ?? null) === 'per_ton' ? '/ton' : '' }}</span>@endif
                                <span class="badge {{ $seg['needs_ai'] ? 'bg-violet-500/10 text-violet-700' : 'bg-emerald-500/10 text-emerald-600' }}">{{ $seg['needs_ai'] ? 'yapay zeka gerekir' : 'kural yeterli, yapay zeka gerekmez' }}</span>
                            </div>
                        </div>
                    @endforeach
                @endif

                <h3 class="text-xs font-bold text-neutral-900 dark:text-white pt-2">Sözlük ({{ $lexicon->count() }})</h3>
                @if($lexicon->isEmpty())
                    <p class="text-[11px] text-neutral-400">Henüz girdi yok. Kuyrukta bir adayın ilini düzelttiğinizde konum kısaltmaları kendiliğinden buraya düşer.</p>
                @else
                    <div class="responsive-scroll"><table class="w-full text-left text-xs">
                        <thead><tr class="text-[11px] text-neutral-400 border-b border-neutral-100 dark:border-neutral-800/50"><th class="p-2">Tür</th><th class="p-2">Sözcük</th><th class="p-2">Karşılığı</th><th class="p-2">Kaynak</th><th class="p-2">Kullanım</th><th class="p-2"></th></tr></thead>
                        <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/40">
                        @foreach($lexicon as $row)
                            <tr wire:key="lex-{{ $row->id }}">
                                <td class="p-2 text-neutral-500">{{ $kinds[$row->kind] ?? $row->kind }}</td>
                                <td class="p-2 font-semibold text-neutral-900 dark:text-white">{{ $row->term }}</td>
                                <td class="p-2">{{ $row->kind === 'vehicle' ? \App\Support\VehicleTypes::label($row->canonical) : ($row->kind === 'goods' ? (\App\Support\GoodsCatalog::label($row->canonical) ?? $row->canonical) : ($row->canonical ?: '—')) }}</td>
                                <td class="p-2 text-neutral-500">{{ $row->source === 'learned' ? 'öğrenildi' : 'yönetici' }}</td>
                                <td class="p-2 text-neutral-500">{{ $row->hits }}</td>
                                <td class="p-2 text-right"><button type="button" wire:click="deleteLexicon({{ $row->id }})" wire:confirm="Sözlükten silinsin mi?" class="text-red-600 text-[11px] font-semibold hover:underline">Sil</button></td>
                            </tr>
                        @endforeach
                        </tbody></table></div>
                @endif
            </div>
        </div>
    @endif
</div>
