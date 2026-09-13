<?php

use App\Models\ActivityLog;
use App\Models\CmsContent;
use App\Models\SettingRevision;
use App\Services\PaymentService;
use App\Support\Settings;
use Livewire\Volt\Component;

new class extends Component {
    public const GENERAL_KEYS = [
        'system_site_title' => 'Site başlığı',
        'system_meta_description' => 'Meta açıklaması',
        'system_maintenance_note' => 'Bakım duyurusu',
    ];

    public const LIMIT_LABELS = [
        'commission_standard_driver' => 'Standart şoför komisyonu (%)',
        'commission_discounted_premium' => 'Premium şoför komisyonu (%)',
        'commission_cargo_owner' => 'Yük sahibi hizmet bedeli (%)',
        'delivery_auto_approval_hours' => 'Teslimat sonrası otomatik onay süresi (saat)',
        'offer_validity_days' => 'Teklif geçerlilik süresi (gün)',
        'premium_monthly_price' => 'Premium abonelik aylık ücreti (₺)',
        'min_load_price' => 'Asgari navlun bedeli (₺)',
    ];

    public string $activeTab = 'general';

    /** @var array<string, string> */
    public array $general = [];

    /** @var array<string, string> */
    public array $limits = [];

    public function mount(): void
    {
        abort_unless(auth()->user()->can('manage settings'), 403);
        $this->loadValues();
    }

    private function loadValues(): void
    {
        foreach (array_keys(self::GENERAL_KEYS) as $key) {
            $this->general[$key] = (string) CmsContent::getVal($key, '');
        }
        foreach (array_keys(Settings::DEFAULTS) as $key) {
            $this->limits[$key] = (string) Settings::get($key);
        }
    }

    private function normalizeLimit(string $key, string $raw): string
    {
        $raw = str_replace(',', '.', trim($raw));

        return in_array($key, ['delivery_auto_approval_hours', 'offer_validity_days'], true)
            ? (string) (int) $raw
            : number_format((float) $raw, 2, '.', '');
    }

    /** Değer değiştiyse ayarı yazar, revizyon ve denetim kaydı bırakır. */
    private function persist(string $key, string $label, ?string $new, ?string $old): bool
    {
        if ($old === $new) {
            return false;
        }

        Settings::set($key, $new, auth()->id());
        SettingRevision::create(['user_id' => auth()->id(), 'key' => $key, 'setting_label' => $label, 'old_value' => $old, 'new_value' => $new]);
        ActivityLog::record('setting.updated', "Ayar değişti: {$label} ({$key})", auth()->id(), null, ['key' => $key, 'old' => $old, 'new' => $new]);

        return true;
    }

    public function saveGeneral(): void
    {
        if (! auth()->user()?->can('manage settings')) {
            session()->flash('error_message', 'Bu işlem için yetkiniz yok.');

            return;
        }

        $this->validate([
            'general.system_site_title' => 'nullable|string|max:120',
            'general.system_meta_description' => 'nullable|string|max:320',
            'general.system_maintenance_note' => 'nullable|string|max:500',
        ]);

        $changed = 0;
        foreach (self::GENERAL_KEYS as $key => $label) {
            $value = trim((string) ($this->general[$key] ?? ''));
            $old = CmsContent::getVal($key);
            $changed += $this->persist($key, $label, $value === '' ? null : $value, $old === null ? null : (string) $old) ? 1 : 0;
        }

        $this->loadValues();
        session()->flash('success_message', $changed > 0 ? "{$changed} ayar güncellendi." : 'Değişiklik yok.');
    }

    public function saveLimits(): void
    {
        if (! auth()->user()?->can('manage settings')) {
            session()->flash('error_message', 'Bu işlem için yetkiniz yok.');

            return;
        }

        foreach ($this->limits as $key => $value) {
            $this->limits[$key] = str_replace(',', '.', trim((string) $value));
        }

        $this->validate([
            'limits.commission_standard_driver' => 'required|numeric|min:0|max:100',
            'limits.commission_discounted_premium' => 'required|numeric|min:0|max:100',
            'limits.commission_cargo_owner' => 'required|numeric|min:0|max:100',
            'limits.delivery_auto_approval_hours' => 'required|integer|min:1|max:720',
            'limits.offer_validity_days' => 'required|integer|min:1|max:60',
            'limits.premium_monthly_price' => 'required|numeric|min:0|max:1000000',
            'limits.min_load_price' => 'required|numeric|min:0|max:10000000',
        ]);

        $changed = 0;
        foreach (self::LIMIT_LABELS as $key => $label) {
            $value = $this->normalizeLimit($key, (string) ($this->limits[$key] ?? ''));
            $old = $this->normalizeLimit($key, (string) Settings::get($key));
            $changed += $this->persist($key, $label, $value, $old) ? 1 : 0;
        }

        $this->loadValues();
        session()->flash('success_message', $changed > 0 ? "{$changed} ayar güncellendi; yeni oranlar bundan sonraki işlemlerde geçerlidir." : 'Değişiklik yok.');
    }

    public function with(): array
    {
        $payments = app(PaymentService::class);
        $merchantId = (string) config('services.paytr.merchant_id');

        return [
            'generalKeys' => self::GENERAL_KEYS,
            'limitLabels' => self::LIMIT_LABELS,
            'defaults' => Settings::DEFAULTS,
            'paytr' => [
                'configured' => $payments->isConfigured(),
                'sandbox' => $payments->isSandbox(),
                'merchant_id' => $merchantId === '' ? 'Tanımlı değil' : str_repeat('*', max(0, strlen($merchantId) - 3)).substr($merchantId, -3),
                'key' => filled(config('services.paytr.merchant_key')) ? 'Tanımlı' : 'Tanımlı değil',
                'salt' => filled(config('services.paytr.merchant_salt')) ? 'Tanımlı' : 'Tanımlı değil',
                'callback' => route('payment.paytr.callback'),
            ],
            'revisions' => SettingRevision::query()->with('user')->latest('id')->limit(10)->get(),
        ];
    }
}; ?>

<div class="max-w-5xl mx-auto space-y-6">
    @php
        $input = 'w-full px-3 py-2 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/60 dark:border-neutral-700/40 text-neutral-900 dark:text-white text-xs rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500';
        $tabs = ['general' => 'Genel', 'limits' => 'Komisyon ve limitler', 'paytr' => 'PayTR'];
    @endphp

    @if (session()->has('success_message'))
        <div class="p-4 bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200/50 dark:border-emerald-800/30 text-emerald-600 dark:text-emerald-400 text-xs rounded-2xl">{{ session('success_message') }}</div>
    @endif

    <div>
        <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">Sistem Ayarları</h1>
        <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">Her değişiklik revizyon geçmişine yazılır ve Geri Yükleme sayfasından eski değere döndürülebilir.</p>
    </div>

    <div class="flex p-0.5 bg-neutral-100 dark:bg-neutral-900 rounded-xl">
        @foreach($tabs as $key => $label)
            <button type="button" wire:click="$set('activeTab', '{{ $key }}')" class="flex-1 px-4 py-2 text-xs font-semibold rounded-lg {{ $activeTab === $key ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">{{ $label }}</button>
        @endforeach
    </div>

    @if($activeTab === 'general')
        <form wire:submit="saveGeneral" class="apple-glass rounded-3xl p-6 space-y-4 text-xs">
            @foreach($generalKeys as $key => $label)
                <div>
                    <label class="text-[11px] font-semibold text-neutral-500">{{ $label }} <span class="font-mono text-neutral-400">({{ $key }})</span></label>
                    @if($key === 'system_site_title')
                        <input type="text" wire:model="general.{{ $key }}" class="{{ $input }}">
                    @else
                        <textarea wire:model="general.{{ $key }}" rows="3" class="{{ $input }}"></textarea>
                    @endif
                    @error('general.'.$key) <span class="text-red-500 text-[11px]">{{ $message }}</span> @enderror
                </div>
            @endforeach
            <p class="text-[11px] text-neutral-400">Bakım duyurusu doldurulduğunda ön yüzde gösterilmesi için ilgili şablonun bu anahtarı okuması gerekir; bu sürümde yalnız saklanır.</p>
            <button type="submit" wire:loading.attr="disabled" class="btn-apple-brand py-2.5 px-5 text-xs">Kaydet</button>
        </form>
    @endif

    @if($activeTab === 'limits')
        <form wire:submit="saveLimits" class="apple-glass rounded-3xl p-6 space-y-4 text-xs">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @foreach($limitLabels as $key => $label)
                    <div>
                        <label class="text-[11px] font-semibold text-neutral-500">{{ $label }} <span class="font-mono text-neutral-400">({{ $key }})</span></label>
                        <input type="text" inputmode="decimal" wire:model="limits.{{ $key }}" class="{{ $input }}">
                        <span class="text-[11px] text-neutral-400">Varsayılan: {{ $defaults[$key] }}</span>
                        @error('limits.'.$key) <span class="text-red-500 text-[11px] block">{{ $message }}</span> @enderror
                    </div>
                @endforeach
            </div>
            <button type="submit" wire:loading.attr="disabled" class="btn-apple-brand py-2.5 px-5 text-xs">Kaydet</button>
        </form>
    @endif

    @if($activeTab === 'paytr')
        <div class="apple-glass rounded-3xl p-6 space-y-4 text-xs">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div class="p-4 rounded-2xl bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/40 dark:border-neutral-700/40"><span class="text-neutral-400 block">Durum</span><span class="font-bold {{ $paytr['configured'] ? 'text-emerald-600' : 'text-amber-600' }}">{{ $paytr['configured'] ? 'Yapılandırıldı' : 'Eksik anahtar; ödeme alınamaz' }}</span></div>
                <div class="p-4 rounded-2xl bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/40 dark:border-neutral-700/40"><span class="text-neutral-400 block">Mod</span><span class="font-bold">{{ $paytr['sandbox'] ? 'Test (sandbox)' : 'Canlı' }}</span></div>
                <div class="p-4 rounded-2xl bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/40 dark:border-neutral-700/40"><span class="text-neutral-400 block">Merchant ID</span><span class="font-mono font-bold">{{ $paytr['merchant_id'] }}</span></div>
                <div class="p-4 rounded-2xl bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/40 dark:border-neutral-700/40"><span class="text-neutral-400 block">Merchant key / salt</span><span class="font-bold">{{ $paytr['key'] }} / {{ $paytr['salt'] }}</span></div>
            </div>
            <div class="p-4 rounded-2xl bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/40 dark:border-neutral-700/40">
                <span class="text-neutral-400 block">Bildirim (callback) adresi</span>
                <span class="font-mono break-all">{{ $paytr['callback'] }}</span>
            </div>
            <p class="text-[11px] text-neutral-400">Anahtarlar yalnız sunucudaki .env dosyasında (PAYTR_MERCHANT_ID, PAYTR_MERCHANT_KEY, PAYTR_MERCHANT_SALT, PAYTR_SANDBOX) tutulur; bu ekrandan değiştirilemez. Değişiklik sonrası yapılandırma önbelleğini yenileyin.</p>
        </div>
    @endif

    <section class="apple-glass rounded-3xl p-6">
        <h2 class="text-sm font-bold text-neutral-900 dark:text-white">Son revizyonlar</h2>
        <div class="responsive-scroll mt-3">
            <table class="w-full text-left text-xs">
                <thead><tr class="text-[11px] text-neutral-400 border-b border-neutral-100 dark:border-neutral-800/60"><th class="py-2 pr-4">Zaman</th><th class="py-2 pr-4">Ayar</th><th class="py-2 pr-4">Eski</th><th class="py-2 pr-4">Yeni</th><th class="py-2">Personel</th></tr></thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/40">
                    @forelse($revisions as $rev)
                        <tr>
                            <td class="py-2 pr-4 whitespace-nowrap text-neutral-500">{{ $rev->created_at?->format('d.m.Y H:i') }}</td>
                            <td class="py-2 pr-4">{{ $rev->setting_label }}</td>
                            <td class="py-2 pr-4 max-w-xs truncate">{{ $rev->old_value ?? '—' }}</td>
                            <td class="py-2 pr-4 max-w-xs truncate">{{ $rev->new_value ?? '—' }}</td>
                            <td class="py-2">{{ $rev->user?->full_name ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-6 text-center text-neutral-500">Henüz revizyon yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
