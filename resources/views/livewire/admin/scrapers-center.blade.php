<?php

use App\Models\ActivityLog;
use App\Models\AiLexicon;
use App\Models\IntakeEvent;
use App\Models\ScrapedLoad;
use App\Models\Scraper;
use App\Services\AiParserService;
use App\Services\LocalClassifier;
use App\Services\ScrapedLoadService;
use App\Support\BodyTypes;
use App\Support\GoodsCatalog;
use App\Support\Lexicon;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
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

    /** Kasa / dorse tipi (BodyTypes anahtarı; boş: hepsi). */
    public string $body = '';

    /** Yük kategorisi (GoodsCatalog etiketi; boş: hepsi). */
    public string $goods = '';

    /** Güzergah süzgeci: il kodu (string) + ilçe adı. "Ankara'dan Gebze'ye" = nereden 06, nereye 41 / Gebze (Osman, 2026-10-08). */
    #[Url(as: 'nereden')]
    public string $fromProvince = '';

    public string $fromDistrict = '';

    #[Url(as: 'nereye')]
    public string $toProvince = '';

    public string $toDistrict = '';

    /** Hazır zaman penceresi (gün); özel aralık girilince dikkate alınmaz. */
    public string $period = '7';

    /** Özel zaman aralığı (saat dahil, "Y-m-d\TH:i"): adayın geliş zamanı bu aralıkta. */
    public string $from = '';

    public string $to = '';

    public string $minPrice = '';

    public string $maxPrice = '';

    public string $minWeight = '';

    public string $maxWeight = '';

    /** Durum çipleri (birden çok seçilir, hepsi birden sağlanır): FLAGS anahtarları. */
    public array $flags = [];

    public string $sort = 'newest';

    /** Durum çipleri: anahtar → etiket. Sıra ekrandaki sıradır. */
    public const FLAGS = [
        'auto_ok' => 'Otomatik onaya uygun', 'auto_blocked' => 'Otomatik onay engelli', 'unresolved' => 'İl çözülemedi', 'no_phone' => 'Telefon yok',
        'priced' => 'Fiyatlı', 'unpriced' => 'Fiyatsız', 'per_ton' => 'Ton başı fiyat', 'urgent' => 'Acil', 'incomplete' => 'Eksik bilgili',
        'duplicates' => 'Birden fazla kaynakta', 'reshared' => 'Yeniden paylaşılan', 'similar' => 'Benzer ilan (farklı numara)', 'series' => 'Seri ilan',
        'multi_vehicle' => 'Birden çok araç', 'ai' => 'Yapay zeka ile çözülen', 'ai_pending' => 'Yapay zeka bekleyen', 'conflict' => 'Kural / yapay zeka çelişen',
        'driver_taken' => 'Şoför aldı',
    ];

    public const SORTS = ['newest' => 'En yeni gelen', 'seen' => 'En son görülen', 'oldest' => 'En eski', 'price_desc' => 'Fiyat (yüksekten)', 'weight_desc' => 'Tonaj (yüksekten)', 'route' => 'Güzergah (A→Z)'];

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
        if ($name === 'fromProvince') {
            $this->fromDistrict = '';
        }
        if ($name === 'toProvince') {
            $this->toDistrict = '';
        }
        if (in_array($name, ['activeTab', 'search', 'sourceId', 'vehicle', 'body', 'goods', 'fromProvince', 'fromDistrict', 'toProvince', 'toDistrict', 'period', 'from', 'to', 'minPrice', 'maxPrice', 'minWeight', 'maxWeight', 'flags', 'sort', 'sourceState', 'sourceSearch'], true) || str_starts_with($name, 'flags.')) {
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

    /**
     * Arama: sözcükler ayrı ayrı aranır ve hepsi bulunmalıdır ("adana tekirdağ" → ham mesajda "ADANADAN ➡️ TEKİRDAĞ" da eşleşir; eskiden
     * bütün ifade tek parça aranıyor ve bulunamıyordu, Osman 2026-10-08). "#123" kayıt numarası; 7+ rakam telefon (boşluk/tire fark etmez).
     */
    private function applySearch($q): void
    {
        $term = trim($this->search);
        if ($term === '') {
            return;
        }
        if (preg_match('/^#?\d{1,9}$/', $term) && str_starts_with($term, '#')) {
            $q->where('id', (int) ltrim($term, '#'));

            return;
        }
        $digits = preg_replace('/\D+/', '', $term) ?? '';
        if (strlen($digits) >= 7 && strlen($digits) >= mb_strlen($term) - 6) {
            $q->whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(raw_message, ' ', ''), '-', ''), '(', ''), ')', '') LIKE ?", ['%'.$digits.'%']);

            return;
        }
        foreach (preg_split('/\s+/u', $term) ?: [] as $word) {
            if (mb_strlen($word) < 2) {
                continue;
            }
            $like = '%'.$word.'%';
            $q->where(fn ($w) => $w->where('raw_message', 'like', $like)->orWhere('pickup_location', 'like', $like)->orWhere('delivery_location', 'like', $like)->orWhere('goods_type', 'like', $like));
        }
    }

    /**
     * Arama varken üç sekmede kaç kayıt eşleştiği (Osman "kaynaklarda bulamadım": kayıt başka sekmede, örneğin tekrar diye reddedilmiş olabilir).
     *
     * @return array<string,int>
     */
    private function searchCounts(): array
    {
        if (trim($this->search) === '') {
            return [];
        }
        $base = fn () => ScrapedLoad::query()->when($this->period !== 'all', fn ($q) => $q->where('created_at', '>=', now()->subDays((int) $this->period)));
        $counts = [];
        foreach (['queue' => fn ($q) => $q->where('visibility', 'private')->where('status', '!=', 'rejected'), 'published' => fn ($q) => $q->where('visibility', 'public'), 'rejected' => fn ($q) => $q->where('status', 'rejected')] as $tab => $scope) {
            $q = $scope($base());
            $this->applySearch($q);
            $counts[$tab] = $q->count();
        }

        return $counts;
    }

    /** Özel zaman aralığının sınırları (geçersiz metin yok sayılır). @return array{0:?\Illuminate\Support\Carbon,1:?\Illuminate\Support\Carbon} */
    private function customRange(): array
    {
        $parse = function (string $v): ?\Illuminate\Support\Carbon {
            try {
                return trim($v) === '' ? null : \Illuminate\Support\Carbon::parse($v, config('app.timezone'));
            } catch (\Throwable) {
                return null;
            }
        };

        return [$parse($this->from), $parse($this->to)];
    }

    /** Zaman penceresi: özel aralık varsa o, yoksa hazır gün sayısı; "Tümü" sınırsız. */
    private function applyTime($q): void
    {
        [$from, $to] = $this->customRange();
        if ($from || $to) {
            $from && $q->where('created_at', '>=', $from);
            $to && $q->where('created_at', '<=', $to);

            return;
        }
        if ($this->period !== 'all') {
            $q->where('created_at', '>=', now()->subDays(max(1, (int) $this->period)));
        }
    }

    /** Güzergah süzgeci: il kodu kolona, ilçe adı ilçe kolonuna (eski kayıtlarda yalnız etikette olabilir: etiket de aranır). */
    private function applyRoute($q): void
    {
        foreach ([['fromProvince', 'fromDistrict', 'pickup'], ['toProvince', 'toDistrict', 'delivery']] as [$prov, $dist, $col]) {
            if ($this->{$prov} === '') {
                continue;
            }
            $q->where($col.'_province_code', (int) $this->{$prov});
            if ($this->{$dist} !== '') {
                $d = $this->{$dist};
                $q->where(fn ($w) => $w->where($col.'_district', $d)->orWhere($col.'_location', 'like', '% '.$d.'%'));
            }
        }
    }

    /** Durum çipleri; her seçili çip ayrı koşuldur (hepsi birden). */
    private function applyFlags($q): void
    {
        foreach ($this->flags as $flag) {
            match ($flag) {
                'unresolved' => $q->where(fn ($w) => $w->whereNull('pickup_province_code')->orWhereNull('delivery_province_code')),
                'no_phone' => $q->whereNull('encrypted_sender_phone')->whereNull('sender_phone'),
                'priced' => $q->where('price', '>', 0),
                'unpriced' => $q->where(fn ($w) => $w->whereNull('price')->orWhere('price', '<=', 0)),
                'per_ton' => $q->where('price', '>', 0)->where('price_unit', 'per_ton'),
                'ai' => $q->where('ai_status', 'done'),
                'ai_pending' => $q->where('ai_status', 'pending'),
                'conflict' => $q->whereNotNull('parse_metadata->ai_conflict'),
                'urgent' => $q->where('parse_metadata->urgent', true),
                'duplicates' => $q->where('duplicate_count', '>', 1),
                'reshared' => $q->whereNotNull('published_at')->whereColumn('last_seen_at', '>', 'published_at'),
                'similar' => $q->where(fn ($w) => $w->whereNotNull('parse_metadata->similar_to')->orWhereNotNull('parse_metadata->similar_with')),
                'series' => $q->whereNotNull('parse_metadata->series'),
                'multi_vehicle' => $q->where('vehicle_count', '>', 1),
                'incomplete' => $q->where('is_incomplete', true),
                'driver_taken' => $q->has('trips'),
                'auto_ok', 'auto_blocked' => $q->whereIn('id', $this->autoApprovalIds($flag === 'auto_ok')),
                default => null,
            };
        }
    }

    /** Aktif sekme ve filtrelere göre aday sorgusu (sayfalama öncesi). */
    private function currentQuery()
    {
        $q = ScrapedLoad::query()->with('scraper')->withCount('trips');
        match ($this->activeTab) {
            'published' => $q->where('visibility', 'public'),
            'rejected' => $q->where('status', 'rejected'),
            default => $q->where('visibility', 'private')->where('status', '!=', 'rejected'),
        };
        $this->applySearch($q);
        if ($this->sourceId !== '') {
            $q->where('scraper_id', (int) $this->sourceId);
        }
        if ($this->vehicle !== '') {
            $this->vehicle === 'none' ? $q->whereNull('vehicle_type')->where('vehicle_any', false) : ($this->vehicle === 'any' ? $q->where('vehicle_any', true) : $q->where('vehicle_type', $this->vehicle));
        }
        if ($this->body !== '') {
            $this->body === 'none' ? $q->whereNull('body_types') : $q->where('body_types', 'like', '%"'.$this->body.'"%');
        }
        if ($this->goods !== '') {
            $this->goods === 'none' ? $q->whereNull('goods_type') : $q->where('goods_type', $this->goods);
        }
        $this->applyRoute($q);
        $this->applyTime($q);
        // Fiyat: ton başı yazılan fiyat tonajla toplam fiyata çevrilir (şoför listesiyle aynı kural)
        $priceExpr = \App\Services\LoadFilterService::EFFECTIVE_PRICE_SQL;
        // Tam sayı bağlanır: ondalık bağlama SQLite'ta metin sayılır ve CASE ifadesiyle karşılaştırma boş döner
        if (is_numeric($this->minPrice)) {
            $q->whereRaw('('.$priceExpr.') >= ?', [(int) round((float) $this->minPrice)]);
        }
        if (is_numeric($this->maxPrice)) {
            $q->whereRaw('('.$priceExpr.') <= ?', [(int) round((float) $this->maxPrice)]);
        }
        if (is_numeric($this->minWeight)) {
            $q->where('weight', '>=', (int) round((float) $this->minWeight * 1000));
        }
        if (is_numeric($this->maxWeight)) {
            $q->where('weight', '<=', (int) round((float) $this->maxWeight * 1000));
        }
        $this->applyFlags($q);
        match ($this->sort) {
            'oldest' => $q->oldest('id'),
            'seen' => $q->orderByDesc('last_seen_at')->orderByDesc('id'),
            'price_desc' => $q->orderByRaw('('.$priceExpr.') DESC')->orderByDesc('id'),
            'weight_desc' => $q->orderByDesc('weight')->orderByDesc('id'),
            'route' => $q->orderBy('pickup_location')->orderBy('delivery_location')->orderByDesc('id'),
            default => $q->latest('id'),
        };

        return $q;
    }

    /** Durum çipi aç/kapa. */
    public function toggleFlag(string $flag): void
    {
        if (! array_key_exists($flag, self::FLAGS)) {
            return;
        }
        $this->flags = in_array($flag, $this->flags, true) ? array_values(array_diff($this->flags, [$flag])) : [...$this->flags, $flag];
        $this->updated('flags');
    }

    public function setPeriod(string $period): void
    {
        $this->period = in_array($period, ['1', '7', '30', 'all'], true) ? $period : '7';
        $this->from = $this->to = '';
        $this->updated('period');
    }

    /** Nereden ⇄ nereye. */
    public function swapRoute(): void
    {
        [$this->fromProvince, $this->toProvince] = [$this->toProvince, $this->fromProvince];
        [$this->fromDistrict, $this->toDistrict] = [$this->toDistrict, $this->fromDistrict];
        $this->updated('fromDistrict');
    }

    /** Seçili filtreler rozet olarak (× ile tek tek kalkar). @return list<array{key:string,label:string}> */
    private function filterChips(): array
    {
        $chips = [];
        $place = function (string $prov, string $dist): string {
            $name = TurkishLocations::province((int) $prov)['name'] ?? $prov;

            return $dist !== '' ? $name.' '.$dist : $name;
        };
        if (trim($this->search) !== '') {
            $chips[] = ['key' => 'search', 'label' => 'Arama: '.trim($this->search)];
        }
        if ($this->fromProvince !== '') {
            $chips[] = ['key' => 'from', 'label' => 'Nereden: '.$place($this->fromProvince, $this->fromDistrict)];
        }
        if ($this->toProvince !== '') {
            $chips[] = ['key' => 'to', 'label' => 'Nereye: '.$place($this->toProvince, $this->toDistrict)];
        }
        [$from, $to] = $this->customRange();
        if ($from || $to) {
            $chips[] = ['key' => 'time', 'label' => 'Zaman: '.($from ? $from->format('d.m H:i') : '…').' – '.($to ? $to->format('d.m H:i') : '…')];
        }
        if ($this->sourceId !== '') {
            $chips[] = ['key' => 'sourceId', 'label' => 'Kaynak: '.(Scraper::query()->whereKey((int) $this->sourceId)->value('name') ?? '#'.$this->sourceId)];
        }
        if ($this->vehicle !== '') {
            $chips[] = ['key' => 'vehicle', 'label' => 'Araç: '.(['none' => 'tipi yok', 'any' => 'fark etmez'][$this->vehicle] ?? (VehicleTypes::labels()[$this->vehicle] ?? $this->vehicle))];
        }
        if ($this->body !== '') {
            $chips[] = ['key' => 'body', 'label' => 'Kasa: '.($this->body === 'none' ? 'yazmıyor' : (BodyTypes::labels()[$this->body] ?? $this->body))];
        }
        if ($this->goods !== '') {
            $chips[] = ['key' => 'goods', 'label' => 'Yük: '.($this->goods === 'none' ? 'yazmıyor' : $this->goods)];
        }
        if (is_numeric($this->minPrice) || is_numeric($this->maxPrice)) {
            $chips[] = ['key' => 'price', 'label' => 'Fiyat: '.(is_numeric($this->minPrice) ? number_format((float) $this->minPrice, 0, ',', '.') : '…').' – '.(is_numeric($this->maxPrice) ? number_format((float) $this->maxPrice, 0, ',', '.') : '…').' ₺'];
        }
        if (is_numeric($this->minWeight) || is_numeric($this->maxWeight)) {
            $chips[] = ['key' => 'weight', 'label' => 'Tonaj: '.(is_numeric($this->minWeight) ? $this->minWeight : '…').' – '.(is_numeric($this->maxWeight) ? $this->maxWeight : '…').' t'];
        }
        foreach ($this->flags as $flag) {
            if (isset(self::FLAGS[$flag])) {
                $chips[] = ['key' => 'flag:'.$flag, 'label' => self::FLAGS[$flag]];
            }
        }

        return $chips;
    }

    public function removeFilter(string $key): void
    {
        match (true) {
            $key === 'search' => $this->search = '',
            $key === 'from' => [$this->fromProvince, $this->fromDistrict] = ['', ''],
            $key === 'to' => [$this->toProvince, $this->toDistrict] = ['', ''],
            $key === 'time' => [$this->from, $this->to] = ['', ''],
            $key === 'price' => [$this->minPrice, $this->maxPrice] = ['', ''],
            $key === 'weight' => [$this->minWeight, $this->maxWeight] = ['', ''],
            in_array($key, ['sourceId', 'vehicle', 'body', 'goods'], true) => $this->{$key} = '',
            str_starts_with($key, 'flag:') => $this->flags = array_values(array_diff($this->flags, [substr($key, 5)])),
            default => null,
        };
        $this->updated('flags');
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'sourceId', 'vehicle', 'body', 'goods', 'fromProvince', 'fromDistrict', 'toProvince', 'toDistrict', 'from', 'to', 'minPrice', 'maxPrice', 'minWeight', 'maxWeight', 'flags');
        $this->updated('flags');
    }

    /**
     * "Otomatik onay: uygun" / "engelli" süzgeci: kuyruktaki adaylar (en yeni 2.000) kural kural denetlenir; sonuç aynı
     * istek içinde önbelleğe alınır. Engel nedeni satırda görünür.
     *
     * @return list<int>
     */
    private function autoApprovalIds(bool $eligible): array
    {
        static $cache = [];
        $key = $eligible ? 'ok' : 'blocked';
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $service = app(ScrapedLoadService::class);
        $ids = [];
        ScrapedLoad::query()->with('scraper')->where('visibility', 'private')->where('status', '!=', 'rejected')
            ->latest('id')->limit(2000)->get()
            ->each(function (ScrapedLoad $load) use (&$ids, $eligible, $service): void {
                if (($service->autoApprovalBlocker($load) === null) === $eligible) {
                    $ids[] = $load->id;
                }
            });

        return $cache[$key] = $ids;
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

    /** @var array<int|string, string> Satır başına seçilen ret gerekçesi (ScrapedLoadService::REJECT_REASONS); seçilmediyse "other". */
    public array $rejectReasons = [];

    /** Kalkış bekleyen adaylar için yazılan kalkış metni (aday kimliği → "İl İlçe"). */
    public array $teachPickup = [];

    /** Kalkış bekleyen aday: gönderen hafızasına kalkış yazılır, mesaj yeniden okunur, her varış satırı ayrı ilan olur. */
    public function teachPickup(int $loadId): void
    {
        $load = ScrapedLoad::query()->find($loadId);
        if (! $load || ! $this->can()) {
            return;
        }
        $text = trim((string) ($this->teachPickup[$loadId] ?? ''));
        if ($text === '') {
            session()->flash('error_message', 'Kalkış yerini yazın ("İl" ya da "İl İlçe").');

            return;
        }
        try {
            $n = app(ScrapedLoadService::class)->teachPickup($load, $text, auth()->id());
            unset($this->teachPickup[$loadId]);
            session()->flash('success_message', "Gönderenin kalkışı öğretildi; mesaj yeniden okundu, {$n} ilan adayı açıldı. Aynı gönderenin sonraki listeleri kendiliğinden çözülür.");
        } catch (\Throwable $e) {
            session()->flash('error_message', $e->getMessage());
        }
    }

    /** Toplu "Reddet" için gerekçe; varsayılan "other" öğrenmez. */
    public string $bulkRejectReason = 'other';

    /**
     * Gerekçe her zaman açıkça verilir: yalnız "İlan değil" sınıflandırıcıya olumsuz örnek olur. Varsayılan "Diğer" hiçbir şey
     * öğretmez; eski tek düğme her reddi "ilan değil" diye öğretip sınıflandırıcıyı ilan metnine karşı zehirliyordu (denetim Y4).
     */
    private function rejectReasonFor(int $loadId): string
    {
        $reason = (string) ($this->rejectReasons[$loadId] ?? $this->rejectReasons[(string) $loadId] ?? 'other');

        return array_key_exists($reason, ScrapedLoadService::REJECT_REASONS) ? $reason : 'other';
    }

    public function reject(int $loadId): void
    {
        if (! $this->can()) {
            return;
        }
        if ($load = ScrapedLoad::query()->find($loadId)) {
            $reason = $this->rejectReasonFor($loadId);
            app(ScrapedLoadService::class)->reject($load, auth()->id(), $reason);
            unset($this->rejectReasons[$loadId], $this->rejectReasons[(string) $loadId]);
            session()->flash('success_message', "#{$load->id} reddedildi (".ScrapedLoadService::REJECT_REASONS[$reason].').');
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
                    'approve' => $service->approve($load, auth()->id(), bulk: true), // toplu yayın: tek tek incelenmemiş ilandan konum/kalıp öğrenilmez
                    'reject' => $service->reject($load, auth()->id(), array_key_exists($this->bulkRejectReason, ScrapedLoadService::REJECT_REASONS) ? $this->bulkRejectReason : 'other'),
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
            'vehicle_type' => $load->vehicle_any ? 'any' : ($load->vehicle_type ?? ''),
            'body_types' => BodyTypes::clean($load->body_types ?? []),
            'load_kind' => $load->load_kind ?? '',
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
            'edit.vehicle_type' => ['nullable', Rule::in(array_merge(['any'], array_keys(VehicleTypes::TYPES)))],
            'edit.body_types' => ['array'],
            'edit.body_types.*' => [Rule::in(array_keys(BodyTypes::TYPES))],
            'edit.load_kind' => ['nullable', Rule::in(array_keys(BodyTypes::LOAD_KINDS))],
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
            'vehicle_type' => ! in_array($this->edit['vehicle_type'], ['', 'any'], true) ? $this->edit['vehicle_type'] : null,
            'vehicle_type_source' => $this->edit['vehicle_type'] !== '' ? 'admin' : null,
            'vehicle_any' => $this->edit['vehicle_type'] === 'any',
            'is_incomplete' => $load->is_incomplete && $this->edit['vehicle_type'] === '',
            'completed_by' => $load->is_incomplete && $this->edit['vehicle_type'] !== '' ? 'admin' : $load->completed_by,
            'body_types' => ($bodies = BodyTypes::clean($this->edit['body_types'] ?? [])) !== [] ? $bodies : null,
            'body_type_source' => $bodies !== [] ? 'admin' : null,
            'load_kind' => ($this->edit['load_kind'] ?? '') !== '' ? $this->edit['load_kind'] : null,
            'goods_type' => trim((string) $this->edit['goods_type']) ?: null,
            'weight' => $this->edit['weight'] !== '' ? (int) $this->edit['weight'] : null,
            'price' => $this->edit['price'] !== '' ? round((float) $this->edit['price'], 2) : null,
            'price_unit' => $this->edit['price'] !== '' ? $this->edit['price_unit'] : null,
            'status' => $load->status === 'parsed_partial' ? 'parsed_success' : $load->status,
            'route_key' => \App\Services\LoadIntakeService::routeKey($load->plainPhone(), $pickup['label'], $delivery['label'], (bool) $load->meta('series')),
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
            'lex.term' => ['required', 'string', in_array($this->lex['kind'] ?? '', ['not_load', 'load_signal'], true) ? 'min:4' : 'min:2', 'max:120'], // kısa ifade her mesajda geçer
            'lex.canonical' => ['nullable', 'string', 'max:160'],
        ], [], ['lex.term' => 'sözcük', 'lex.canonical' => 'karşılık']);
        $kind = $this->lex['kind'];
        $canonical = trim((string) $this->lex['canonical']);
        $error = match (true) {
            $kind === 'location' && ($canonical === '' || TurkishLocations::resolve($canonical) === null) => 'Karşılık katalogda bulunan bir il ya da "İl İlçe" olmalı (ör. "Ankara" ya da "Kocaeli Gebze").',
            $kind === 'vehicle' && ! VehicleTypes::isValid($canonical) => 'Araç tipi seçin.',
            $kind === 'goods' && GoodsCatalog::label($canonical) === null => 'Yük kategorisi seçin.',
            $kind === 'body' && BodyTypes::clean(explode(',', $canonical)) === [] => 'En az bir kasa tipi seçin.',
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
            ['canonical' => in_array($kind, ['location', 'vehicle', 'goods', 'body'], true) ? ($kind === 'body' ? implode(',', BodyTypes::clean(explode(',', $canonical))) : $canonical) : null, 'status' => 'active', 'source' => 'admin', 'created_by' => auth()->id()]
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

    /** Öneriyi onaylar: yapay zekadan gelen öneride sözcük hazırdır (tek dokunuş); düzeltmeden gelen öneride yönetici sözcüğü yazar. */
    public function acceptSuggestion(int $id): void
    {
        abort_unless(auth()->user()?->can('manage scrapers'), 403);
        $row = AiLexicon::query()->where('status', 'suggested')->find($id);
        $typed = trim((string) ($this->suggestTerm[$id] ?? ''));
        if (! $row || ! app(\App\Services\RuleFeedbackService::class)->approve($row, $typed !== '' ? $typed : null, auth()->id())) {
            $this->addError('suggestTerm.'.$id, 'Öğretilecek sözcüğü yazın (mesajda geçtiği gibi).');

            return;
        }
        unset($this->suggestTerm[$id]);
        session()->flash('success_message', 'Sözlüğe eklendi; bu yazım bundan sonra yapay zekaya sorulmadan kuralla çözülür.');
    }

    /** "Yok say": öneri kalkar ve aynı yazım bir daha önerilmez. */
    public function ignoreSuggestion(int $id): void
    {
        abort_unless(auth()->user()?->can('manage scrapers'), 403);
        $row = AiLexicon::query()->where('status', 'suggested')->find($id);
        if ($row) {
            app(\App\Services\RuleFeedbackService::class)->ignore($row);
        }
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
                'body' => BodyTypes::summary($std['body_types'] ?? null), 'load_kind' => $std['load_kind'] ?? null, 'vehicle_count' => $std['vehicle_count'] ?? null, 'stops' => $std['delivery_stops'] ?? null,
                'goods' => $std['goods_type'] ?? null, 'weight' => $std['weight'] ?? null, 'price' => $std['price'] ?? null, 'price_unit' => $std['price_unit'] ?? null,
                'needs_ai' => $parser->shouldUseAi($parsed) || ! $std || ! $std['pickup_province_code'] || ! $std['delivery_province_code'],
            ];
        }
    }

    /** "Geçmişten yeniden öğren" onay metni; sunucuda birebir karşılaştırılır. */
    public string $rebuildConfirm = '';

    public const REBUILD_PHRASE = 'YENİDEN ÖĞREN';

    /**
     * Sınıflandırıcıyı sıfırlayıp tüm geçmişten yeniden öğretir. Tek tıkla geri alınamaz bir işlemdi (denetim Y19): yalnız süper
     * yönetici, onay metni yazılır, önceki sayaçlar işlem kaydına yazılır ki yanlışlıkla basılınca ne kaybolduğu görünsün.
     */
    public function rebuildClassifier(): void
    {
        abort_unless(auth()->user()?->can('manage scrapers'), 403);
        if (! auth()->user()->hasRole('super_admin')) {
            session()->flash('error_message', 'Geçmişten yeniden öğrenmeyi yalnız süper yönetici başlatabilir.');

            return;
        }
        $this->resetErrorBag('rebuildConfirm');
        if (trim($this->rebuildConfirm) !== self::REBUILD_PHRASE) {
            $this->addError('rebuildConfirm', 'Onaylamak için kutuya "'.self::REBUILD_PHRASE.'" yazın.');

            return;
        }
        $classifier = app(LocalClassifier::class);
        $before = $classifier->stats();
        $r = $classifier->rebuild();
        $this->rebuildConfirm = '';
        ActivityLog::record('classifier.rebuilt', "Sınıflandırıcı geçmişten yeniden öğrendi: {$r['load']} ilan, {$r['other']} ilan-değil", auth()->id(), null, ['before' => $before, 'after' => $classifier->stats(), 'rebuilt' => $r]);
        session()->flash('success_message', "Yeniden öğrenildi: {$r['load']} ilan, {$r['other']} ilan-değil örneği (önce: {$before['docs_load']} / {$before['docs_other']}).");
    }

    // ---- Kaynaklar ve telefon bağlantısı ----

    public function addSource(): void
    {
        if (! $this->can()) {
            return;
        }
        $this->validate([
            'sourceName' => 'required|string|min:3|max:120',
            'sourceType' => 'required|in:whatsapp,notification,facebook,telegram,web',
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

    /** Sayfada görünen verinin ucuz imzası; değişmediyse süreli yenileme hiçbir şey çizmez. */
    public ?string $pollSignature = null;

    private function currentSignature(): string
    {
        $parts = match (true) {
            $this->activeTab === 'events' => [IntakeEvent::query()->max('id')],
            $this->activeTab === 'sources' => [Scraper::withTrashed()->max('id'), Scraper::query()->where('is_active', false)->count(), Scraper::withTrashed()->max('last_message_at')],
            $this->activeTab === 'lexicon' => [AiLexicon::query()->max('id'), AiLexicon::query()->where('status', 'suggested')->count()],
            default => [
                ScrapedLoad::withTrashed()->max('id'),
                ScrapedLoad::query()->where('visibility', 'private')->where('status', '!=', 'rejected')->count(),
                ScrapedLoad::query()->where('visibility', 'public')->count(),
                ScrapedLoad::query()->where('status', 'rejected')->count(),
                ScrapedLoad::query()->where('ai_status', 'pending')->count(),
                ScrapedLoad::query()->max('published_at'),
            ],
        };

        return md5(implode('|', array_map(fn ($v) => (string) $v, $parts)));
    }

    /**
     * Hat karnesi (ucuz toplu sorgular; aday başına karar hesabı yok): bekleyenlerin kaba engel dağılımı, bugün yaşla reddedilen,
     * bugün yayınlananların açılış→yayın medyan süresi, sağlayıcı durumu (devre kesici / kota).
     *
     * @return array{pending_by:array<string,int>, age_rejected_today:int, median_minutes:?int, providers:array<string,array{state:string,until:?string}>}
     */
    private function scorecard(AiParserService $parser): array
    {
        $pending = ScrapedLoad::query()->where('visibility', 'private')->where('status', '!=', 'rejected');
        $aiPending = (clone $pending)->where('ai_status', 'pending')->count();
        $unresolved = (clone $pending)->where('ai_status', '!=', 'pending')->where(fn ($q) => $q->whereNull('pickup_province_code')->orWhereNull('delivery_province_code'))->count();
        $noPhone = (clone $pending)->where('ai_status', '!=', 'pending')->whereNotNull('pickup_province_code')->whereNotNull('delivery_province_code')->whereNull('encrypted_sender_phone')->whereNull('sender_phone')->count();
        $total = (clone $pending)->count();
        $ageRejected = ScrapedLoad::query()->where('status', 'rejected')->where('updated_at', '>=', now()->startOfDay())
            ->whereNotNull('parse_metadata->auto_rejected')->where('parse_metadata->auto_rejected->reason', 'like', 'kuyrukta%')->count();
        $minutes = ScrapedLoad::query()->where('published_at', '>=', now()->startOfDay())->limit(2000)->get(['created_at', 'published_at'])
            ->map(fn ($l) => $l->created_at && $l->published_at ? max(0, (int) round($l->created_at->diffInMinutes($l->published_at, true))) : null)->filter(fn ($m) => $m !== null)->sort()->values();
        $median = $minutes->isEmpty() ? null : (int) $minutes->get(intdiv($minutes->count(), 2));

        // Okuma katmanları karnesi (IntakeLayerReview): aşama, 7 günlük aday/elenen, gölge uyumu, yayın sonrası sonuçlar
        $layers = app(\App\Services\IntakeLayerReview::class)->report();

        return [
            'pending_by' => ['yapay zeka bekliyor' => $aiPending, 'il/rota çözülemedi' => $unresolved, 'telefon yok' => $noPhone, 'puan / elle kontrol' => max(0, $total - $aiPending - $unresolved - $noPhone)],
            'age_rejected_today' => $ageRejected,
            'median_minutes' => $median,
            'providers' => $parser->isConfigured() ? $parser->providerStatus() : [],
            'layers' => $layers,
        ];
    }

    /** wire:poll: veri değişmediyse ekran çizilmez (ağır sayaç ve karar hesapları atlanır). */
    public function tick(): void
    {
        $signature = $this->currentSignature();
        if ($this->pollSignature === $signature) {
            $this->skipRender();

            return;
        }
        $this->pollSignature = $signature;
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
        $this->pollSignature = $this->currentSignature();
        $service = app(ScrapedLoadService::class);
        $parser = app(AiParserService::class);
        $todayEvents = IntakeEvent::query()->where('created_at', '>=', now()->startOfDay());
        $schedulerAge = ScrapedLoadService::schedulerAgeSeconds();
        $queueAge = \App\Jobs\QueueHeartbeat::ageSeconds();

        $data = [
            'freeDelay' => $service->freeDelayMinutes(),
            'autoApprove' => Settings::bool('scraper_auto_approve'),
            'schedulerAge' => $schedulerAge,
            'schedulerOk' => $schedulerAge !== null && $schedulerAge < 180,
            'queueAge' => $queueAge,
            'queueOk' => \App\Jobs\QueueHeartbeat::alive(),
            'ai' => ['enabled' => $parser->isEnabled(), 'configured' => $parser->isConfigured(), 'mode' => AiParserService::MODES[$parser->mode()] ?? $parser->mode(), 'provider' => $parser->provider(), 'model' => $parser->model()],
            // Günün sayaçları ve hat karnesi 60 sn önbellekte: her 15 sn'lik yenilemede 20'ye yakın sayım sorgusu koşuyordu (sayfa yavaştı, Osman 2026-10-08)
            'stats' => Cache::remember('admin:scrapers:stats', 60, fn () => [
                'received' => (clone $todayEvents)->count(),
                'created' => (clone $todayEvents)->where('status', 'created')->count(),
                'filtered' => (clone $todayEvents)->whereIn('status', ['filtered', 'skipped'])->count(),
                // Günün ayrıntısı: aynı ilanın başka gruplardan gelen kopyaları (tekrar), bugün yeniden paylaşıldığı için tazelenen eski ilanlar
                // (yeni yayın sayılmaz), Facebook ekran paketleri ve en sık elenme nedenleri. "Gelen istek" çoğunlukla tekrardır (650+ grup aynı ilanı taşır).
                'duplicate' => (clone $todayEvents)->where('status', 'duplicate')->count(),
                'screens' => (clone $todayEvents)->where('status', 'screen')->count(),
                'refreshed' => ScrapedLoad::query()->where('visibility', 'public')->where('last_seen_at', '>=', now()->startOfDay())->where('published_at', '<', now()->startOfDay())->count(),
                'reasons' => (clone $todayEvents)->whereIn('status', ['filtered', 'skipped'])->whereNotNull('reason')->selectRaw('reason, COUNT(*) AS c')->groupBy('reason')->orderByDesc('c')->limit(5)->get()
                    ->map(fn ($r) => ['label' => IntakeEvent::reasonLabel($r->reason), 'count' => (int) $r->c])->all(),
                'published_facebook' => ScrapedLoad::withTrashed()->where('published_at', '>=', now()->startOfDay())->whereIn('scraper_id', fn ($q) => $q->select('id')->from('scrapers')->where('type', 'facebook'))->count(),
                'pending' => ScrapedLoad::query()->where('visibility', 'private')->where('status', '!=', 'rejected')->count(),
                'published' => ScrapedLoad::query()->where('visibility', 'public')->count(),
                'rejected' => ScrapedLoad::query()->where('status', 'rejected')->count(),
            ]),
            'rejectedRetention' => max(0, Settings::int('scraper_rejected_retention_days')),
            'scorecard' => Cache::remember('admin:scrapers:scorecard', 60, fn () => $this->scorecard($parser)),
            'searchCounts' => in_array($this->activeTab, ['queue', 'published', 'rejected'], true) ? $this->searchCounts() : [],
            'filterChips' => in_array($this->activeTab, ['queue', 'published', 'rejected'], true) ? $this->filterChips() : [],
            'flagOptions' => self::FLAGS, 'sortOptions' => self::SORTS,
            'fromDistricts' => $this->fromProvince !== '' ? TurkishLocations::districtsOf((int) $this->fromProvince) : [],
            'toDistricts' => $this->toProvince !== '' ? TurkishLocations::districtsOf((int) $this->toProvince) : [],
            'lifetime' => app(\App\Services\LoadStatsService::class)->summary(),
            'sourcesList' => Scraper::query()->orderBy('name')->get(['id', 'name']),
            'queue' => null, 'events' => null, 'sources' => null, 'blockers' => [], 'decisions' => [], 'incompleteEligible' => [],
            'tokenBody' => '', 'screenBody' => '', 'webhookUrl' => url('/api/v1/webhook/notification'), 'pingUrl' => '', 'phoneParams' => [], 'toplayici' => \App\Support\Toplayici::version(), 'sourceCounts' => ['active' => 0, 'pending' => 0, 'deleted' => 0], 'sourceTotal' => 0,
            'lexicon' => collect(), 'suggestions' => collect(), 'classifier' => null,
        ];

        if ($this->activeTab === 'lexicon') {
            $data['lexicon'] = AiLexicon::query()->where('status', 'active')->orderBy('kind')->orderByDesc('hits')->orderBy('term')->get();
            $data['suggestions'] = AiLexicon::query()->where('status', 'suggested')->orderByDesc('hits')->latest('id')->limit(50)->get();
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
            $data['screenBody'] = ScrapedLoadService::screenRequestBody();
            $data['pingUrl'] = ScrapedLoadService::pingUrl();
            $data['phoneParams'] = ScrapedLoadService::phoneRequestParams();
        } else {
            $data['queue'] = $this->currentQuery()->paginate(20);
            if ($this->activeTab === 'queue') {
                foreach ($data['queue'] as $load) {
                    $data['blockers'][$load->id] = $service->autoApprovalBlocker($load);
                    $data['decisions'][$load->id] = $service->decision($load);
                    $data['incompleteEligible'][$load->id] = $data['blockers'][$load->id] !== null && $service->incompleteEligible($load, $data['blockers'][$load->id]);
                }
            }
        }

        return $data;
    }
}; ?>

<div @if(! $editingId && $selected === [] && $selectedSources === [] && trim($search) === '') wire:poll.15s="tick" @endif class="max-w-7xl mx-auto space-y-5">
    @php
        $input = 'w-full px-3 py-2 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/60 dark:border-neutral-700/40 text-neutral-900 dark:text-white text-xs rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500';
        $tabs = ['queue' => 'İnceleme kuyruğu', 'published' => 'Yayında', 'rejected' => 'Reddedilenler', 'events' => 'Canlı akış', 'sources' => 'Kaynaklar ve telefon', 'lexicon' => 'Sözlük ve öğrenme'];
        $tabCount = ['queue' => $stats['pending'], 'published' => $stats['published'], 'rejected' => $stats['rejected'], 'events' => $stats['received'], 'sources' => null, 'lexicon' => null];
        $blockerLabels = ['durum' => 'Durum uygun değil', 'kaynak pasif' => 'Kaynak pasif', 'rota eksik' => 'Rota eksik', 'il çözülemedi' => 'İl çözülemedi', 'araç tipi yok' => 'Araç tipi yok', 'telefon yok' => 'Telefon yok', 'fiyat yok' => 'Fiyat yok', 'tonaj yok' => 'Tonaj yok', 'eski aday' => \App\Services\ScrapedLoadService::AUTO_APPROVE_MAX_AGE_DAYS.' günden eski; elle karar verin'];
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
            <span class="badge {{ $queueOk ? 'bg-emerald-500/10 text-emerald-600' : 'bg-amber-500/10 text-amber-600' }}" title="{{ $queueOk ? 'Telefon mesajları kuyrukta işleniyor; son nabız '.$queueAge.' sn önce' : 'Kuyruk işçisi nabız vermiyor; telefon mesajları istek içinde işleniyor (site yavaşlayabilir)' }}">Kuyruk: {{ $queueOk ? 'çalışıyor' : ($queueAge === null ? 'nabız yok' : 'durmuş ('.floor($queueAge / 60).' dk)') }}</span>
            <span class="badge {{ $autoApprove ? 'bg-emerald-500/10 text-emerald-600' : 'bg-neutral-100 dark:bg-neutral-800 text-neutral-500' }}">Otomatik onay: {{ $autoApprove ? 'açık' : 'kapalı' }}</span>
            <span class="badge {{ $ai['enabled'] && $ai['configured'] ? 'bg-violet-500/10 text-violet-600' : 'bg-neutral-100 dark:bg-neutral-800 text-neutral-500' }}" title="{{ $ai['provider'] }} · {{ $ai['model'] }}">Yapay zeka: {{ ! $ai['enabled'] ? 'kapalı' : ($ai['configured'] ? $ai['mode'] : 'anahtar yok') }}</span>

            <a href="{{ route('admin.settings') }}" class="text-brand-500 font-semibold hover:underline">Ayarlar →</a>
        </div>
    </div>

    {{-- Günün özeti tek şeritte: liste ve filtreler hemen altında başlar (Osman 2026-10-08: sayfa her açıdan işlevsel olsun, kartlar listeyi aşağı itmesin) --}}
    @php $stat = 'min-w-[6.5rem] flex-1 sm:flex-none'; $statLabel = 'text-[11px] text-neutral-400 block leading-tight'; $statValue = 'text-lg font-black tabular-nums leading-tight'; @endphp
    <div class="apple-glass rounded-2xl px-4 py-3 text-xs">
        <div class="flex flex-wrap gap-x-6 gap-y-3">
            <div class="{{ $stat }}"><span class="{{ $statLabel }}">Bugün gelen istek</span><span class="{{ $statValue }} text-neutral-900 dark:text-white">{{ number_format($stats['received'], 0, ',', '.') }}</span><span class="text-[10px] text-neutral-400 block">{{ $stats['created'] }} kuyruğa · {{ $stats['duplicate'] }} tekrar · {{ $stats['filtered'] }} elendi{{ $stats['screens'] > 0 ? ' · '.$stats['screens'].' Facebook paketi' : '' }}</span></div>
            <div class="{{ $stat }}"><span class="{{ $statLabel }}">Onay bekleyen</span><span class="{{ $statValue }} text-amber-600">{{ number_format($stats['pending'], 0, ',', '.') }}</span></div>
            <div class="{{ $stat }}"><span class="{{ $statLabel }}">Yayında</span><span class="{{ $statValue }} text-emerald-600">{{ number_format($stats['published'], 0, ',', '.') }}</span></div>
            <div class="{{ $stat }}"><span class="{{ $statLabel }}">Reddedilen</span><span class="{{ $statValue }} text-neutral-500">{{ number_format($stats['rejected'], 0, ',', '.') }}</span><span class="text-[10px] text-neutral-400 block">{{ $rejectedRetention }} gün sonra silinir</span></div>
            <div class="{{ $stat }}"><span class="{{ $statLabel }}">Bugün yayınlanan</span><span class="{{ $statValue }} text-brand-500">{{ number_format($lifetime['external_today'], 0, ',', '.') }}</span><span class="text-[10px] text-neutral-400 block">7 gün {{ number_format($lifetime['external_7d'], 0, ',', '.') }} · 30 gün {{ number_format($lifetime['external_30d'], 0, ',', '.') }} · Facebook {{ number_format($stats['published_facebook'], 0, ',', '.') }} · WhatsApp {{ number_format(max(0, $lifetime['external_today'] - $stats['published_facebook']), 0, ',', '.') }}{{ $stats['refreshed'] > 0 ? ' · ayrıca '.number_format($stats['refreshed'], 0, ',', '.').' eski ilan bugün yeniden paylaşıldı' : '' }}</span></div>
            <div class="{{ $stat }}"><span class="{{ $statLabel }}">Bugüne kadar</span><span class="{{ $statValue }} text-neutral-900 dark:text-white">{{ number_format($lifetime['external_total'], 0, ',', '.') }}</span><span class="text-[10px] text-neutral-400 block">günde {{ number_format($lifetime['external_daily_avg'], 1, ',', '.') }}{{ $lifetime['external_since'] ? ' · '.$lifetime['external_since'].'\'den beri' : '' }}</span></div>
            <div class="{{ $stat }}"><span class="{{ $statLabel }}">Açılış → yayın</span><span class="{{ $statValue }} text-neutral-900 dark:text-white">{{ $scorecard['median_minutes'] === null ? '—' : $scorecard['median_minutes'].' dk' }}</span><span class="text-[10px] text-neutral-400 block">bugünün medyanı</span></div>
        </div>
        <div class="mt-3 pt-3 border-t border-neutral-100 dark:border-neutral-800/60 text-[11px] text-neutral-400 space-y-1">
            <p>Bekleyen: @foreach(array_filter($scorecard['pending_by']) as $label => $n){{ ! $loop->first ? ' · ' : '' }}{{ $label }} <span class="font-semibold text-neutral-600 dark:text-neutral-300">{{ number_format($n, 0, ',', '.') }}</span>@endforeach{{ array_filter($scorecard['pending_by']) === [] ? 'yok' : '' }}
                · Bugün yaşla reddedilen <span class="font-semibold text-neutral-600 dark:text-neutral-300">{{ $scorecard['age_rejected_today'] }}</span>
                @if($scorecard['providers'] !== [])
                    · Yapay zeka: @foreach($scorecard['providers'] as $provider => $state){{ ! $loop->first ? ', ' : '' }}{{ \App\Services\AiParserService::PROVIDERS[$provider]['label'] ?? $provider }} <span class="font-semibold {{ $state['state'] === 'ok' ? 'text-emerald-600' : 'text-amber-600' }}">{{ match($state['state']) { 'ok' => 'çalışıyor', 'cooldown' => 'bekletiliyor'.($state['until'] ? ' ('.$state['until'].' kadar)' : ''), default => 'kota doldu'.($state['until'] ? ' ('.$state['until'].' sıfırlanır)' : '') } }}</span>@endforeach
                @endif
            </p>
            @if($stats['reasons'] !== [])
                <p>Bugün en sık elenme nedenleri: @foreach($stats['reasons'] as $i => $r){{ $i > 0 ? ' · ' : '' }}{{ $r['label'] }} <span class="font-semibold text-neutral-600 dark:text-neutral-300">{{ number_format($r['count'], 0, ',', '.') }}</span>@endforeach</p>
            @endif
            @if($lifetime['top_provinces'] !== [])
                <p>En çok ilan çıkan iller (30 gün): <span class="font-semibold text-neutral-600 dark:text-neutral-300">{{ implode(' · ', array_map(fn ($p) => $p['name'].' '.$p['count'], $lifetime['top_provinces'])) }}</span></p>
            @endif
            {{-- Okuma katmanları karnesi: kalıcı katmanlar sayım, yönetilen katmanlar aşama + gölge uyumu + yayın sonrası sonuç (aşamayı sistem yönetir) --}}
            @if(($scorecard['layers'] ?? []) !== [])
                <details>
                    <summary class="cursor-pointer select-none">Okuma katmanları (7 gün): @foreach(array_filter($scorecard['layers'], fn ($m) => $m['managed']) as $key => $m){{ ! $loop->first ? ' · ' : '' }}{{ $m['label'] }} <span class="font-semibold {{ $m['stage'] === 'active' ? 'text-emerald-600' : 'text-amber-600' }}">{{ $m['stage_label'] }}</span>@endforeach</summary>
            <div class="mt-1 space-y-0.5">
                @foreach($scorecard['layers'] as $key => $m)
                    <div>{{ $m['label'] }} <span class="text-neutral-300 dark:text-neutral-600">({{ $m['kind'] }}{{ $m['managed'] ? ' · '.$m['stage_label'] : '' }})</span>:
                        @if($m['opened_7d'] > 0)<span class="font-semibold text-neutral-600 dark:text-neutral-300">{{ number_format($m['opened_7d'], 0, ',', '.') }}</span> aday @endif
                        @if($m['filtered_7d'] > 0){{ $m['opened_7d'] > 0 ? ' · ' : '' }}<span class="font-semibold text-neutral-600 dark:text-neutral-300">{{ number_format($m['filtered_7d'], 0, ',', '.') }}</span> elenen @endif
                        @if($m['opened_7d'] === 0 && $m['filtered_7d'] === 0)—@endif
                        @if($m['managed'])
                            @php $judged = $m['shadow']['agree'] + $m['shadow']['disagree']; @endphp
                            @if($judged > 0) · gölge: {{ $judged }} örnek, hakemle uyum %{{ (int) round($m['shadow']['agreement'] * 100) }}@endif
                            @if($m['outcome']['total'] > 0) · sonuç: {{ $m['outcome']['good'] }} doğrulandı / {{ $m['outcome']['bad'] }} düzeltildi-reddedildi @endif
                        @endif
                    </div>
                @endforeach
            </div>
                </details>
            @endif
        </div>
    </div>

    {{-- Sekmeler telefonda kaymaz: iki/üç sütunlu ızgara, masaüstünde tek satır --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:flex gap-0.5 p-0.5 bg-neutral-100 dark:bg-neutral-900 rounded-xl">
        @foreach($tabs as $key => $label)
            <button type="button" wire:click="$set('activeTab', '{{ $key }}')" class="w-full lg:flex-1 px-2 sm:px-4 py-2 text-xs font-semibold rounded-lg leading-tight {{ $activeTab === $key ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">{{ $label }}@if($tabCount[$key] !== null) <span class="ml-1 text-[10px] text-neutral-400 tabular-nums">{{ number_format($tabCount[$key], 0, ',', '.') }}</span>@endif</button>
        @endforeach
    </div>

    @if(in_array($activeTab, ['queue', 'published', 'rejected'], true))
        @php
            $what = ['queue' => 'onay bekleyen aday', 'published' => 'yayında', 'rejected' => 'reddedilen'][$activeTab];
            $chip = 'inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-[11px] font-semibold whitespace-nowrap transition';
            $chipOn = 'border-neutral-900 bg-neutral-900 text-white dark:border-white dark:bg-white dark:text-neutral-900';
            $chipOff = 'border-neutral-200 dark:border-neutral-700 text-neutral-600 dark:text-neutral-300 hover:border-neutral-400 dark:hover:border-neutral-500';
            $customTime = trim($from) !== '' || trim($to) !== '';
        @endphp

        {{-- Filtre paneli: her alan düz ve görünür; kayan menü ya da açılır panel yok (Osman, 2026-10-08) --}}
        <div class="apple-glass rounded-2xl p-3 sm:p-4 space-y-3 text-xs">
            <div class="flex flex-col sm:flex-row gap-2">
                <input type="search" wire:model.live.debounce.400ms="search" class="{{ $input }} sm:flex-1" placeholder="Ara: rota, yük, ham mesaj, telefon, #no">
                <select wire:model.live="sort" class="{{ $input }} sm:w-52" title="Sıralama">@foreach($sortOptions as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-[1fr_auto_1fr] gap-2 md:items-end">
                <div class="space-y-1">
                    <label class="form-label">Nereden</label>
                    <div class="grid grid-cols-2 gap-1.5">
                        <select wire:model.live="fromProvince" class="{{ $input }}"><option value="">Tüm iller</option>@foreach(TurkishLocations::provinces() as $p)<option value="{{ $p['code'] }}">{{ $p['name'] }}</option>@endforeach</select>
                        <select wire:model.live="fromDistrict" class="{{ $input }}" @disabled($fromProvince === '')><option value="">Tüm ilçeler</option>@foreach($fromDistricts as $d)<option value="{{ $d }}">{{ $d }}</option>@endforeach</select>
                    </div>
                </div>
                <button type="button" wire:click="swapRoute" class="hidden md:inline-flex items-center justify-center md:mb-0.5 h-9 w-9 rounded-xl border border-neutral-200 dark:border-neutral-700 text-neutral-600 dark:text-neutral-300 hover:border-neutral-400 text-base leading-none" title="Nereden ile nereyeyi değiştir" aria-label="Nereden ile nereyeyi değiştir">⇄</button>
                <div class="space-y-1">
                    <div class="flex items-center justify-between"><label class="form-label">Nereye</label><button type="button" wire:click="swapRoute" class="md:hidden text-[11px] font-semibold text-brand-600">⇄ Yer değiştir</button></div>
                    <div class="grid grid-cols-2 gap-1.5">
                        <select wire:model.live="toProvince" class="{{ $input }}"><option value="">Tüm iller</option>@foreach(TurkishLocations::provinces() as $p)<option value="{{ $p['code'] }}">{{ $p['name'] }}</option>@endforeach</select>
                        <select wire:model.live="toDistrict" class="{{ $input }}" @disabled($toProvince === '')><option value="">Tüm ilçeler</option>@foreach($toDistricts as $d)<option value="{{ $d }}">{{ $d }}</option>@endforeach</select>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-[auto_1fr_1fr] gap-2 md:items-end">
                <div class="space-y-1">
                    <label class="form-label">Geliş zamanı</label>
                    <div class="flex flex-wrap gap-1">
                        @foreach(['1' => 'Bugün', '7' => '7 gün', '30' => '30 gün', 'all' => 'Tümü'] as $k => $l)
                            <button type="button" wire:click="setPeriod('{{ $k }}')" class="{{ $chip }} {{ ! $customTime && $period === $k ? $chipOn : $chipOff }}">{{ $l }}</button>
                        @endforeach
                    </div>
                </div>
                <div class="space-y-1"><label class="form-label">Başlangıç (gün ve saat)</label><input type="datetime-local" wire:model.live="from" class="{{ $input }}"></div>
                <div class="space-y-1"><label class="form-label">Bitiş (gün ve saat)</label><input type="datetime-local" wire:model.live="to" class="{{ $input }}"></div>
            </div>

            <div class="grid grid-cols-2 lg:grid-cols-4 gap-2">
                <div class="space-y-1"><label class="form-label">Kaynak</label><select wire:model.live="sourceId" class="{{ $input }}"><option value="">Tüm kaynaklar</option>@foreach($sourcesList as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></div>
                <div class="space-y-1"><label class="form-label">Araç</label><select wire:model.live="vehicle" class="{{ $input }}"><option value="">Tüm araçlar</option><option value="none">Araç tipi yok</option><option value="any">Araç fark etmez</option>@foreach(VehicleTypes::labels() as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                <div class="space-y-1"><label class="form-label">Kasa / dorse</label><select wire:model.live="body" class="{{ $input }}"><option value="">Tüm kasalar</option><option value="none">Kasa yazmıyor</option>@foreach(BodyTypes::labels() as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                <div class="space-y-1"><label class="form-label">Yük türü</label><select wire:model.live="goods" class="{{ $input }}"><option value="">Tüm yükler</option><option value="none">Yük yazmıyor</option>@foreach(GoodsCatalog::labels() as $l)<option value="{{ $l }}">{{ $l }}</option>@endforeach</select></div>
            </div>

            <div class="grid grid-cols-2 lg:grid-cols-4 gap-2">
                <div class="space-y-1"><label class="form-label">Fiyat en az (₺)</label><input type="number" inputmode="numeric" min="0" wire:model.live.debounce.500ms="minPrice" class="{{ $input }}" placeholder="0"></div>
                <div class="space-y-1"><label class="form-label">Fiyat en çok (₺)</label><input type="number" inputmode="numeric" min="0" wire:model.live.debounce.500ms="maxPrice" class="{{ $input }}" placeholder="∞"></div>
                <div class="space-y-1"><label class="form-label">Tonaj en az (ton)</label><input type="number" inputmode="decimal" min="0" step="0.5" wire:model.live.debounce.500ms="minWeight" class="{{ $input }}" placeholder="0"></div>
                <div class="space-y-1"><label class="form-label">Tonaj en çok (ton)</label><input type="number" inputmode="decimal" min="0" step="0.5" wire:model.live.debounce.500ms="maxWeight" class="{{ $input }}" placeholder="∞"></div>
            </div>

            <div class="space-y-1">
                <label class="form-label">Durum (birden çok seçilebilir)</label>
                <div class="flex flex-wrap gap-1.5">
                    @foreach($flagOptions as $k => $l)
                        <button type="button" wire:click="toggleFlag('{{ $k }}')" class="{{ $chip }} {{ in_array($k, $flags, true) ? $chipOn : $chipOff }}">{{ $l }}</button>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Sonuç sayısı ve seçili filtre rozetleri (× ile tek tek kalkar) --}}
        <div class="flex flex-wrap items-center gap-2 text-xs">
            <span class="inline-flex items-center gap-1.5 rounded-full bg-neutral-900 dark:bg-white text-white dark:text-neutral-900 px-3 py-1 font-bold tabular-nums">{{ number_format($queue?->total() ?? 0, 0, ',', '.') }} ilan</span>
            <span class="text-neutral-500">{{ $filterChips !== [] ? 'filtreye uyan' : $what }}{{ $customTime ? '' : ($period !== 'all' ? ' · son '.$period.' gün' : ' · tüm zamanlar') }}</span>
            @if($searchCounts !== [])
                <span class="text-neutral-500">· bu arama:
                    @foreach(['queue' => 'onay bekleyen', 'published' => 'yayında', 'rejected' => 'reddedilen'] as $tabKey => $tabLabel)
                        <button type="button" wire:click="$set('activeTab', '{{ $tabKey }}')" class="{{ $activeTab === $tabKey ? 'font-bold text-neutral-900 dark:text-white' : 'text-brand-500 hover:underline' }} tabular-nums">{{ $tabLabel }} {{ $searchCounts[$tabKey] }}</button>@if(! $loop->last) · @endif
                    @endforeach
                </span>
            @endif
            @foreach($filterChips as $c)
                <button type="button" wire:click="removeFilter('{{ $c['key'] }}')" class="inline-flex items-center gap-1 rounded-full bg-brand-500/10 text-brand-700 dark:text-brand-300 px-2.5 py-1 text-[11px] font-semibold" title="Bu filtreyi kaldır">{{ $c['label'] }} <span aria-hidden="true" class="text-brand-500">×</span></button>
            @endforeach
            @if($filterChips !== [])<button type="button" wire:click="clearFilters" class="text-[11px] font-semibold text-neutral-500 hover:underline">Tümünü temizle</button>@endif
            {{-- Analiz dökümü: bu sekmedeki adaylar (son N gün) satır başına JSON olarak iner; telefonlar maskeli --}}
            <a href="{{ route('admin.scrapers.export', ['kapsam' => $activeTab, 'gun' => $period === 'all' ? 60 : (int) $period]) }}" class="ml-auto text-[11px] font-semibold text-brand-600 hover:underline whitespace-nowrap" title="Bu sekmedeki adayları (son {{ $period === 'all' ? 60 : (int) $period }} gün, en çok 20.000) kuralın okuduğu alanlar, karar puanı ve bekletme nedeniyle birlikte indirir; telefonlar maskelidir">Analiz dökümü indir</a>
        </div>

        @if($selected !== [])
            <div class="sticky top-16 z-20 apple-glass rounded-2xl p-3 flex flex-wrap items-center gap-2 text-xs border border-brand-500/30">
                <span class="font-bold text-neutral-900 dark:text-white mr-2">{{ count($selected) }} seçili</span>
                <button type="button" wire:click="selectAllMatching" class="text-brand-600 font-semibold hover:underline mr-2">Filtreye uyan tümünü seç</button>
                @if($activeTab !== 'published')<button type="button" wire:click="bulk('approve')" class="btn-primary py-1.5 px-3 text-xs">Yayınla</button>@endif
                @if($activeTab !== 'rejected')
                    <select wire:model="bulkRejectReason" class="form-input py-1.5 text-xs w-auto" title="Ret gerekçesi: yalnız 'İlan değil' sınıflandırıcıya öğretir">
                        @foreach(\App\Services\ScrapedLoadService::REJECT_REASONS as $rk => $rl)<option value="{{ $rk }}">{{ $rl }}</option>@endforeach
                    </select>
                    <button type="button" wire:click="bulk('reject')" wire:confirm="Seçili adaylar seçilen gerekçeyle reddedilecek." class="btn-secondary py-1.5 px-3 text-xs">Reddet</button>
                @endif
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
                <table class="table-cards w-full text-left text-xs">
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
                                <td class="p-3 tc-check"><input type="checkbox" wire:model.live="selected" value="{{ $load->id }}" class="rounded mt-1"></td>
                                <td class="p-3">
                                    <div class="font-bold">#{{ $load->id }}
                                        @if((int) $load->duplicate_count > 1)<span class="ml-1 inline-flex items-center justify-center min-w-[1.5rem] h-5 px-1.5 rounded-full bg-amber-500 text-white text-[10px] font-bold align-middle" title="{{ implode(', ', (array) $load->seen_sources) }}">{{ $load->duplicate_count }}</span>@endif
                                        @if($load->is_incomplete)<span class="ml-1 badge bg-neutral-200 dark:bg-neutral-800 text-neutral-600 dark:text-neutral-300 align-middle" title="Puanı ret ile onay arasında; rotası ve telefonu belli. Şoför arayıp araç tipini girince tamamlanır.">Eksik bilgili</span>@elseif($load->auto_approved_at)<span class="ml-1 badge bg-sky-500/10 text-sky-600 align-middle">Otomatik</span>@endif
                                    @if($load->completed_by === 'driver')<span class="ml-1 badge bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 align-middle">Şoför tamamladı</span>@endif
                                        @if((int) ($load->trips_count ?? 0) > 0)<span class="ml-1 badge bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 align-middle" title="Bu ilanı 'Bu işi aldım' diye işaretleyen şoför sayısı">{{ $load->trips_count }} şoför aldı</span>@endif
                                    </div>
                                    <div class="text-[11px] text-neutral-400">{{ $load->scraper?->name ?? 'Kaynak silinmiş' }}<span class="lg:hidden"> · </span><br class="hidden lg:block">{{ $load->created_at?->format('d.m.Y H:i') }}<span class="lg:hidden"> · </span><br class="hidden lg:block">{{ $load->masked_phone }}@if(($extra = $load->extraPhones()) !== [])<span class="lg:hidden"> · </span><br class="hidden lg:block"><span class="text-brand-500 font-semibold" title="{{ implode(', ', array_map(fn ($p) => \App\Support\Phone::format($p), $extra)) }}">+{{ count($extra) }} numara</span>@endif@if($load->meta('message_part'))<span class="lg:hidden"> · </span><br class="hidden lg:block"><span title="Aynı mesajdan ayrılan ilanlardan biri">mesajın {{ (int) $load->meta('message_part')['index'] + 1 }}/{{ $load->meta('message_part')['count'] }}. ilanı</span>@endif</div>
                                </td>
                                <td class="p-3">
                                    <div class="font-bold text-neutral-900 dark:text-white text-sm">
                                        @if($load->isUrgent())<span class="badge bg-red-500 text-white mr-1">ACİL</span>@endif
                                        {{ $load->pickup_location ?: '—' }} <span class="text-neutral-400">→</span> {{ $load->delivery_location ?: '—' }}
                                    </div>
                                    <div class="mt-1 flex flex-wrap items-center gap-1">
                                        @if($load->vehicle_type)
                                            <span class="badge {{ in_array($load->vehicle_type_source, ['keyword', 'ai', 'admin'], true) ? 'bg-neutral-900 dark:bg-white text-white dark:text-neutral-900' : 'bg-neutral-100 dark:bg-neutral-800 text-neutral-700 dark:text-neutral-200' }}" title="Kaynak: {{ ['keyword' => 'araç adı', 'hint' => 'kasa ipucu', 'weight' => 'tonaj', 'pallet' => 'palet adedi', 'volume' => 'hacim', 'goods' => 'yük türünden çıkarım', 'ai' => 'yapay zeka', 'admin' => 'yönetici', 'template' => 'doğrulanmış kalıp'][$load->vehicle_type_source] ?? $load->vehicle_type_source }}">{{ $load->vehicleLabel() }}</span>
                                        @elseif($load->vehicle_any)
                                            <span class="badge bg-neutral-900 dark:bg-white text-white dark:text-neutral-900">Araç fark etmez</span>
                                        @else
                                            <span class="badge bg-amber-500/10 text-amber-600">Araç tipi yok</span>
                                        @endif
                                        @if($load->bodyLabel())<span class="badge bg-teal-500/10 text-teal-700 dark:text-teal-300" title="Kasa ({{ ['keyword' => 'kasa sözcüğü', 'lexicon' => 'sözlük', 'goods' => 'yükten çıkarım', 'ai' => 'yapay zeka', 'admin' => 'yönetici'][$load->body_type_source] ?? $load->body_type_source }})">{{ $load->bodyLabel() }}</span>@endif
                                        @if($load->loadKindLabel())<span class="badge bg-neutral-100 dark:bg-neutral-800 text-neutral-700 dark:text-neutral-200">{{ $load->loadKindLabel() }}</span>@endif
                                        @if(($load->vehicle_count ?? 1) > 1)<span class="badge bg-brand-500/10 text-brand-600">{{ $load->vehicle_count }} araç</span>@endif
                                        @if(count($load->deliveryStops()) > 1)<span class="badge bg-neutral-100 dark:bg-neutral-800 text-neutral-700 dark:text-neutral-200" title="{{ implode(' → ', $load->deliveryStops()) }}">{{ count($load->deliveryStops()) }} teslim noktası</span>@endif
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
                                <td class="p-3 whitespace-nowrap" data-label="Fiyat">@if($load->priceLabel())<span class="font-semibold">{{ $load->priceLabel() }}</span>@else<span class="text-neutral-400">belirtilmemiş</span>@endif</td>
                                <td class="p-3 max-w-xs text-neutral-500 tc-block" data-label="Ham mesaj"><x-clamp-text :text="$load->raw_message" lines="2" /></td>
                                <td class="p-3" data-label="Durum">
                                    <span class="px-2 py-1 rounded-full text-[10px] font-semibold {{ $load->visibility === 'public' ? 'bg-emerald-500/10 text-emerald-600' : ($load->status === 'rejected' ? 'bg-red-500/10 text-red-600' : 'bg-amber-500/10 text-amber-600') }}">{{ $load->visibility === 'public' ? 'Yayında' : ($load->status === 'rejected' ? 'Reddedildi' : 'Onay bekliyor') }}</span>
                                    @if($load->meta('duplicate_of'))<div class="text-[11px] text-neutral-400 mt-1">Tekrar: #{{ $load->meta('duplicate_of') }} yayında</div>@endif
                                    @if($load->hasSimilar())<div class="text-[11px] text-neutral-400 mt-1" title="Aynı yük başka numarayla da paylaşılmış; komisyoncu olabilir">Benzer ilan (farklı numara): {{ implode(', ', array_map(fn ($i) => '#'.$i, $load->similarIds())) }}</div>@endif
                                    @if($r = $load->meta('auto_rejected'))<div class="text-[11px] text-neutral-400 mt-1">Otomatik ret: {{ $r['reason'] ?? '' }}</div>@endif
                                    @if($load->meta('route_inferred'))<div class="text-[11px] text-neutral-400 mt-1" title="Rota kesin kuralla değil, {{ $load->meta('route_inferred') === 'two_line' ? 'iki satırın sırasıyla' : 'gönderen hafızasıyla' }} çözüldü">Rota yorumla çözüldü{{ $load->meta('route_confirmed') ? ' · yapay zeka doğruladı' : '' }}</div>@endif
                                    @if($load->meta('needs_pickup') && $load->status !== 'rejected' && $load->visibility !== 'public')
                                        <div class="mt-2 text-[11px] space-y-1">
                                            <div class="text-amber-600 font-semibold">Kalkış yazmıyor · {{ count((array) $load->meta('dest_lines', [])) }} varış satırı</div>
                                            <div class="flex items-center gap-1">
                                                <input type="text" wire:model="teachPickup.{{ $load->id }}" wire:keydown.enter.prevent="teachPickup({{ $load->id }})" class="form-input py-1 text-[11px] w-36" placeholder="Kalkış: İl İlçe">
                                                <button type="button" wire:click="teachPickup({{ $load->id }})" class="text-emerald-600 font-semibold whitespace-nowrap">Kalkış öğret</button>
                                            </div>
                                            <div class="text-neutral-400">Bu gönderenin sonraki listeleri bu kalkışla kendiliğinden ayrılır.</div>
                                        </div>
                                    @endif
                                    @if($activeTab === 'queue')
                                        @if($blocker && ($incompleteEligible[$load->id] ?? false))
                                            <div class="text-[11px] mt-1 text-sky-600">{{ $autoApprove ? 'Eksik bilgili yayına gidecek' : 'Otomatik onay kapalı · eksik bilgili yayına uygun' }} · {{ $blockerLabels[$blocker] ?? $blocker }}</div>
                                        @else
                                            <div class="text-[11px] mt-1 {{ $blocker ? 'text-amber-600' : 'text-emerald-600' }}">{{ $autoApprove ? 'Otomatik onay: ' : 'Otomatik onay kapalı · ' }}{{ $blocker ? ($blockerLabels[$blocker] ?? $blocker) : 'uygun' }}</div>
                                        @endif
                                        @if($d = $decisions[$load->id] ?? null)
                                            <div class="text-[11px] text-neutral-400" title="{{ $d['basis'] }}">Karar puanı %{{ (int) round($d['score'] * 100) }} · kural %{{ (int) round($d['rule'] * 100) }}@if($d['ai'] !== null) · yapay zeka %{{ (int) round($d['ai'] * 100) }}@endif @if($d['local'] !== null) · yerel %{{ (int) round($d['local'] * 100) }}@endif</div>
                                        @endif
                                    @endif
                                </td>
                                <td class="p-3 whitespace-nowrap tc-actions">
                                    <div class="flex flex-col gap-1 items-start">
                                        @if($load->visibility !== 'public' && $load->status !== 'rejected')<button type="button" wire:click="approve({{ $load->id }})" class="text-emerald-600 font-semibold">Yayınla</button>@endif
                                        @if($load->status === 'rejected')<button type="button" wire:click="restore({{ $load->id }})" class="text-emerald-600 font-semibold">Kuyruğa geri al</button>@endif
                                        <button type="button" wire:click="startEdit({{ $load->id }})" class="text-neutral-600 dark:text-neutral-300 font-semibold">Düzenle</button>
                                        <button type="button" wire:click="reparse({{ $load->id }})" class="text-violet-600 font-semibold">Yapay zeka ile çözümle</button>
                                        @if($load->status !== 'rejected')
                                            <div class="flex items-center gap-1">
                                                <select wire:model="rejectReasons.{{ $load->id }}" class="form-input py-1 text-[11px] w-auto max-w-[9rem]" title="Ret gerekçesi">
                                                    @foreach(\App\Services\ScrapedLoadService::REJECT_REASONS as $rk => $rl)<option value="{{ $rk }}" @selected($rk === 'other')>{{ $rk === 'not_load' ? 'İlan değil' : $rl }}</option>@endforeach
                                                </select>
                                                <button type="button" wire:click="reject({{ $load->id }})" wire:confirm="Aday seçilen gerekçeyle reddedilecek." class="text-red-500 font-semibold">Reddet</button>
                                            </div>
                                        @endif
                                        <button type="button" wire:click="delete({{ $load->id }})" wire:confirm="Aday KALICI olarak silinecek; geri alınamaz." class="text-neutral-400 hover:text-red-600">Sil</button>
                                    </div>
                                </td>
                            </tr>
                            @if($editingId === $load->id)
                                <tr class="bg-neutral-50 dark:bg-neutral-900/60 tc-editor">
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
                                                <select wire:model="edit.vehicle_type" class="{{ $input }}"><option value="">Belirsiz</option><option value="any">Fark etmez (her araç)</option>@foreach(VehicleTypes::labels() as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
                                            </div>
                                            <div class="col-span-2 md:col-span-4">
                                                <label class="form-label">Kasa / dorse (birden çok seçilebilir; boş = fark etmez)</label>
                                                <div class="flex flex-wrap gap-x-4 gap-y-1.5">
                                                    @foreach(BodyTypes::labels() as $bk => $bl)
                                                        <label class="inline-flex items-center gap-1.5 text-xs"><input type="checkbox" wire:model="edit.body_types" value="{{ $bk }}" class="rounded border-neutral-300 dark:border-neutral-700 text-brand-500 focus:ring-brand-500"> {{ $bl }}</label>
                                                    @endforeach
                                                </div>
                                            </div>
                                            <div><label class="form-label">Yük biçimi</label><select wire:model="edit.load_kind" class="{{ $input }}"><option value="">Belirsiz</option>@foreach(BodyTypes::LOAD_KINDS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                                            <div><label class="form-label">Yük türü</label><input type="text" wire:model="edit.goods_type" class="{{ $input }}" list="goods-catalog"></div>
                                            <div><label class="form-label">Tonaj (kg)</label><input type="number" wire:model="edit.weight" class="{{ $input }}" min="1" max="60000">@error('edit.weight')<div class="text-red-500 mt-1">{{ $message }}</div>@enderror</div>
                                            <div class="col-span-2 md:col-span-1"><label class="form-label">Fiyat (₺)</label><div class="flex gap-2"><input type="number" step="0.01" wire:model="edit.price" class="{{ $input }} min-w-0" min="0"><select wire:model="edit.price_unit" class="{{ $input }} !w-32 shrink-0"><option value="total">Toplam</option><option value="per_ton">Ton başına</option></select></div>@error('edit.price')<div class="text-red-500 mt-1">{{ $message }}</div>@enderror</div>
                                            <datalist id="goods-catalog">@foreach(\App\Support\GoodsCatalog::labels() as $label)<option value="{{ $label }}"></option>@endforeach</datalist>
                                            <div class="col-span-2 md:col-span-4 flex flex-wrap items-center gap-3">
                                                <button type="submit" class="btn-primary text-xs px-4 py-2 whitespace-nowrap shrink-0">Kaydet</button>
                                                <button type="button" wire:click="cancelEdit" class="btn-secondary text-xs px-4 py-2 whitespace-nowrap shrink-0">Vazgeç</button>
                                                <span class="text-neutral-400 basis-full md:basis-auto md:flex-1">Elle düzenlenen aday otomatik standartlaştırmadan ve yapay zeka birleştirmesinden etkilenmez.</span>
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
                <table class="table-cards w-full text-left text-xs">
                    <thead><tr class="border-b border-neutral-100 dark:border-neutral-800/50 text-[11px] text-neutral-400"><th class="p-3">Zaman</th><th class="p-3">Kaynak</th><th class="p-3">Sonuç</th><th class="p-3">Mesaj</th><th class="p-3">Aday</th></tr></thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/40">
                        @forelse($events as $e)
                            @php $tone = ['created' => 'bg-emerald-500/10 text-emerald-600', 'duplicate' => 'bg-sky-500/10 text-sky-600', 'source_pending' => 'bg-amber-500/10 text-amber-600', 'unauthorized' => 'bg-red-500/10 text-red-600', 'failed' => 'bg-red-500/10 text-red-600'][$e->status] ?? 'bg-neutral-100 dark:bg-neutral-800 text-neutral-500'; @endphp
                            <tr class="align-top">
                                <td class="p-3 whitespace-nowrap text-neutral-500" data-label="Zaman">{{ $e->created_at->format('d.m H:i:s') }}</td>
                                <td class="p-3" data-label="Kaynak">{{ $e->source_name ?: ($e->title ?: '—') }}</td>
                                <td class="p-3" data-label="Sonuç"><span class="badge {{ $tone }}">{{ $e->statusLabel() }}</span>@if($e->reason)<div class="text-[11px] text-neutral-400 mt-1">{{ \App\Models\IntakeEvent::reasonLabel($e->reason) }}</div>@endif</td>
                                <td class="p-3 max-w-md text-neutral-600 dark:text-neutral-300 tc-block" data-label="Mesaj"><x-clamp-text :text="$e->excerpt" lines="2" /></td>
                                <td class="p-3 whitespace-nowrap" data-label="Aday">@if($e->scraped_load_id)<button type="button" wire:click="$set('search', '#{{ $e->scraped_load_id }}'); $set('activeTab', 'queue')" class="text-brand-500 font-semibold">#{{ $e->scraped_load_id }}</button>@else —@endif</td>
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
        <div class="apple-glass rounded-3xl p-6 space-y-3 text-xs" x-data="{ open: false, copied: '' , copy(text, key) { navigator.clipboard.writeText(text).then(() => { this.copied = key; setTimeout(() => this.copied = '', 2000); }); } }">
            <div class="flex items-center justify-between gap-3">
            <h2 class="text-sm font-bold text-neutral-900 dark:text-white">Telefon bağlantısı (bildirim iletici)</h2>
                <button type="button" @click="open = !open" class="text-[11px] font-semibold text-brand-600 hover:underline shrink-0" x-text="open ? 'Kurulum adımlarını gizle' : 'Kurulum adımlarını göster'"></button>
            </div>
            {{-- Kurulum metinleri ve adresler varsayılan gizli: sayfa kaynak listesine ayrılır; gerekince tek dokunuşla açılır --}}
            <div x-show="open" x-cloak class="space-y-3">
            <p class="text-[11px] text-neutral-400">Aşağıdaki adres ve alanlar hangi telefondaki bildirim iletici uygulamasına yazılırsa o telefon sunucuya ilan iletmeye başlar; anahtar alanların içinde hazırdır, sunucu tarafında telefon başına ayar yoktur. Adım adım kurulum: <span class="font-mono">docs/BILDIRIM_ILETICI_KURULUM.md</span>.</p>

            <div class="flex flex-wrap items-center gap-3 p-3 rounded-2xl bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/60 dark:border-neutral-700/40">
                <span class="font-bold text-neutral-900 dark:text-white">Sorun giderme</span>
                <span class="text-[11px] text-neutral-500">Telefon istek atmıyor gibi görünüyorsa: sınama bağlantısını <strong>telefonun tarayıcısında</strong> açın; Canlı akışa "Bağlantı sınaması" düşerse ağ ve anahtar tamamdır, sorun telefondaki makro tetikleyicisindedir (bildirim erişimi, sessize alınmış grup, kendi yazdığınız mesaj bildirim üretmez).</span>
                <button type="button" @click="copy(@js($pingUrl), 'ping')" class="btn-secondary py-2 px-3 text-xs" x-text="copied === 'ping' ? 'Kopyalandı' : 'Sınama bağlantısını kopyala'"></button>
                <a href="{{ $pingUrl }}" target="_blank" rel="noopener" class="text-brand-600 font-semibold hover:underline text-xs">Buradan aç</a>
            </div>

            <div class="text-xs space-y-2 p-3 rounded-2xl bg-brand-50/60 dark:bg-brand-950/20 border border-brand-200/60 dark:border-brand-800/40">
                <h3 class="font-semibold text-neutral-800 dark:text-neutral-100">Önerilen yol: NavlunIQ Toplayıcı uygulaması (WhatsApp + Facebook, tek kurulum)</h3>
                <p class="text-[11px] text-neutral-600 dark:text-neutral-300">Kendi küçük Android uygulamamız. Telefona bir kez kurulur, iki izin verilir, anahtar yapıştırılır; sonrası kendiliğinden: WhatsApp grup bildirimlerindeki her mesaj ve Facebook'ta <strong>normal gezinirken</strong> ekranda görünen her grup gönderisi sunucuya gelir. Düğme yok, kaydırma makrosu yok, MacroDroid yok. Veri yalnız bu sunucuya gider; yazar adları saklanmaz.</p>
                @if ($toplayici['available'])
                    <div class="flex flex-wrap items-center gap-2">
                        <a href="{{ $toplayici['url'] }}" class="btn-primary py-2 px-3 text-xs">Uygulamayı indir (APK, v{{ $toplayici['versionName'] }})</a>
                        <button type="button" @click="copy(@js($toplayici['url']), 'apk')" class="btn-secondary py-2 px-3 text-xs" x-text="copied === 'apk' ? 'Kopyalandı' : 'İndirme bağlantısını kopyala'"></button>
                        <span class="text-[11px] text-neutral-500">{{ number_format($toplayici['bytes'] / 1024, 0) }} KB · bağlantı herkese açıktır, anahtar içinde değildir; şoföre WhatsApp ile gönderilebilir.</span>
                    </div>
                @else
                    <p class="text-[11px] text-red-600">APK dosyası sunucuda yok (public/toplayici). Siteyi güncelleyin.</p>
                @endif
                <ol class="list-decimal pl-5 space-y-1 text-[11px] text-neutral-600 dark:text-neutral-300">
                    <li>Telefonda bağlantıyı açın, inen dosyaya dokunun; "Bilinmeyen uygulama" uyarısında <strong>İzin ver → Yükle</strong>. (Play Protect "tanınmayan uygulama" derse <strong>Yine de yükle</strong>.)</li>
                    <li>Uygulamayı açın, anahtarı yapıştırın (yukarıdaki <strong>Kopyala</strong> düğmesinden gelen metnin içindeki <span class="font-mono">token</span> değeri ya da aşağıdaki anahtar), <strong>Kaydet</strong>, <strong>Bağlantıyı sına</strong>: Canlı akışa "Bağlantı sınaması" düşer.</li>
                    <li><strong>WhatsApp bildirim iznini aç</strong> → listede NavlunIQ Toplayıcı'yı açın. <strong>Facebook okuma iznini aç</strong> → Yüklü uygulamalar → NavlunIQ Toplayıcı → açın. Anahtar gri ve tıklanmıyorsa (Android 13+): <strong>Uygulama bilgisi</strong> → sağ üst ⋮ → "Kısıtlı ayarlara izin ver", sonra tekrar.</li>
                    <li><strong>Pil kısıtlamasını kaldır</strong> deyin; Xiaomi/Huawei/Oppo'da ayrıca Uygulama bilgisi → "Otomatik başlat" açılır.</li>
                    <li><strong>Normal Facebook uygulaması gerekir.</strong> Facebook Lite ekranı kendi çizer, yazıları Android'e vermez; uygulama hiçbir şey okuyamaz (uygulama ekranında "Facebook ekranı: 0" kalır). Tarayıcıdan açılan facebook.com da okunmaz.</li>
                    <li>Bitti. WhatsApp grup mesajları kendiliğinden gelir; Canlı akışta görünmeye başlayınca MacroDroid'deki WhatsApp makrosunu kapatın (ikisi birden açık kalırsa sunucu tekrarı eler ama gereksiz yük olur). Facebook için Gruplar akışında ya da bir grubun içinde normal kaydırın; her yeni ekran 20 saniyede bir paket olarak gider, Canlı akışa "Ekran dökümü alındı" satırı düşer. Aynı gönderi 24 saat içinde bir daha kuyruğa girmez.</li>
                </ol>
                <p class="text-[11px] text-neutral-500">Tanı: <a href="{{ route('admin.toplayici.dump') }}" class="text-brand-600 font-semibold hover:underline">son Facebook dökümünü indir</a> (son 3 ekran paketi, 48 saat; Kaynaklar'a tuhaf adlar düşerse bu dosya gönderilir, ayrıştırıcı ona göre düzeltilir). Uygulamanın kendi ekranında son gönderim, bekleyen paket sayısı ve günlük görünür; sorun olursa o ekranın görüntüsü yeter. Yeni sürüm çıkınca uygulama kendisi haber verir. Aşağıdaki MacroDroid yolları yalnız yedek olarak duruyor.</p>
            </div>

            <div class="text-xs space-y-1">
                <h3 class="font-semibold text-neutral-700 dark:text-neutral-200">Yedek yol A · MacroDroid ile WhatsApp bildirimleri: adres ve alanlar</h3>
                <p class="text-[11px] text-neutral-500 mt-2">Önerilen: içerik türü <strong>application/x-www-form-urlencoded</strong>, "Parametreler" bölümüne şu alanlar (mesajdaki tırnak/satır sonu JSON'u bozabilir, form alanlarını bozamaz):</p>
                <dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-0.5 text-[11px] font-mono mt-1">
                    @foreach($phoneParams as $k => $v)<dt class="font-bold">{{ $k }}</dt><dd class="break-all min-w-0">{{ $v }}</dd>@endforeach
                </dl>
                <div class="grid grid-cols-1 md:grid-cols-[auto_1fr_auto] gap-2 items-center mt-3">
                    <span class="text-neutral-400">Adres (POST)</span>
                    <code class="block px-3 py-2 rounded-xl bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/60 dark:border-neutral-700/40 font-mono break-all">{{ $webhookUrl }}</code>
                    <button type="button" @click="copy(@js($webhookUrl), 'url')" class="btn-secondary py-2 px-3 text-xs" x-text="copied === 'url' ? 'Kopyalandı' : 'Kopyala'"></button>
                    <span class="text-neutral-400">Alternatif: JSON gövde</span>
                    <code class="block px-3 py-2 rounded-xl bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/60 dark:border-neutral-700/40 font-mono break-all">{{ $tokenBody }}</code>
                    <button type="button" @click="copy(@js($tokenBody), 'body')" class="btn-primary py-2 px-3 text-xs" x-text="copied === 'body' ? 'Kopyalandı' : 'Kopyala'"></button>
                </div>
                <div class="flex items-center gap-3 pt-2">
                    <button type="button" wire:click="regenerateToken" wire:confirm="Anahtar yenilenince telefonlardaki eski alanlar çalışmaz; yeni anahtarı telefonlara yeniden girmeniz gerekir. Devam edilsin mi?" class="text-red-600 font-semibold hover:underline whitespace-nowrap">Anahtarı yenile</button>
                    <span class="text-[11px] text-neutral-400">İçerik türü: application/json · Zaman aşımı: 20 sn · "Yanıtı değişkene kaydet" gerekmez.</span>
                </div>
            </div>

            <div class="text-xs space-y-2 pt-2 border-t border-neutral-100 dark:border-neutral-800/50">
                <h3 class="font-semibold text-neutral-700 dark:text-neutral-200">Yedek yol B · MacroDroid ile Facebook akışı (bot yok)</h3>
                <p class="text-[11px] text-neutral-500">Facebook kalabalık gruplarda her gönderi için bildirim göndermez; bu yüzden gönderiler telefonun içinde okunur. Facebook'ta <strong>Gruplar</strong> sekmesi tüm grupların gönderilerini tek akışta gösterir; kayan düğmeye bir kez dokununca makro akışı aşağı kaydırır, ekrandaki yazıyı okur ve tek istekte buraya yollar. Facebook sunucusuna otomatik istek atılmaz. Sunucu dökümü gönderilere ayırır, yazar adlarını atar, reklamları ve tekrarları eler; her gönderi normal ayrıştırmadan geçer. Fotoğraf içindeki yazı okunamaz.</p>
                <div class="p-3 rounded-2xl bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/60 dark:border-neutral-700/40 space-y-2">
                    <p class="text-[11px] font-semibold text-neutral-700 dark:text-neutral-200">Hazır makro dosyası (elle kurulum gerekmez)</p>
                    <ol class="list-decimal pl-5 space-y-1 text-[11px] text-neutral-600 dark:text-neutral-300">
                        <li>Aşağıdaki düğmeyle <span class="font-mono">navluniq-macro-final-v1.macro</span> dosyasını indirin; anahtar ve adres içinde hazırdır. Dosyayı WhatsApp ile iletici telefona gönderin.</li>
                        <li>Telefonda dosyaya dokunun, açmak için <strong>MacroDroid</strong>'i seçin (ya da MacroDroid → Makrolar → sağ üst ⋮ → <strong>İçe aktar</strong> → dosyayı seçin). "navluniq macro final v1" makrosu listeye gelir.</li>
                        <li>MacroDroid izin isterse verin: Erişilebilirlik (UI etkileşimi, ekran okuma) ve "Diğer uygulamaların üzerinde göster". Ekranda <strong>NQ</strong> düğmesi belirir.</li>
                        <li>Facebook'ta Gruplar akışını ya da bir grubu açın, en üste gelin, NQ düğmesine dokunun; makro bulunduğunuz sayfayı 15 ekran kaydırıp okur, 40-50 saniye telefona dokunmayın. "Akış gönderildi" bildirimi gelir, gönderiler Canlı akışta görünür. Makro Facebook'u kendisi açmaz ve hiçbir şeye dokunmaz.</li>
                    </ol>
                    <div class="flex flex-wrap items-center gap-2">
                        <a href="{{ route('admin.macrodroid.download') }}" class="btn-primary py-2 px-4 text-xs inline-block">Makro dosyasını indir</a>
                        <span class="text-[11px] text-neutral-400">15 ekran kaydırır (≈ son 30-40 gönderi). Daha az/çok:</span>
                        <a href="{{ route('admin.macrodroid.download', ['ekran' => 8]) }}" class="text-brand-600 text-[11px] font-semibold hover:underline">8 ekran</a>
                        <a href="{{ route('admin.macrodroid.download', ['ekran' => 25]) }}" class="text-brand-600 text-[11px] font-semibold hover:underline">25 ekran</a>
                    </div>
                    <p class="text-[11px] text-neutral-400">Gönderildi mi? Dış Kaynak İlanları → <strong>Canlı akış</strong> sekmesi: telefondan gelen her istek anında satır olur (kuyruğa alındı / tekrar / elendi + neden / kaynak onay bekliyor / anahtar hatalı). Ekran dökümü gelir gelmez "Ekran dökümü alındı" satırı düşer (kaç gönderi, kaç karakter). Hiç satır yoksa istek sunucuya ulaşmamıştır; MacroDroid → Yuva → <strong>Sistem günlüğü</strong> HTTP isteğinin sonucunu (200 = ulaştı) gösterir. NQ düğmesini çöp kutusuna sürüklerseniz düğme gizlenir; geri getirmek için Makrolar listesinde makronun anahtarını kapatıp açın.</p>
                    <p class="text-[11px] text-neutral-400">İçe aktarma hata verirse ya da makrodaki "HTTP İsteği" / "Ekran içeriğini oku" satırları boş görünürse: telefondaki WhatsApp makrosuna uzun basıp <strong>Dışa aktar</strong> deyin ve çıkan dosyayı Osman'a gönderin; dosya biçimi ona göre düzeltilir. Aşağıdaki elle kurulum adımları yedek yoldur.</p>
                </div>
                <p class="text-[11px] font-semibold text-neutral-700 dark:text-neutral-200">Elle kurulum (yedek yol) · İzinler</p>
                <ul class="list-disc pl-5 space-y-1 text-[11px] text-neutral-600 dark:text-neutral-300">
                    <li>MacroDroid → Ayarlar → <strong>Erişilebilirlik hizmetleri</strong>: "UI etkileşimi" ve "Ekran içeriğini okuma" açık (Android erişilebilirlik izni istenir).</li>
                </ul>
                <p class="text-[11px] font-semibold text-neutral-700 dark:text-neutral-200">Makro (WhatsApp makrosundan ayrı, yeni makro)</p>
                <ol class="list-decimal pl-5 space-y-1.5 text-[11px] text-neutral-600 dark:text-neutral-300">
                    <li><strong>Tetikleyici:</strong> Kayan düğme (MacroDroid → Kayan düğme) ya da ana ekran kısayolu.</li>
                    <li><strong>İşlem 1:</strong> Değişkenler → Değişken ayarla → <span class="font-mono">ekran</span> (metin) = boş.</li>
                    <li><strong>İşlem 2:</strong> Döngü → Yinele <strong>15 kez</strong> (15 ekran ≈ son 30-40 gönderi). Döngünün içine sırayla:
                        <ul class="list-disc pl-5 mt-1 space-y-1">
                            <li>UI etkileşimi → <strong>Ekran içeriğini oku</strong> → değişken <span class="font-mono">parca</span>.</li>
                            <li>Değişkenler → Değişken ayarla → <span class="font-mono">ekran</span> = <span class="font-mono">{lv=ekran}</span> + yeni satır + <span class="font-mono">-----</span> + yeni satır + <span class="font-mono">{lv=parca}</span> ("Ekle" seçeneğiyle).</li>
                            <li>UI etkileşimi → Hareket: kaydır (yukarı; ekranın %75'inden %25'ine, 500 ms).</li>
                            <li>Bekle → 1,5 saniye.</li>
                        </ul>
                    </li>
                    <li><strong>İşlem 3:</strong> Bağlantı → HTTP İsteği → Ayarlar: POST, adres yukarıdaki (POST) adres, zaman aşımı 60 sn. İçerik gövdesi: içerik türü <strong>text/plain</strong>, Metin, gövdeye yalnız <span class="font-mono">{lv=ekran}</span>. Başlık parametreleri: <span class="font-mono">X-Scraper-Token</span> = yukarıdaki anahtar (token satırı), <span class="font-mono">X-Intake-Kind</span> = <span class="font-mono">screen</span>. (Gövde JSON olmaz: ekran içeriğindeki tırnaklar JSON'u bozar.)</li>
                    <li><strong>İşlem 4 (isteğe bağlı):</strong> Bildirim göster: "NavlunIQ: akış gönderildi".</li>
                </ol>
                <div class="grid grid-cols-1 md:grid-cols-[auto_1fr_auto] gap-2 items-center">
                    <span class="text-neutral-400">Gövde (düz metin)</span>
                    <code class="block px-3 py-2 rounded-xl bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/60 dark:border-neutral-700/40 font-mono break-all">{{ $screenBody }}</code>
                    <button type="button" @click="copy(@js($screenBody), 'screen')" class="btn-primary py-2 px-3 text-xs" x-text="copied === 'screen' ? 'Kopyalandı' : 'Kopyala'"></button>
                </div>
                <p class="text-[11px] font-semibold text-neutral-700 dark:text-neutral-200">Kullanım</p>
                <ul class="list-disc pl-5 space-y-1 text-[11px] text-neutral-600 dark:text-neutral-300">
                    <li>Facebook → <strong>Gruplar</strong> sekmesi → en üste gelin → kayan düğmeye dokunun → 30-40 saniye telefona dokunmayın.</li>
                    <li>Tek bir grubun içinden toplamak için HTTP isteğine <span class="font-mono">X-Intake-Title</span> başlığıyla grubun adını ekleyin (ayrı bir makro olarak).</li>
                    <li>Gönderiler Canlı akışta satır satır görünür; yeni gruplar aşağıda <span class="font-mono">fb:grup-adi</span> tanımlayıcısıyla pasif açılır, <strong>Aktif et</strong> deyince işlenir. Aynı ilan WhatsApp'ta da geldiyse ikinci kayıt açılmaz.</li>
                    <li>Facebook ekran düzenini değiştirirse kaydırma ayarı güncellenir. Kısaltılmış gönderiler "… diğer" ile kesik gelir (dokunma adımı makroyu durdurduğu için yok).</li>
                </ul>
            </div>
            </div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 items-start">
            <form wire:submit="addSource" class="apple-glass rounded-3xl p-6 space-y-3 text-xs">
                <h2 class="text-sm font-bold text-neutral-900 dark:text-white">Yeni kaynak</h2>
                <p class="text-[11px] text-neutral-400">Telefondan ilk mesaj geldiğinde grup kendiliğinden pasif kaynak olarak eklenir; burada elle de tanımlayabilirsiniz.</p>
                <div><label class="form-label">Ad</label><input type="text" wire:model="sourceName" class="{{ $input }}">@error('sourceName') <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror</div>
                <div><label class="form-label">Tür</label>
                    <select wire:model="sourceType" class="{{ $input }}"><option value="notification">WhatsApp grubu (bildirim iletici)</option><option value="facebook">Facebook grubu (bildirim iletici)</option><option value="whatsapp">WhatsApp grubu (servis)</option><option value="telegram">Telegram kanalı</option><option value="web">Web sayfası</option></select>
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
                        <table class="table-cards w-full text-left text-xs">
                            <thead><tr class="border-b border-neutral-100 dark:border-neutral-800/50 text-[11px] text-neutral-400"><th class="p-4 w-8"><input type="checkbox" wire:model.live="selectSourcePage" class="rounded" title="Sayfadakilerin tümünü seç"></th><th class="p-4">Kaynak</th><th class="p-4">Silinme</th><th class="p-4">Silindikten sonra gelen</th><th class="p-4">Aday</th><th class="p-4"></th></tr></thead>
                            <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/40">
                                @forelse($sources as $source)
                                    <tr wire:key="src-{{ $source->id }}" class="align-top {{ in_array((string) $source->id, $selectedSources, true) ? 'bg-brand-500/5' : '' }}">
                                        <td class="p-4 tc-check"><input type="checkbox" wire:model.live="selectedSources" value="{{ $source->id }}" class="rounded"></td>
                                        <td class="p-4"><div class="font-bold">{{ $source->name }}</div><div class="text-[11px] text-neutral-400 font-mono">{{ $source->source_identifier }}</div></td>
                                        <td class="p-4 whitespace-nowrap text-neutral-500" data-label="Silinme"><x-time-ago :at="$source->deleted_at" /></td>
                                        <td class="p-4" data-label="Silindikten sonra gelen">
                                            @if($source->messages_since_deleted > 0)
                                                <span class="badge bg-amber-500/10 text-amber-600">{{ $source->messages_since_deleted }} mesaj</span>
                                                <span class="text-[11px] text-neutral-400">son: <x-time-ago :at="$source->last_message_at" /></span>
                                            @else
                                                <span class="text-neutral-400">yok</span>
                                            @endif
                                        </td>
                                        <td class="p-4" data-label="Aday">{{ $source->scraped_loads_count }}</td>
                                        <td class="p-4 whitespace-nowrap space-x-2 tc-actions">
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
                        <table class="table-cards w-full text-left text-xs">
                            <thead><tr class="border-b border-neutral-100 dark:border-neutral-800/50 text-[11px] text-neutral-400"><th class="p-4 w-8"><input type="checkbox" wire:model.live="selectSourcePage" class="rounded" title="Sayfadakilerin tümünü seç"></th><th class="p-4">Kaynak</th><th class="p-4">Aday</th><th class="p-4">Son mesaj</th><th class="p-4">Durum</th><th class="p-4"></th></tr></thead>
                            <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/40">
                                @forelse($sources as $source)
                                    <tr wire:key="src-{{ $source->id }}" class="align-top {{ in_array((string) $source->id, $selectedSources, true) ? 'bg-brand-500/5' : (! $source->is_active ? 'bg-amber-500/5' : '') }}">
                                        <td class="p-4 tc-check"><input type="checkbox" wire:model.live="selectedSources" value="{{ $source->id }}" class="rounded"></td>
                                        <td class="p-4"><div class="font-bold">{{ $source->name }}</div><div class="text-[11px] text-neutral-400">{{ ['whatsapp' => 'WhatsApp servis', 'notification' => 'WhatsApp grubu (bildirim iletici)', 'facebook' => 'Facebook grubu (bildirim iletici)', 'telegram' => 'Telegram', 'web' => 'Web'][$source->type] ?? $source->type }} · <span class="font-mono">{{ $source->source_identifier }}</span></div></td>
                                        <td class="p-4" data-label="Aday">{{ $source->scraped_loads_count }}</td>
                                        <td class="p-4 whitespace-nowrap text-neutral-500" data-label="Son mesaj"><x-time-ago :at="$source->last_message_at ?? $source->last_success_at" empty="Henüz yok" /></td>
                                        <td class="p-4" data-label="Durum"><span class="px-2 py-1 rounded-full text-[10px] font-semibold {{ $source->is_active ? 'bg-emerald-500/10 text-emerald-600' : 'bg-amber-500/10 text-amber-600' }}">{{ $source->is_active ? 'Aktif' : 'Onay bekliyor' }}</span></td>
                                        <td class="p-4 whitespace-nowrap space-x-2 tc-actions">
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
                <p class="text-[11px] text-neutral-400">Dış servise bağlı değildir. Siz "Yayınla" dedikçe ilan örneği, "İlan değil" gerekçesiyle "Reddet" dedikçe ilan-değil örneği öğrenir (tekrar / eski / yanlış rota / diğer gerekçeleri öğretmez); yapay zeka doğrulamalı otomatik onaylar da ilan örneğidir. Her sınıfta en az {{ \App\Services\LocalClassifier::MIN_DOCS }} örnek olunca karar vermeye başlar; dış yapay zeka kotası dolduğunda otomatik onayı bu karar sürdürür. "İlan değil" diye eleme en az {{ \App\Services\LocalClassifier::MIN_OTHER_DOCS_FOR_FILTER }} ret örneğinden sonra başlar. Grup dışa aktarımlarından toplu öğretmek için sunucuda <code>php artisan intake:analyze /klasör --learn</code> (belgede anlatılır).</p>
                @if($classifier)
                    <div class="grid grid-cols-3 gap-2 text-center">
                        <div class="p-3 rounded-2xl bg-neutral-50 dark:bg-neutral-900"><div class="text-lg font-bold text-emerald-600">{{ $classifier['docs_load'] }}</div><div class="text-[10px] text-neutral-400">ilan örneği</div></div>
                        <div class="p-3 rounded-2xl bg-neutral-50 dark:bg-neutral-900"><div class="text-lg font-bold text-rose-600">{{ $classifier['docs_other'] }}</div><div class="text-[10px] text-neutral-400">ilan-değil örneği</div></div>
                        <div class="p-3 rounded-2xl bg-neutral-50 dark:bg-neutral-900"><div class="text-lg font-bold text-neutral-900 dark:text-white">{{ $classifier['tokens'] }}</div><div class="text-[10px] text-neutral-400">öğrenilen sözcük</div></div>
                    </div>
                    <div class="badge {{ $classifier['ready'] ? 'bg-emerald-500/10 text-emerald-600' : 'bg-amber-500/10 text-amber-600' }}">{{ $classifier['ready'] ? 'Karar veriyor' : 'Henüz yeterli örnek yok' }}</div>
                    <p class="text-[11px] text-neutral-400 pt-1"><strong class="text-neutral-700 dark:text-neutral-200">Şablon hafızası:</strong> {{ $classifier['templates'] }} doğrulanmış gönderen kalıbı, {{ $classifier['template_uses'] }} kez yapay zekasız çözüm. Aynı numaradan aynı kalıpla gelen ilan bir kez doğrulanınca (yapay zeka ya da sizin onayınız) sonrakiler kalıptan okunur; reddettiğiniz bir kalıp silinir.</p>
                @endif
                @if(auth()->user()->hasRole('super_admin'))
                    <div class="pt-2 border-t border-neutral-100 dark:border-neutral-800 space-y-2">
                        <p class="text-[11px] text-neutral-400">Geçmişten yeniden öğren: sayaçlar sıfırlanır, yayınlanan / "ilan değil" diye reddedilen tüm adaylardan yeniden öğrenilir. Geri alınamaz; önceki sayaçlar işlem kaydına yazılır. Onaylamak için <span class="font-mono">YENİDEN ÖĞREN</span> yazın.</p>
                        <div class="flex flex-wrap items-center gap-2">
                            <input type="text" wire:model="rebuildConfirm" placeholder="YENİDEN ÖĞREN" autocomplete="off" class="form-input py-1.5 text-xs w-44">
                            <button type="button" wire:click="rebuildClassifier" class="btn-secondary py-1.5 px-3 text-xs">Geçmişten yeniden öğren</button>
                        </div>
                        @error('rebuildConfirm')<p class="text-rose-500 text-[11px]">{{ $message }}</p>@enderror
                    </div>
                @else
                    <p class="text-[11px] text-neutral-400">"Geçmişten yeniden öğren" yalnız süper yöneticiye açıktır.</p>
                @endif
            </div>

            <div class="apple-glass rounded-3xl p-6 space-y-3 text-xs lg:col-span-2">
                <h2 class="text-sm font-bold text-neutral-900 dark:text-white">Jargon sözlüğüne ekle</h2>
                <p class="text-[11px] text-neutral-400">Tırcıların dilini siz öğretirsiniz: "ostim" → Ankara Ostim, "tenteli mega" → TIR, "salça" → Gıda, "kemik" → Damperli (kasa), "satılık" → ilan değil. Girilen sözcük sonraki her mesajda kural tarafından anında uygulanır; yapay zekaya gerek kalmaz.</p>
                <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-end">
                    <div><label class="form-label">Tür</label>
                        <select wire:model.live="lex.kind" class="{{ $input }}">@foreach($kinds as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                    <div><label class="form-label">Sözcük / ifade (mesajda geçtiği gibi)</label><input type="text" wire:model="lex.term" class="{{ $input }}" placeholder="ör. ostim, tenteli mega, satılık">@error('lex.term')<p class="text-rose-500 text-[11px] mt-1">{{ $message }}</p>@enderror</div>
                    <div><label class="form-label">Karşılığı</label>
                        @if($lex['kind'] === 'vehicle')
                            <select wire:model="lex.canonical" class="{{ $input }}"><option value="">Seçin</option>@foreach(\App\Support\VehicleTypes::labels() as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                        @elseif($lex['kind'] === 'goods')
                            <select wire:model="lex.canonical" class="{{ $input }}"><option value="">Seçin</option>@foreach(\App\Support\GoodsCatalog::labels() as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                        @elseif($lex['kind'] === 'body')
                            <select wire:model="lex.canonical" class="{{ $input }}"><option value="">Seçin</option>@foreach(\App\Support\BodyTypes::labels() as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach<option value="kapali,tenteli,frigo">Kapalı / Tenteli / Frigo (kasalı)</option><option value="damperli,acik">Damperli / Açık</option><option value="tenteli,kapali,acik,frigo">Damper hariç hepsi</option></select>
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
                    <h3 class="text-xs font-bold text-neutral-900 dark:text-white pt-2">Öneriler ({{ $suggestions->count() }})</h3>
                    <p class="text-[11px] text-neutral-400">Yapay zekanın çözdüğü ama kuralın bilmediği yazımlar ve sizin düzeltmelerinizden çıkan öneriler. Onaylanan öneri sözlüğe girer; aynı yazım bir daha yapay zekaya sorulmaz. "Yok say" denen bir daha önerilmez.</p>
                    <div class="space-y-2">
                        @foreach($suggestions as $sg)
                            @php $target = $sg->kind === 'vehicle' ? \App\Support\VehicleTypes::label($sg->canonical) : ($sg->kind === 'goods' ? (\App\Support\GoodsCatalog::label($sg->canonical) ?? $sg->canonical) : $sg->canonical); @endphp
                            <div wire:key="sg-{{ $sg->id }}" class="p-3 rounded-2xl border border-amber-200/60 dark:border-amber-900/40 bg-amber-50/40 dark:bg-amber-950/10 space-y-2">
                                <div class="text-[11px] flex flex-wrap gap-x-2 gap-y-1 items-center"><span class="badge bg-amber-500/10 text-amber-700">{{ $kinds[$sg->kind] ?? $sg->kind }}</span>@if($sg->term)<strong class="text-neutral-900 dark:text-white">{{ $sg->term }}</strong> →@endif <strong>{{ $target }}</strong>@if($sg->source === 'ai')<span class="text-neutral-400">· {{ $sg->hits }} ilanda görüldü</span>@endif</div>
                                @if($sg->note)<div class="text-[11px] text-neutral-400">{{ $sg->note }}</div>@endif
                                <div class="text-[11px] text-neutral-500 dark:text-neutral-400">{{ \Illuminate\Support\Str::limit($sg->sample, 200) }}</div>
                                <div class="flex flex-wrap gap-2 items-center">
                                    @if(! $sg->term)<input type="text" wire:model="suggestTerm.{{ $sg->id }}" class="{{ $input }} sm:max-w-xs" placeholder="mesajdaki sözcük">@endif
                                    <button type="button" wire:click="acceptSuggestion({{ $sg->id }})" class="btn-primary py-1.5 px-3 text-xs">{{ $sg->term ? 'Onayla' : 'Öğret' }}</button>
                                    <button type="button" wire:click="ignoreSuggestion({{ $sg->id }})" class="text-neutral-500 text-[11px] hover:underline">Yok say</button>
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
                                @if($seg['body'] ?? null)<span class="badge bg-teal-500/10 text-teal-700 dark:text-teal-300">Kasa: {{ $seg['body'] }}</span>@endif
                                @if($seg['load_kind'] ?? null)<span class="badge bg-neutral-100 dark:bg-neutral-800 text-neutral-700 dark:text-neutral-200">{{ \App\Support\BodyTypes::LOAD_KINDS[$seg['load_kind']] }}</span>@endif
                                @if(($seg['vehicle_count'] ?? 0) > 1)<span class="badge bg-brand-500/10 text-brand-600">{{ $seg['vehicle_count'] }} araç</span>@endif
                                @if(count($seg['stops'] ?? []) > 1)<span class="badge bg-neutral-100 dark:bg-neutral-800 text-neutral-700 dark:text-neutral-200">Teslim: {{ implode(' → ', $seg['stops']) }}</span>@endif
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
                    <div class="responsive-scroll"><table class="table-cards w-full text-left text-xs">
                        <thead><tr class="text-[11px] text-neutral-400 border-b border-neutral-100 dark:border-neutral-800/50"><th class="p-2">Tür</th><th class="p-2">Sözcük</th><th class="p-2">Karşılığı</th><th class="p-2">Kaynak</th><th class="p-2">Kullanım</th><th class="p-2"></th></tr></thead>
                        <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/40">
                        @foreach($lexicon as $row)
                            <tr wire:key="lex-{{ $row->id }}">
                                <td class="p-2 text-neutral-500" data-label="Tür">{{ $kinds[$row->kind] ?? $row->kind }}</td>
                                <td class="p-2 font-semibold text-neutral-900 dark:text-white" data-label="Sözcük">{{ $row->term }}</td>
                                <td class="p-2" data-label="Karşılığı">{{ $row->kind === 'vehicle' ? \App\Support\VehicleTypes::label($row->canonical) : ($row->kind === 'goods' ? (\App\Support\GoodsCatalog::label($row->canonical) ?? $row->canonical) : ($row->canonical ?: '—')) }}</td>
                                <td class="p-2 text-neutral-500" data-label="Kaynak">{{ $row->sourceLabel() }}</td>
                                <td class="p-2 text-neutral-500" data-label="Kullanım">{{ $row->hits }}</td>
                                <td class="p-2 text-right tc-actions"><button type="button" wire:click="deleteLexicon({{ $row->id }})" wire:confirm="Sözlükten silinsin mi?" class="text-red-600 text-[11px] font-semibold hover:underline">Sil</button></td>
                            </tr>
                        @endforeach
                        </tbody></table></div>
                @endif
            </div>
        </div>
    @endif
</div>
