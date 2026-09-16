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

    public const SCRAPER_KEYS = [
        'scraper_free_delay_minutes' => 'Ücretsiz üyelere açılma gecikmesi (dakika)',
        'scraper_auto_approve' => 'Otomatik onay',
        'scraper_auto_approve_require_price' => 'Otomatik onay için fiyat zorunlu',
        'scraper_auto_approve_require_weight' => 'Otomatik onay için tonaj zorunlu',
        'telegram_post_enabled' => 'Telegram kanalına paylaş',
        'telegram_bot_token' => 'Telegram bot anahtarı',
        'telegram_channel_id' => 'Telegram kanal kimliği (@kanal veya -100...)',
        'telegram_show_full_phone' => 'Telegram mesajında tam numara',
    ];

    public const SCRAPER_TOGGLES = ['scraper_auto_approve', 'scraper_auto_approve_require_price', 'scraper_auto_approve_require_weight', 'telegram_post_enabled', 'telegram_show_full_phone'];

    /** @var array<string, string> */
    public array $scraper = [];

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
        foreach (array_keys(self::LIMIT_LABELS) as $key) {
            $this->limits[$key] = (string) Settings::get($key);
        }
        foreach (array_keys(self::SCRAPER_KEYS) as $key) {
            $this->scraper[$key] = in_array($key, self::SCRAPER_TOGGLES, true) ? (Settings::bool($key) ? '1' : '0') : (string) Settings::get($key);
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
        // Gizli anahtarlar revizyon ve denetim kaydına maskelenmiş yazılır.
        $mask = fn (?string $v) => $v === null || $v === '' ? $v : '••••'.substr($v, -4);
        $logOld = in_array($key, Settings::SECRET_KEYS, true) ? $mask($old) : $old;
        $logNew = in_array($key, Settings::SECRET_KEYS, true) ? $mask($new) : $new;
        SettingRevision::create(['user_id' => auth()->id(), 'key' => $key, 'setting_label' => $label, 'old_value' => $logOld, 'new_value' => $logNew]);
        ActivityLog::record('setting.updated', "Ayar değişti: {$label} ({$key})", auth()->id(), null, ['key' => $key, 'old' => $logOld, 'new' => $logNew]);

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

    public function saveScraper(): void
    {
        if (! auth()->user()?->can('manage settings')) {
            session()->flash('error_message', 'Bu işlem için yetkiniz yok.');

            return;
        }

        $this->validate([
            'scraper.scraper_free_delay_minutes' => 'required|integer|min:0|max:1440',
            'scraper.telegram_bot_token' => ['nullable', 'string', 'max:120', 'regex:/^\d+:[A-Za-z0-9_-]+$/'],
            'scraper.telegram_channel_id' => ['nullable', 'string', 'max:120', 'regex:/^(@[A-Za-z0-9_]{4,}|-?\d+)$/'],
        ], [
            'scraper.telegram_bot_token.regex' => 'Bot anahtarı "123456789:AA..." biçiminde olmalıdır.',
            'scraper.telegram_channel_id.regex' => 'Kanal kimliği "@kanaladi" ya da "-100..." biçiminde olmalıdır.',
        ]);

        if ($this->scraper['telegram_post_enabled'] === '1' && (trim($this->scraper['telegram_bot_token']) === '' || trim($this->scraper['telegram_channel_id']) === '')) {
            $this->addError('scraper.telegram_post_enabled', 'Paylaşımı açmak için bot anahtarı ve kanal kimliği gerekir.');

            return;
        }

        $changed = 0;
        foreach (self::SCRAPER_KEYS as $key => $label) {
            $value = trim((string) ($this->scraper[$key] ?? ''));
            if (in_array($key, self::SCRAPER_TOGGLES, true)) {
                $value = $value === '1' ? '1' : '0';
                $old = Settings::bool($key) ? '1' : '0';
            } elseif ($key === 'scraper_free_delay_minutes') {
                $value = (string) (int) $value;
                $old = (string) Settings::int($key);
            } else {
                $old = (string) Settings::get($key);
            }
            $changed += $this->persist($key, $label, $value, $old) ? 1 : 0;
        }

        $this->loadValues();
        session()->flash('success_message', $changed > 0 ? "{$changed} ayar güncellendi." : 'Değişiklik yok.');
    }

    public function sendTelegramTest(): void
    {
        if (! auth()->user()?->can('manage settings')) {
            return;
        }
        try {
            app(\App\Services\TelegramPublisher::class)->send('✅ NavlunIQ Telegram bağlantısı çalışıyor. İlanlar bu kanala düşecek.');
            session()->flash('success_message', 'Deneme mesajı kanala gönderildi.');
        } catch (\Throwable $e) {
            session()->flash('error_message', 'Telegram gönderimi başarısız: '.$e->getMessage());
        }
    }

    public function with(): array
    {
        $payments = app(PaymentService::class);
        $merchantId = (string) config('services.paytr.merchant_id');

        return [
            'scraperKeys' => self::SCRAPER_KEYS,
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
        $tabs = ['general' => 'Genel', 'limits' => 'Komisyon ve limitler', 'scraper' => 'Dış kaynak ve Telegram', 'paytr' => 'PayTR'];
    @endphp

    @if (session()->has('success_message'))
        <div class="p-4 bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200/50 dark:border-emerald-800/30 text-emerald-600 dark:text-emerald-400 text-xs rounded-2xl">{{ session('success_message') }}</div>
    @endif

    <div>
        <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">Sistem Ayarları</h1>
        <p class="page-subtitle">Her değişiklik revizyon geçmişine yazılır ve Geri Yükleme sayfasından eski değere döndürülebilir.</p>
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
                    <label class="form-label">{{ $label }} <span class="font-mono text-neutral-400">({{ $key }})</span></label>
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
                        <label class="form-label">{{ $label }} <span class="font-mono text-neutral-400">({{ $key }})</span></label>
                        <input type="text" inputmode="decimal" wire:model="limits.{{ $key }}" class="{{ $input }}">
                        <span class="text-[11px] text-neutral-400">Varsayılan: {{ $defaults[$key] }}</span>
                        @error('limits.'.$key) <span class="text-red-500 text-[11px] block">{{ $message }}</span> @enderror
                    </div>
                @endforeach
            </div>
            <button type="submit" wire:loading.attr="disabled" class="btn-apple-brand py-2.5 px-5 text-xs">Kaydet</button>
        </form>
    @endif

    @if($activeTab === 'scraper')
        <form wire:submit="saveScraper" class="apple-glass rounded-3xl p-6 space-y-5 text-xs">
            @if (session()->has('error_message'))
                <div class="p-3 bg-red-50 dark:bg-red-950/20 border border-red-200/50 dark:border-red-800/30 text-red-600 dark:text-red-400 rounded-xl">{{ session('error_message') }}</div>
            @endif
            <div>
                <h3 class="section-title">Otomatik onay</h3>
                <p class="text-[11px] text-neutral-400 mt-1">Açıkken her dakika çalışan görev, kriterleri sağlayan adayları kendiliğinden yayınlar. Kapalıyken adaylar Dış Kaynak İlanları ekranında elle onaylanır.</p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @foreach(['scraper_auto_approve', 'scraper_auto_approve_require_price', 'scraper_auto_approve_require_weight'] as $key)
                    <div>
                        <label class="form-label">{{ $scraperKeys[$key] }}</label>
                        <select wire:model="scraper.{{ $key }}" class="{{ $input }}"><option value="0">Kapalı</option><option value="1">Açık</option></select>
                        @error('scraper.'.$key) <span class="text-red-500 text-[11px] block">{{ $message }}</span> @enderror
                    </div>
                @endforeach
                <div>
                    <label class="form-label">{{ $scraperKeys['scraper_free_delay_minutes'] }}</label>
                    <input type="number" min="0" max="1440" wire:model="scraper.scraper_free_delay_minutes" class="{{ $input }}">
                    <span class="text-[11px] text-neutral-400">Premium şoförler ilanı anında görür; bu süre sonunda herkese ve Telegram'a açılır.</span>
                    @error('scraper.scraper_free_delay_minutes') <span class="text-red-500 text-[11px] block">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="pt-4 border-t border-neutral-200 dark:border-neutral-800">
                <h3 class="section-title">Telegram kanalı</h3>
                <p class="text-[11px] text-neutral-400 mt-1">Kurulum adımları: docs/TELEGRAM_KANAL_KURULUM.md. Bot, kanala yönetici olarak eklenmiş olmalıdır.</p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="form-label">{{ $scraperKeys['telegram_post_enabled'] }}</label>
                    <select wire:model="scraper.telegram_post_enabled" class="{{ $input }}"><option value="0">Kapalı</option><option value="1">Açık</option></select>
                    @error('scraper.telegram_post_enabled') <span class="text-red-500 text-[11px] block">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="form-label">{{ $scraperKeys['telegram_show_full_phone'] }}</label>
                    <select wire:model="scraper.telegram_show_full_phone" class="{{ $input }}"><option value="0">Maskeli numara + siteye bağlantı</option><option value="1">Tam numara</option></select>
                </div>
                <div>
                    <label class="form-label">{{ $scraperKeys['telegram_bot_token'] }}</label>
                    <input type="password" autocomplete="off" wire:model="scraper.telegram_bot_token" class="{{ $input }} font-mono" placeholder="123456789:AA...">
                    @error('scraper.telegram_bot_token') <span class="text-red-500 text-[11px] block">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="form-label">{{ $scraperKeys['telegram_channel_id'] }}</label>
                    <input type="text" wire:model="scraper.telegram_channel_id" class="{{ $input }} font-mono" placeholder="@navluniq">
                    @error('scraper.telegram_channel_id') <span class="text-red-500 text-[11px] block">{{ $message }}</span> @enderror
                </div>
            </div>
            <div class="flex flex-wrap gap-3">
                <button type="submit" wire:loading.attr="disabled" class="btn-apple-brand py-2.5 px-5 text-xs">Kaydet</button>
                <button type="button" wire:click="sendTelegramTest" wire:loading.attr="disabled" class="btn-apple-secondary py-2.5 px-5 text-xs">Kanala deneme mesajı gönder</button>
            </div>
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
