<?php

use App\Models\ActivityLog;
use App\Models\CmsContent;
use App\Models\SettingRevision;
use App\Services\PaymentService;
use App\Support\Company;
use App\Support\Settings;
use App\Models\UserNotification;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Artisan;
use Livewire\Volt\Component;

new class extends Component {
    public const GENERAL_KEYS = [
        'system_site_title' => 'Site başlığı',
        'system_meta_description' => 'Meta açıklaması',
        'system_maintenance_note' => 'Bakım duyurusu',
        'review_login_emails' => 'İnceleme (test) hesapları: e-postalar (virgülle)',
        'review_login_code' => 'İnceleme hesapları için sabit doğrulama kodu (6 hane)',
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
        'scraper_free_delay_minutes' => 'Premium öncelik süresi (dakika)',
        'scraper_auto_approve' => 'Otomatik onay',
        'scraper_auto_approve_require_price' => 'Otomatik onay için fiyat zorunlu',
        'scraper_auto_approve_require_weight' => 'Otomatik onay için tonaj zorunlu',
        'scraper_auto_approve_require_vehicle' => 'Otomatik onay için araç tipi zorunlu',
        'scraper_auto_approve_require_ai' => 'Otomatik onay için yapay zeka doğrulaması zorunlu',
        'scraper_auto_approve_min_confidence' => 'Otomatik onay için en düşük yapay zeka güveni (%)',
        'scraper_local_enabled' => 'Yerel öğrenen sınıflandırıcı',
        'scraper_local_min_confidence' => 'Yapay zeka ulaşılamazsa otomatik onay için en düşük yerel güven (%)',
        'telegram_post_enabled' => 'Sistem ilanlarını Telegram kanalına paylaş',
        'telegram_bot_token' => 'Telegram bot anahtarı',
        'telegram_channel_id' => 'Telegram kanal kimliği (@kanal veya -100...)',
        'scraper_rejected_retention_days' => 'Reddedilen adayların silinme süresi (gün)',
        'ai_parse_mode' => 'Yapay zeka çözümleme',
        'ai_provider' => 'Öncelikli sağlayıcı',
        'ai_gemini_model' => 'Gemini modeli',
        'ai_gemini_key' => 'Gemini API anahtarı',
        'ai_groq_model' => 'Groq modeli',
        'ai_groq_key' => 'Groq API anahtarı',
        'ai_cerebras_model' => 'Cerebras modeli',
        'ai_cerebras_key' => 'Cerebras API anahtarı',
        'ai_openrouter_model' => 'OpenRouter modeli',
        'ai_openrouter_key' => 'OpenRouter API anahtarı',
        'ai_mistral_model' => 'Mistral modeli',
        'ai_mistral_key' => 'Mistral API anahtarı',
        'ai_claude_model' => 'Claude modeli',
        'ai_claude_key' => 'Claude API anahtarı',
        'ai_openai_model' => 'OpenAI modeli',
        'ai_openai_key' => 'OpenAI API anahtarı',
        'ai_xai_model' => 'Grok modeli',
        'ai_xai_key' => 'xAI API anahtarı',
        'ai_kimi_model' => 'Kimi modeli',
        'ai_kimi_key' => 'Moonshot API anahtarı',
    ];

    public const AI_SECRET_KEYS = ['ai_gemini_key', 'ai_groq_key', 'ai_cerebras_key', 'ai_openrouter_key', 'ai_mistral_key', 'ai_claude_key', 'ai_openai_key', 'ai_xai_key', 'ai_kimi_key'];

    public const SCRAPER_TOGGLES = ['scraper_auto_approve', 'scraper_auto_approve_require_price', 'scraper_auto_approve_require_weight', 'scraper_auto_approve_require_vehicle', 'scraper_auto_approve_require_ai', 'scraper_local_enabled', 'telegram_post_enabled'];

    /** @var array<string, string> */
    public array $scraper = [];

    /** Şirket künyesi + ETBİS + KDV; künye anahtarları CmsContent'te company_* olarak saklanır. */
    public const COMPANY_EXTRA_KEYS = [
        'etbis_code' => 'ETBİS kodu',
        'payment_vat_rate' => 'KDV oranı (%)',
    ];

    /** @var array<string, string> */
    public array $company = [];

    public string $testEmail = '';

    public const MAIL_KEYS = [
        'mail_host' => 'SMTP sunucusu',
        'mail_port' => 'Port',
        'mail_encryption' => 'Şifreleme',
        'mail_username' => 'Kullanıcı adı (e-posta)',
        'mail_password' => 'Şifre',
        'mail_from_address' => 'Gönderici adresi',
        'mail_from_name' => 'Gönderici adı',
    ];

    /** @var array<string, string> */
    public array $mailForm = [];

    public const PAYMENT_KEYS = [
        'payment_provider' => 'Ödeme kuruluşu',
        'iyzico_api_key' => 'iyzico API anahtarı',
        'iyzico_secret_key' => 'iyzico gizli anahtar',
        'iyzico_sandbox' => 'iyzico test (sandbox) modu',
        'iyzico_marketplace' => 'iyzico pazaryeri (alt üye işyeri) ürünü aktif',
    ];

    /** @var array<string, string> */
    public array $paymentForm = [];

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
            $this->scraper[$key] = in_array($key, self::SCRAPER_TOGGLES, true) ? (Settings::bool($key) ? '1' : '0') : (in_array($key, self::AI_SECRET_KEYS, true) ? '' : (string) Settings::get($key));
        }
        foreach (array_keys(\App\Services\AiParserService::PROVIDERS) as $provider) {
            $this->scraper['ai_'.$provider.'_key_set'] = app(\App\Services\AiParserService::class)->apiKey($provider) !== '' ? '1' : '0';
        }
        foreach (array_keys(Company::LABELS) as $key) {
            $this->company[$key] = Company::get($key);
        }
        $this->company['etbis_code'] = (string) CmsContent::getVal('etbis_code', '');
        foreach (array_keys(self::MAIL_KEYS) as $key) {
            $this->mailForm[$key] = $key === 'mail_password' ? '' : (string) Settings::get($key);
        }
        $this->mailForm['mail_password_set'] = Settings::string('mail_password') !== '' ? '1' : '0';
        foreach (array_keys(self::PAYMENT_KEYS) as $key) {
            $this->paymentForm[$key] = in_array($key, ['iyzico_sandbox', 'iyzico_marketplace'], true)
                ? (Settings::bool($key) ? '1' : '0')
                : ($key === 'iyzico_secret_key' ? '' : (string) Settings::get($key));
        }
        $this->paymentForm['payment_provider'] = \App\Payments\GatewayManager::selectedId();
        $this->paymentForm['iyzico_secret_set'] = Settings::string('iyzico_secret_key') !== '' ? '1' : '0';
        $this->company['payment_vat_rate'] = number_format(Settings::float('payment_vat_rate'), 0, '.', '');
    }

    public function saveCompany(): void
    {
        if (! auth()->user()?->can('manage settings')) {
            session()->flash('error_message', 'Bu işlem için yetkiniz yok.');

            return;
        }

        foreach ($this->company as $key => $value) {
            $this->company[$key] = trim((string) $value);
        }
        $this->company['payment_vat_rate'] = str_replace(',', '.', $this->company['payment_vat_rate']);

        $this->validate([
            'company.name' => 'required|string|max:160',
            'company.address' => 'required|string|max:300',
            'company.phone' => 'required|string|max:40',
            'company.email' => 'required|email|max:120',
            'company.tax_office' => 'required|string|max:120',
            'company.tax_no' => ['required', 'regex:/^\d{10,11}$/'],
            'company.mersis_no' => ['nullable', 'regex:/^\d{16}$/'],
            'company.trade_registry_no' => 'nullable|string|max:20',
            'company.etbis_code' => 'nullable|string|max:60',
            'company.payment_vat_rate' => 'required|numeric|min:0|max:100',
        ], [
            'company.tax_no.regex' => 'Vergi numarası 10 haneli (VKN) ya da 11 haneli (TCKN) olmalıdır.',
            'company.mersis_no.regex' => 'MERSİS numarası 16 hanelidir.',
        ]);

        $changed = 0;
        foreach (Company::LABELS as $key => $label) {
            $value = $this->company[$key];
            $old = Company::stored($key);
            // Zaten geçerli olan (.env ya da koddaki varsayılan) değeri ayrıca saklamaya gerek yok.
            $new = $value === Company::fallback($key) && $old === '' ? '' : $value;
            $changed += $this->persist('company_'.$key, $label, $new === '' ? null : $new, $old === '' ? null : $old) ? 1 : 0;
        }
        $etbisOld = (string) CmsContent::getVal('etbis_code', '');
        $changed += $this->persist('etbis_code', 'ETBİS kodu', $this->company['etbis_code'] === '' ? null : $this->company['etbis_code'], $etbisOld === '' ? null : $etbisOld) ? 1 : 0;
        $vat = number_format((float) $this->company['payment_vat_rate'], 2, '.', '');
        $changed += $this->persist('payment_vat_rate', 'KDV oranı (%)', $vat, number_format(Settings::float('payment_vat_rate'), 2, '.', '')) ? 1 : 0;

        $this->loadValues();
        session()->flash('success_message', $changed > 0 ? "{$changed} alan güncellendi; altbilgi, iletişim sayfası ve sözleşmeler yeni künyeyi gösterir." : 'Değişiklik yok.');
    }

    /** SMTP ayarlarını panelden kaydeder; şifre boş bırakılırsa mevcut şifre korunur. */
    public function saveMail(): void
    {
        if (! auth()->user()?->can('manage settings')) {
            session()->flash('error_message', 'Bu işlem için yetkiniz yok.');

            return;
        }
        foreach (self::MAIL_KEYS as $key => $label) {
            $this->mailForm[$key] = trim((string) ($this->mailForm[$key] ?? ''));
        }
        $this->validate([
            'mailForm.mail_host' => 'required|string|max:190',
            'mailForm.mail_port' => 'required|integer|min:1|max:65535',
            'mailForm.mail_encryption' => 'required|in:tls,ssl,none',
            'mailForm.mail_username' => 'required|string|max:190',
            'mailForm.mail_password' => 'nullable|string|max:190',
            'mailForm.mail_from_address' => 'required|email|max:190',
            'mailForm.mail_from_name' => 'required|string|max:80',
        ]);

        $changed = 0;
        foreach (self::MAIL_KEYS as $key => $label) {
            $value = $this->mailForm[$key];
            if ($key === 'mail_password' && $value === '') {
                continue; // boş bırakıldı: mevcut şifre korunur
            }
            $old = (string) Settings::get($key);
            $changed += $this->persist($key, $label, $value, $old) ? 1 : 0;
        }

        $this->loadValues();
        \App\Support\RuntimeMailConfig::apply();
        session()->flash('success_message', $changed > 0 ? "{$changed} e-posta ayarı kaydedildi; aşağıdan deneme e-postası gönderin." : 'Değişiklik yok.');
    }

    /** Ödeme kuruluşu seçimi ve iyzico anahtarları (gizli anahtar boşsa mevcut korunur). */
    public function savePayment(): void
    {
        if (! auth()->user()?->can('manage settings')) {
            session()->flash('error_message', 'Bu işlem için yetkiniz yok.');

            return;
        }
        foreach (self::PAYMENT_KEYS as $key => $label) {
            $this->paymentForm[$key] = trim((string) ($this->paymentForm[$key] ?? ''));
        }
        $this->validate([
            'paymentForm.payment_provider' => 'required|in:paytr,iyzico',
            'paymentForm.iyzico_api_key' => 'nullable|string|max:190',
            'paymentForm.iyzico_secret_key' => 'nullable|string|max:190',
        ]);

        $changed = 0;
        foreach (self::PAYMENT_KEYS as $key => $label) {
            $value = $this->paymentForm[$key];
            if ($key === 'iyzico_secret_key' && $value === '') {
                continue;
            }
            if (in_array($key, ['iyzico_sandbox', 'iyzico_marketplace'], true)) {
                $value = $value === '1' ? '1' : '0';
                $old = Settings::bool($key) ? '1' : '0';
            } else {
                $old = (string) Settings::get($key);
            }
            $changed += $this->persist($key, $label, $value, $old) ? 1 : 0;
        }

        $this->loadValues();
        session()->flash('success_message', $changed > 0 ? "{$changed} ödeme ayarı kaydedildi." : 'Değişiklik yok.');
    }

    /** SMTP ve şablon doğrulaması: girilen adrese markalı deneme e-postası gönderir. */
    public function sendTestMail(): void
    {
        if (! auth()->user()?->can('manage settings')) {
            session()->flash('error_message', 'Bu işlem için yetkiniz yok.');

            return;
        }
        $this->testEmail = trim($this->testEmail) ?: (string) auth()->user()->email;
        $this->validate(['testEmail' => 'required|email|max:190']);

        $error = app(NotificationService::class)->sendTest($this->testEmail);
        ActivityLog::record('setting.mail_test', 'Deneme e-postası: '.$this->testEmail.($error ? ' (başarısız)' : ''), auth()->id(), null, ['error' => $error]);
        if ($error) {
            $this->addError('testEmail', 'Gönderim başarısız: '.mb_substr($error, 0, 300));

            return;
        }
        session()->flash('success_message', $this->testEmail.' adresine deneme e-postası gönderildi; gelen kutusunu (ve spam klasörünü) kontrol edin.');
    }

    /** Başarısız bildirim e-postalarını hemen yeniden dener. */
    public function retryFailedMail(): void
    {
        if (! auth()->user()?->can('manage settings')) {
            return;
        }
        $sent = app(NotificationService::class)->retryFailedMail(100);
        session()->flash('success_message', "{$sent} e-posta yeniden gönderildi.");
    }

    /** Beş yasal metni koddaki güncel şablonla yeniler (künye yer tutucuları panelden dolar). */
    public function refreshLegalTexts(): void
    {
        if (! auth()->user()?->can('manage settings')) {
            session()->flash('error_message', 'Bu işlem için yetkiniz yok.');

            return;
        }

        Artisan::call('legal:refresh');
        ActivityLog::record('setting.updated', 'Yasal metinler güncel şablonla yenilendi', auth()->id(), null, []);
        session()->flash('success_message', 'Yasal metinler güncel şablonla yenilendi; sözleşme sayfaları şirket künyesini panelden alıyor.');
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
            'general.review_login_emails' => ['nullable', 'string', 'max:500', 'regex:/^[^,\s]+@[^,\s]+(\s*,\s*[^,\s]+@[^,\s]+)*$/'],
            'general.review_login_code' => ['nullable', 'regex:/^\d{6}$/'],
        ], [
            'general.review_login_emails.regex' => 'E-postaları virgülle ayırarak yazın.',
            'general.review_login_code.regex' => 'Kod 6 haneli olmalıdır.',
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
            'scraper.scraper_rejected_retention_days' => 'required|integer|min:0|max:365',
            'scraper.scraper_auto_approve_min_confidence' => 'required|integer|min:0|max:100',
            'scraper.scraper_local_min_confidence' => 'required|integer|min:50|max:100',
            'scraper.ai_parse_mode' => 'required|in:off,fill_gaps,always',
            'scraper.ai_provider' => 'nullable|in:,'.implode(',', array_keys(\App\Services\AiParserService::PROVIDERS)),
            'scraper.ai_gemini_model' => 'nullable|string|max:120',
            'scraper.ai_groq_model' => 'nullable|string|max:120',
            'scraper.ai_cerebras_model' => 'nullable|string|max:120',
            'scraper.ai_openrouter_model' => 'nullable|string|max:120',
            'scraper.ai_mistral_model' => 'nullable|string|max:120',
            'scraper.ai_claude_model' => 'nullable|string|max:120',
            'scraper.ai_gemini_key' => 'nullable|string|max:200',
            'scraper.ai_groq_key' => 'nullable|string|max:200',
            'scraper.ai_cerebras_key' => 'nullable|string|max:200',
            'scraper.ai_openrouter_key' => 'nullable|string|max:200',
            'scraper.ai_mistral_key' => 'nullable|string|max:200',
            'scraper.ai_claude_key' => 'nullable|string|max:200',
            'scraper.ai_openai_model' => 'nullable|string|max:120',
            'scraper.ai_xai_model' => 'nullable|string|max:120',
            'scraper.ai_kimi_model' => 'nullable|string|max:120',
            'scraper.ai_openai_key' => 'nullable|string|max:200',
            'scraper.ai_xai_key' => 'nullable|string|max:200',
            'scraper.ai_kimi_key' => 'nullable|string|max:200',
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
            if (in_array($key, self::AI_SECRET_KEYS, true) && $value === '') {
                continue; // boş bırakıldı: kayıtlı anahtar korunur
            }
            if (in_array($key, self::SCRAPER_TOGGLES, true)) {
                $value = $value === '1' ? '1' : '0';
                $old = Settings::bool($key) ? '1' : '0';
            } elseif (in_array($key, ['scraper_free_delay_minutes', 'scraper_rejected_retention_days', 'scraper_auto_approve_min_confidence', 'scraper_local_min_confidence'], true)) {
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

    /** @var array<string, array{ok:bool, message:string}> */
    public array $aiTest = [];

    public function fetchAiModels(string $provider): void
    {
        $parser = app(\App\Services\AiParserService::class);
        $models = $parser->listModels($provider, refresh: true);
        \Illuminate\Support\Facades\Cache::forget('ai:auto_model:'.$provider);
        session()->flash($models === [] ? 'error_message' : 'success_message', $models === []
            ? (\App\Services\AiParserService::PROVIDERS[$provider]['label'] ?? $provider).': model listesi alınamadı; anahtarı ve "Bağlantıyı sına" sonucunu kontrol edin.'
            : count($models).' model listelendi; "Otomatik" seçeneği şu an '.$parser->autoModel($provider).' kullanır.');
    }

    public function testAiProvider(string $provider): void
    {
        $this->aiTest[$provider] = app(\App\Services\AiParserService::class)->testProvider($provider);
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
        $gateway = app(\App\Payments\GatewayManager::class)->selected();
        $checks = \App\Support\PaymentReadiness::checks();

        return [
            'scraperKeys' => self::SCRAPER_KEYS,
            'aiUsage' => \App\Models\AiProviderUsage::query()->whereDate('usage_date', now()->toDateString())->get()
                ->mapWithKeys(fn ($u) => [$u->provider => ['requests' => $u->request_count, 'failures' => $u->failure_count, 'quota' => $u->quota_exhausted && (! $u->quota_resets_at || $u->quota_resets_at->isFuture()), 'resets' => $u->quota_resets_at?->diffForHumans(null, true) ?? '']])->all(),
            'aiErrors' => app(\App\Services\AiParserService::class)->lastErrors(),
            'aiModelOptions' => collect(array_keys(\App\Services\AiParserService::PROVIDERS))->mapWithKeys(fn ($p) => [$p => app(\App\Services\AiParserService::class)->modelOptions($p)])->all(),
            'aiAuto' => collect(array_keys(\App\Services\AiParserService::PROVIDERS))->mapWithKeys(fn ($p) => [$p => (string) \Illuminate\Support\Facades\Cache::get('ai:auto_model:'.$p, '')])->all(),
            'aiLive' => collect(array_keys(\App\Services\AiParserService::PROVIDERS))->filter(fn ($p) => is_array(\Illuminate\Support\Facades\Cache::get('ai:models:'.$p)))->mapWithKeys(fn ($p) => [$p => count(\Illuminate\Support\Facades\Cache::get('ai:models:'.$p))])->all(),
            'generalKeys' => self::GENERAL_KEYS,
            'limitLabels' => self::LIMIT_LABELS,
            'defaults' => Settings::DEFAULTS,
            'companyLabels' => Company::LABELS,
            'mail' => [
                'mailer' => (string) config('mail.default'),
                'host' => (string) config('mail.mailers.smtp.host'),
                'port' => (string) config('mail.mailers.smtp.port'),
                'encryption' => (string) (config('mail.mailers.smtp.encryption') ?: config('mail.mailers.smtp.scheme') ?: 'tls'),
                'username' => (string) config('mail.mailers.smtp.username'),
                'from' => (string) config('mail.from.address'),
                'from_name' => (string) config('mail.from.name'),
                'source' => \App\Support\RuntimeMailConfig::source(),
                'stats' => NotificationService::mailStats(),
                'failed' => UserNotification::query()->with('user')->where('mail_status', UserNotification::MAIL_FAILED)->latest('id')->limit(10)->get(),
                'recent' => UserNotification::query()->with('user')->latest('id')->limit(12)->get(),
            ],
            'companyExtra' => self::COMPANY_EXTRA_KEYS,
            'payment' => [
                'provider' => $gateway->label(),
                'configured' => $payments->isConfigured(),
                'sandbox' => $payments->isSandbox(),
                'webhook' => route('payment.webhook', ['provider' => $gateway->id()]),
                'legacy_webhook' => route('payment.paytr.callback'),
                'marketplace' => $gateway->supportsSubMerchants(),
            ],
            'checks' => $checks,
            'checkSummary' => \App\Support\PaymentReadiness::summary($checks),
            'revisions' => SettingRevision::query()->with('user')->latest('id')->limit(10)->get(),
        ];
    }
}; ?>

<div class="max-w-5xl mx-auto space-y-6">
    @php
        $input = 'w-full px-3 py-2 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/60 dark:border-neutral-700/40 text-neutral-900 dark:text-white text-xs rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500';
        $tabs = ['general' => 'Genel', 'limits' => 'Komisyon ve limitler', 'scraper' => 'Dış kaynak ve Telegram', 'payment' => 'Ödeme altyapısı', 'mail' => 'E-posta ve bildirim'];
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
                <p class="text-[11px] text-neutral-400 mt-1">Açıkken her dakika çalışan görev, kriterleri sağlayan adayları kendiliğinden yayınlar: kaynak aktif, kalkış ve varış ili çözülmüş, telefon var, (zorunluysa) fiyat/tonaj/araç var ve yapay zeka doğrulaması geçmiş. Kapalıyken adaylar Dış Kaynak İlanları ekranında elle onaylanır; her satır neden kendiliğinden yayınlanmadığını yazar.</p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @foreach(['scraper_auto_approve', 'scraper_auto_approve_require_ai', 'scraper_auto_approve_require_price', 'scraper_auto_approve_require_weight', 'scraper_auto_approve_require_vehicle'] as $key)
                    <div>
                        <label class="form-label">{{ $scraperKeys[$key] }}</label>
                        <select wire:model="scraper.{{ $key }}" class="{{ $input }}"><option value="0">Kapalı</option><option value="1">Açık</option></select>
                        @error('scraper.'.$key) <span class="text-red-500 text-[11px] block">{{ $message }}</span> @enderror
                    </div>
                @endforeach
                <div>
                    <label class="form-label">{{ $scraperKeys['scraper_auto_approve_min_confidence'] }}</label>
                    <input type="number" min="0" max="100" wire:model="scraper.scraper_auto_approve_min_confidence" class="{{ $input }}">
                    <span class="text-[11px] text-neutral-400">Yapay zeka doğrulaması zorunluyken: aday ancak yapay zeka bakıp "yük ilanı" deyip bu güvenin üstünde puan verdiyse kendiliğinden yayınlanır; altındakiler ve kural/yapay zeka çelişenler kuyrukta elle kontrol bekler.</span>
                    @error('scraper.scraper_auto_approve_min_confidence') <span class="text-red-500 text-[11px] block">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="form-label">{{ $scraperKeys['scraper_local_enabled'] }}</label>
                    <select wire:model="scraper.scraper_local_enabled" class="{{ $input }}"><option value="0">Kapalı</option><option value="1">Açık</option></select>
                    <span class="text-[11px] text-neutral-400">Dış servise bağlı olmayan, sizin kararlarınızdan (yayınla / reddet / düzelt) öğrenen sınıflandırıcı ve jargon sözlüğü. Durumu ve sözlüğü Dış Kaynak İlanları → "Sözlük ve öğrenme" sekmesinde görürsünüz.</span>
                </div>
                <div>
                    <label class="form-label">{{ $scraperKeys['scraper_local_min_confidence'] }}</label>
                    <input type="number" min="50" max="100" wire:model="scraper.scraper_local_min_confidence" class="{{ $input }}">
                    <span class="text-[11px] text-neutral-400">Dış yapay zeka kotası dolduğunda ya da ulaşılamadığında: yerel sınıflandırıcı yeterince örnek görmüşse ve adaya bu güvenin üstünde "ilan" diyorsa aday yine kendiliğinden yayınlanır.</span>
                    @error('scraper.scraper_local_min_confidence') <span class="text-red-500 text-[11px] block">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="form-label">{{ $scraperKeys['scraper_free_delay_minutes'] }}</label>
                    <input type="number" min="0" max="1440" wire:model="scraper.scraper_free_delay_minutes" class="{{ $input }}">
                    <span class="text-[11px] text-neutral-400">Hem sistem hem dış kaynak ilanlar önce premium şoförlere açılır ve bildirilir; bu süre sonunda herkese açılır (sistem ilanları ayrıca Telegram kanalına gider).</span>
                    @error('scraper.scraper_free_delay_minutes') <span class="text-red-500 text-[11px] block">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="pt-4 border-t border-neutral-200 dark:border-neutral-800">
                <h3 class="section-title">Telegram kanalı</h3>
                <p class="text-[11px] text-neutral-400 mt-1">Kanala yalnız <strong>sistem ilanları</strong> (yük sahibi üyelerin açtığı ilanlar) gider; premium öncelik süresi dolup ilan herkese açıldığı anda paylaşılır. Dış kaynak ilanlar kanala gönderilmez. Kurulum adımları: docs/TELEGRAM_KANAL_KURULUM.md; bot kanala yönetici olarak eklenmiş olmalıdır.</p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="form-label">{{ $scraperKeys['telegram_post_enabled'] }}</label>
                    <select wire:model="scraper.telegram_post_enabled" class="{{ $input }}"><option value="0">Kapalı</option><option value="1">Açık</option></select>
                    @error('scraper.telegram_post_enabled') <span class="text-red-500 text-[11px] block">{{ $message }}</span> @enderror
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
            <div class="space-y-3 pt-3 border-t border-neutral-100 dark:border-neutral-800">
                <h3 class="section-title">Yapay zeka ile ilan çözümleme</h3>
                <p class="text-[11px] text-neutral-400">Kural tabanlı çözümleme her ilanda ücretsiz çalışır. Yapay zeka; yazım hatalı, dağınık ya da eksik ilanları anlar (il/ilçe, araç, tonaj, fiyat, yük türü, "ilan değil" ayrımı) ve kuralla çelişirse ilanı elle kontrole düşürür. <strong>"Her ilanda" kipinde telefon numarası olan her mesaj yapay zekaya gider; ilan mı sohbet mi kararını ve tüm alanları yapay zeka verir, kural yalnız yedektir.</strong> Anahtarı girilen sağlayıcılar sırayla denenir; ücretsizler önce, hız/kota sınırına takılan atlanır. Ücretsiz katmanlar için kart gerekmez; ChatGPT'nin ücretsiz uygulaması API sunmaz, OpenAI/Grok/Kimi anahtarları ücretlidir. Anahtarlar veritabanında şifreli tutulur.</p>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div><label class="form-label">{{ $scraperKeys['ai_parse_mode'] }}</label>
                        <select wire:model="scraper.ai_parse_mode" class="{{ $input }}">@foreach(\App\Services\AiParserService::MODES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                    </div>
                    <div><label class="form-label">{{ $scraperKeys['ai_provider'] }}</label>
                        <select wire:model="scraper.ai_provider" class="{{ $input }}"><option value="">Otomatik (ücretsizden başlayan sıra)</option>@foreach(\App\Services\AiParserService::visibleProviders() as $k => $p)<option value="{{ $k }}">{{ $p['label'] }}</option>@endforeach</select>
                    </div>
                    <div><label class="form-label">{{ $scraperKeys['scraper_rejected_retention_days'] }}</label><input type="number" min="0" max="365" wire:model="scraper.scraper_rejected_retention_days" class="{{ $input }}">@error('scraper.scraper_rejected_retention_days')<p class="text-rose-500 text-[11px] mt-1">{{ $message }}</p>@enderror</div>
                </div>
                <div class="space-y-2">
                    @foreach(\App\Services\AiParserService::visibleProviders() as $pk => $prov)
                        @php
                            $set = ($scraper['ai_'.$pk.'_key_set'] ?? '0') === '1';
                            $usage = $aiUsage[$pk] ?? null;
                        @endphp
                        <div class="p-3 rounded-2xl border {{ $set ? 'border-emerald-200/60 dark:border-emerald-900/40 bg-emerald-50/30 dark:bg-emerald-950/10' : 'border-neutral-200/40 dark:border-neutral-700/40 bg-neutral-50 dark:bg-neutral-900' }}">
                            <div class="flex flex-wrap items-center gap-2 mb-2">
                                <span class="font-bold text-neutral-900 dark:text-white">{{ $loop->iteration }}. {{ $prov['label'] }}</span>
                                <span class="badge {{ $set ? 'bg-emerald-500/10 text-emerald-600' : 'bg-neutral-100 dark:bg-neutral-800 text-neutral-500' }}">{{ $set ? 'anahtar kayıtlı' : 'anahtar yok' }}</span>
                                @if($usage)<span class="text-[11px] text-neutral-400">Bugün: {{ $usage['requests'] }} çağrı · {{ $usage['failures'] }} hata{{ $usage['quota'] ? ' · sınır aşıldı, '.$usage['resets'].' sonra yeniden denenir' : '' }}</span>@endif
                                @if($set)<button type="button" wire:click="testAiProvider('{{ $pk }}')" wire:loading.attr="disabled" class="btn-secondary py-1 px-2.5 text-[11px]">Bağlantıyı sına</button>@endif
                                <a href="{{ $prov['site'] }}" target="_blank" rel="noopener" class="text-[11px] text-brand-600 hover:underline ml-auto">Anahtar al →</a>
                            </div>
                            <p class="text-[11px] text-neutral-400 mb-2">{{ $prov['free'] }}</p>
                            @if(($aiTest[$pk] ?? null) !== null)
                                <p class="text-[11px] font-semibold mb-2 {{ $aiTest[$pk]['ok'] ? 'text-emerald-600' : 'text-rose-600' }}">{{ $aiTest[$pk]['ok'] ? 'Çalışıyor: ' : 'Hata: ' }}{{ $aiTest[$pk]['message'] }}</p>
                            @elseif(isset($aiErrors[$pk]))
                                <p class="text-[11px] text-rose-600 mb-2">Son hata ({{ \Illuminate\Support\Carbon::parse($aiErrors[$pk]['at'])->diffForHumans() }}): {{ \App\Services\AiParserService::humanizeError($aiErrors[$pk]['message']) }}</p>
                            @endif
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <div><label class="form-label">{{ $scraperKeys['ai_'.$pk.'_model'] }}</label>
                                    <select wire:model="scraper.ai_{{ $pk }}_model" class="{{ $input }}">
                                        <option value="">Otomatik{{ ($aiAuto[$pk] ?? '') !== '' ? ' (şu an: '.$aiAuto[$pk].')' : '' }}</option>
                                        @foreach($aiModelOptions[$pk] ?? $prov['models'] as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                                    </select>
                                    @if($set)<button type="button" wire:click="fetchAiModels('{{ $pk }}')" wire:loading.attr="disabled" class="mt-1 text-[11px] text-brand-600 font-semibold hover:underline">Modelleri getir</button> <span class="text-[11px] text-neutral-400">{{ isset($aiLive[$pk]) ? $aiLive[$pk].' model listelendi' : 'Güncel listeyi sağlayıcıdan çeker; "Otomatik" en uygun olanı seçer.' }}</span>@endif
                                </div>
                                <div class="sm:col-span-2"><label class="form-label">{{ $scraperKeys['ai_'.$pk.'_key'] }} {{ $set ? '(kayıtlı; değiştirmek için yazın)' : '' }}</label><input type="password" autocomplete="new-password" wire:model="scraper.ai_{{ $pk }}_key" class="{{ $input }} font-mono" placeholder="{{ $set ? '••••••••' : $prov['key_hint'] }}"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="flex flex-wrap gap-3">
                <button type="submit" wire:loading.attr="disabled" class="btn-apple-brand py-2.5 px-5 text-xs">Kaydet</button>
                <button type="button" wire:click="sendTelegramTest" wire:loading.attr="disabled" class="btn-apple-secondary py-2.5 px-5 text-xs">Kanala deneme mesajı gönder</button>
            </div>
        </form>
    @endif

    @if($activeTab === 'payment')
        <div class="apple-glass rounded-3xl p-6 space-y-4 text-xs">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="p-4 rounded-2xl bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/40 dark:border-neutral-700/40"><span class="text-neutral-400 block">Sağlayıcı</span><span class="font-bold">{{ $payment['provider'] }}</span></div>
                <div class="p-4 rounded-2xl bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/40 dark:border-neutral-700/40"><span class="text-neutral-400 block">Durum</span><span class="font-bold {{ $payment['configured'] ? 'text-emerald-600' : 'text-amber-600' }}">{{ $payment['configured'] ? ($payment['sandbox'] ? 'Etkin · test modu' : 'Etkin · canlı') : 'Anahtarlar tanımlı değil' }}</span></div>
                <div class="p-4 rounded-2xl bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/40 dark:border-neutral-700/40"><span class="text-neutral-400 block">Şoför ödemeleri</span><span class="font-bold">{{ $payment['marketplace'] ? 'Ödeme kuruluşu üzerinden (pazaryeri)' : 'Finans ekibi banka transferi' }}</span></div>
            </div>
            <div class="p-4 rounded-2xl bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/40 dark:border-neutral-700/40 space-y-1">
                <span class="text-neutral-400 block">Sağlayıcı paneline yazılacak sunucu bildirimi (webhook) adresi</span>
                <span class="font-mono break-all">{{ $payment['webhook'] }}</span>
                <span class="text-[11px] text-neutral-400 block">Eski adres de çalışır: {{ $payment['legacy_webhook'] }}</span>
            </div>
            <form wire:submit="savePayment" class="space-y-3 pt-2 border-t border-neutral-100 dark:border-neutral-800">
                <h3 class="text-xs font-bold text-neutral-900 dark:text-white">Ödeme kuruluşu ve anahtarlar</h3>
                <p class="text-[11px] text-neutral-400">iyzico anahtarları iyzico üye işyeri panelinde Ayarlar → API anahtarları bölümündedir. Sözleşme öncesi sandbox anahtarlarıyla test modunda deneyin; canlıya geçerken canlı anahtarları girip test modunu kapatın.</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div><label class="form-label">Ödeme kuruluşu</label>
                        <select wire:model="paymentForm.payment_provider" class="{{ $input }}"><option value="iyzico">iyzico (Ödeme Formu)</option><option value="paytr">PayTR (iFrame, anahtarlar .env)</option></select>
                    </div>
                    <div><label class="form-label">iyzico API anahtarı</label><input type="text" wire:model="paymentForm.iyzico_api_key" class="{{ $input }} font-mono" placeholder="sandbox-… ya da canlı anahtar"></div>
                    <div><label class="form-label">iyzico gizli anahtar {{ ($paymentForm['iyzico_secret_set'] ?? '0') === '1' ? '(kayıtlı; değiştirmek için yazın)' : '' }}</label><input type="password" autocomplete="new-password" wire:model="paymentForm.iyzico_secret_key" class="{{ $input }} font-mono" placeholder="{{ ($paymentForm['iyzico_secret_set'] ?? '0') === '1' ? '••••••••' : 'secret key' }}"></div>
                    <div class="space-y-2 pt-5">
                        <label class="flex items-center gap-2 text-xs"><input type="checkbox" wire:model="paymentForm.iyzico_sandbox" value="1" class="rounded"> Test (sandbox) modu</label>
                        <label class="flex items-center gap-2 text-xs"><input type="checkbox" wire:model="paymentForm.iyzico_marketplace" value="1" class="rounded"> Pazaryeri ürünü aktif (şoför ödemeleri iyzico üzerinden)</label>
                    </div>
                </div>
                <button type="submit" wire:loading.attr="disabled" class="btn-apple-brand py-2.5 px-5 text-xs">Ödeme ayarlarını kaydet</button>
            </form>
        </div>

        <form wire:submit="saveCompany" class="apple-glass rounded-3xl p-6 space-y-4 text-xs">
            <div>
                <h2 class="text-sm font-bold text-neutral-900 dark:text-white">Şirket künyesi</h2>
                <p class="text-[11px] text-neutral-400 mt-1">Altbilgi, iletişim sayfası, e-postalar ve beş yasal metin bu bilgileri kullanır. Boş bırakılan alan koddaki varsayılana döner; sunucuda dosya düzenlemek gerekmez.</p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                @foreach($companyLabels as $key => $label)
                    <div class="{{ in_array($key, ['name', 'address'], true) ? 'sm:col-span-2' : '' }}">
                        <label class="form-label">{{ $label }}</label>
                        <input type="text" wire:model="company.{{ $key }}" class="{{ $input }}" placeholder="{{ $key === 'mersis_no' ? '16 haneli MERSİS numarası' : '' }}">
                        @error('company.'.$key)<p class="text-rose-500 text-[11px] mt-1">{{ $message }}</p>@enderror
                    </div>
                @endforeach
                @foreach($companyExtra as $key => $label)
                    <div>
                        <label class="form-label">{{ $label }}</label>
                        <input type="text" wire:model="company.{{ $key }}" class="{{ $input }}" placeholder="{{ $key === 'etbis_code' ? 'etbis.ticaret.gov.tr kaydından sonra' : '' }}">
                        @error('company.'.$key)<p class="text-rose-500 text-[11px] mt-1">{{ $message }}</p>@enderror
                    </div>
                @endforeach
            </div>
            <div class="flex flex-wrap gap-3 items-center">
                <button type="submit" wire:loading.attr="disabled" class="btn-apple-brand py-2.5 px-5 text-xs">Kaydet</button>
                <button type="button" wire:click="refreshLegalTexts" wire:confirm="Beş yasal metin koddaki güncel şablonla değiştirilecek; İçerik ve CMS'den yapılmış el düzenlemeleri silinir. Devam edilsin mi?" wire:loading.attr="disabled" class="btn-apple-secondary py-2.5 px-5 text-xs">Yasal metinleri güncel şablonla yenile</button>
            </div>
        </form>

        <div class="apple-glass rounded-3xl p-6 space-y-4 text-xs">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-sm font-bold text-neutral-900 dark:text-white">Ödeme kuruluşu başvurusu hazırlık listesi</h2>
                <span class="badge {{ $checkSummary['ok'] === $checkSummary['total'] ? 'bg-emerald-500/10 text-emerald-600' : 'bg-amber-500/10 text-amber-600' }}">{{ $checkSummary['ok'] }} / {{ $checkSummary['total'] }} hazır</span>
            </div>
            @foreach(collect($checks)->groupBy('group') as $group => $items)
                <div class="space-y-1.5">
                    <div class="text-[11px] font-bold uppercase tracking-wider text-neutral-400">{{ $group }}</div>
                    @foreach($items as $c)
                        <div class="flex items-start gap-3 p-3 rounded-xl border {{ $c['ok'] ? 'border-neutral-200/60 dark:border-neutral-800' : 'border-amber-500/30 bg-amber-500/5' }}">
                            <span class="mt-0.5 w-4 h-4 rounded-full flex items-center justify-center text-white text-[10px] font-bold {{ $c['ok'] ? 'bg-emerald-500' : 'bg-amber-500' }}">{{ $c['ok'] ? '✓' : '!' }}</span>
                            <div class="flex-1 min-w-0">
                                <div class="font-semibold text-neutral-900 dark:text-white">{{ $c['label'] }} <span class="font-normal text-neutral-500">· {{ $c['detail'] }}</span></div>
                                @if($c['fix'])<div class="text-[11px] text-amber-700 dark:text-amber-300 mt-0.5">{{ $c['fix'] }}</div>@endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
    @endif

    @if($activeTab === 'mail')
        @php $mailOk = $mail['mailer'] === 'smtp' && $mail['host'] !== '' && $mail['from'] !== '' && ! str_contains($mail['from'], 'example'); @endphp
        <div class="apple-glass rounded-3xl p-6 space-y-4 text-xs">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-sm font-bold text-neutral-900 dark:text-white">Gönderim altyapısı</h2>
                <span class="badge {{ $mailOk ? 'bg-emerald-500/10 text-emerald-600' : 'bg-amber-500/10 text-amber-600' }}">{{ $mailOk ? 'SMTP tanımlı' : 'SMTP eksik' }}</span>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div class="p-4 rounded-2xl bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/40 dark:border-neutral-700/40"><span class="text-neutral-400 block">Yöntem</span><span class="font-bold">{{ $mail['mailer'] }}</span></div>
                <div class="p-4 rounded-2xl bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/40 dark:border-neutral-700/40"><span class="text-neutral-400 block">SMTP sunucusu</span><span class="font-bold break-all">{{ $mail['host'] ?: '—' }}{{ $mail['host'] ? ':'.$mail['port'] : '' }}</span></div>
                <div class="p-4 rounded-2xl bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/40 dark:border-neutral-700/40"><span class="text-neutral-400 block">Gönderici</span><span class="font-bold break-all">{{ $mail['from'] ?: '—' }}</span><span class="text-[11px] text-neutral-400 block">{{ $mail['from_name'] }}</span></div>
                <div class="p-4 rounded-2xl bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/40 dark:border-neutral-700/40"><span class="text-neutral-400 block">Son 24 saat</span><span class="font-bold text-emerald-600">{{ $mail['stats']['sent'] }} gönderildi</span><span class="text-[11px] block {{ $mail['stats']['failed'] ? 'text-rose-500 font-semibold' : 'text-neutral-400' }}">{{ $mail['stats']['failed'] }} başarısız bekliyor</span></div>
            </div>
            <p class="text-[11px] text-neutral-400 leading-relaxed">Gönderici adresi navluniq.com alan adında olmalı ve alan adında SPF, DKIM ve DMARC kayıtları tanımlı olmalıdır (docs/EPOSTA_VE_BILDIRIM.md). Doğrulama kodları anında gönderilir; diğer bildirimler uygulama içine yazılır, e-posta hata verirse 10 dakikada bir en fazla 4 kez yeniden denenir.</p>
            <form wire:submit="saveMail" class="space-y-3 pt-2 border-t border-neutral-100 dark:border-neutral-800">
                <div class="flex items-center justify-between gap-3">
                    <h3 class="text-xs font-bold text-neutral-900 dark:text-white">SMTP ayarları (panelden)</h3>
                    <span class="badge {{ $mail['source'] === 'panel' ? 'bg-emerald-500/10 text-emerald-600' : 'bg-neutral-100 dark:bg-neutral-800 text-neutral-500' }}">{{ $mail['source'] === 'panel' ? 'Panel ayarı kullanılıyor' : 'Sunucu .env kullanılıyor' }}</span>
                </div>
                <p class="text-[11px] text-neutral-400">Natro Kurumsal Posta için hazır: mail.kurumsaleposta.com, 587, TLS, info@navluniq.com. Yalnız posta kutusu şifresini girip kaydedin; sunucuda dosya düzenlemek gerekmez.</p>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div class="sm:col-span-1"><label class="form-label">SMTP sunucusu</label><input type="text" wire:model="mailForm.mail_host" class="{{ $input }}">@error('mailForm.mail_host')<p class="text-rose-500 text-[11px] mt-1">{{ $message }}</p>@enderror</div>
                    <div><label class="form-label">Port</label><input type="text" wire:model="mailForm.mail_port" class="{{ $input }}">@error('mailForm.mail_port')<p class="text-rose-500 text-[11px] mt-1">{{ $message }}</p>@enderror</div>
                    <div><label class="form-label">Şifreleme</label>
                        <select wire:model="mailForm.mail_encryption" class="{{ $input }}"><option value="tls">TLS / STARTTLS (587)</option><option value="ssl">SSL (465)</option><option value="none">Yok</option></select>
                    </div>
                    <div><label class="form-label">Kullanıcı adı (e-posta)</label><input type="text" wire:model="mailForm.mail_username" class="{{ $input }}">@error('mailForm.mail_username')<p class="text-rose-500 text-[11px] mt-1">{{ $message }}</p>@enderror</div>
                    <div><label class="form-label">Şifre {{ ($mailForm['mail_password_set'] ?? '0') === '1' ? '(kayıtlı; değiştirmek için yazın)' : '' }}</label><input type="password" autocomplete="new-password" wire:model="mailForm.mail_password" class="{{ $input }}" placeholder="{{ ($mailForm['mail_password_set'] ?? '0') === '1' ? '••••••••' : 'Posta kutusu şifresi' }}"></div>
                    <div><label class="form-label">Gönderici adı</label><input type="text" wire:model="mailForm.mail_from_name" class="{{ $input }}"></div>
                    <div class="sm:col-span-3"><label class="form-label">Gönderici adresi</label><input type="email" wire:model="mailForm.mail_from_address" class="{{ $input }}">@error('mailForm.mail_from_address')<p class="text-rose-500 text-[11px] mt-1">{{ $message }}</p>@enderror</div>
                </div>
                <button type="submit" wire:loading.attr="disabled" class="btn-apple-brand py-2.5 px-5 text-xs">SMTP ayarlarını kaydet</button>
            </form>
            <form wire:submit="sendTestMail" class="flex flex-col sm:flex-row gap-3 sm:items-end">
                <div class="flex-1">
                    <label class="form-label">Deneme e-postası gönder</label>
                    <input type="email" wire:model="testEmail" class="{{ $input }}" placeholder="{{ auth()->user()->email }}">
                    @error('testEmail')<p class="text-rose-500 text-[11px] mt-1">{{ $message }}</p>@enderror
                </div>
                <button type="submit" wire:loading.attr="disabled" class="btn-apple-brand py-2.5 px-5 text-xs">Gönder</button>
                @if($mail['stats']['failed'] > 0)
                    <button type="button" wire:click="retryFailedMail" wire:loading.attr="disabled" class="btn-apple-secondary py-2.5 px-5 text-xs">Başarısızları yeniden dene</button>
                @endif
            </form>
        </div>

        <div class="apple-glass rounded-3xl p-6 space-y-3 text-xs">
            <h2 class="text-sm font-bold text-neutral-900 dark:text-white">Hangi olayda kime ne gönderiliyor</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-1 text-[11px] text-neutral-600 dark:text-neutral-300">
                @foreach([
                    'Kayıt doğrulandı' => 'Hoş geldiniz + ilk adımlar (şoför: belgeler, yük sahibi: ilan)',
                    'Giriş / rol değişimi / kayıt' => 'Tek kullanımlık doğrulama kodu (5 dk)',
                    'Şifremi unuttum' => 'Markalı sıfırlama bağlantısı (60 dk); değişince güvenlik uyarısı',
                    'Belgeler yüklendi' => 'Kullanıcıya "alındı"; KYC ekibine "inceleme bekliyor"',
                    'Belge onay / ret' => 'Kullanıcıya sonuç ve gerekçe',
                    'Yeni teklif' => 'Yük sahibine',
                    'Teklif kabul / ret / süre doldu / geri çekildi' => 'Şoföre (kabul edilmeyenler dahil); geri çekme yük sahibine',
                    'İlan iptali' => 'Teklif vermiş tüm şoförlere',
                    'Navlun ödemesi alındı' => 'Yük sahibine ve şoföre',
                    'Yola çıktı / teslim kanıtı / onay' => 'Karşı tarafa; otomatik onayda yük sahibine de',
                    'Hakediş ödendi / yapılamadı' => 'Şoföre; aktarım hatası finans ekibine',
                    'Uyuşmazlık açıldı / savunma / karar' => 'Taraflara ve hakem ekibine',
                    'Destek talebi' => 'Talep sahibine onay; destek ekibine bildirim; yanıt talep sahibine',
                    'Premium' => 'Etkinleşti; bitişe 3 gün kala hatırlatma; sona erdi',
                    'Değerlendirme' => 'Değerlendirilen tarafa',
                    'Hesap kapatma' => 'Kapatma onayı',
                ] as $event => $who)
                    <div class="flex gap-2 py-1 border-b border-neutral-100 dark:border-neutral-800/60"><span class="font-semibold text-neutral-800 dark:text-neutral-100 w-56 shrink-0">{{ $event }}</span><span>{{ $who }}</span></div>
                @endforeach
            </div>
        </div>

        <div class="apple-glass rounded-3xl p-6 space-y-3 text-xs">
            <h2 class="text-sm font-bold text-neutral-900 dark:text-white">Son bildirimler</h2>
            <div class="responsive-scroll">
                <table class="w-full text-left text-xs">
                    <thead><tr class="text-[11px] text-neutral-400 border-b border-neutral-100 dark:border-neutral-800/60"><th class="py-2 pr-4">Zaman</th><th class="py-2 pr-4">Kime</th><th class="py-2 pr-4">Başlık</th><th class="py-2 pr-4">Tür</th><th class="py-2 pr-4">E-posta</th><th class="py-2">Okundu</th></tr></thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/40">
                        @forelse($mail['recent'] as $n)
                            <tr>
                                <td class="py-2 pr-4 whitespace-nowrap text-neutral-500">{{ $n->created_at->format('d.m H:i') }}</td>
                                <td class="py-2 pr-4 max-w-[10rem] truncate">{{ $n->user?->full_name ?? '—' }}</td>
                                <td class="py-2 pr-4 max-w-xs truncate">{{ $n->title }}</td>
                                <td class="py-2 pr-4 text-neutral-500">{{ $n->typeLabel() }}</td>
                                <td class="py-2 pr-4"><span class="badge {{ $n->mail_status === 'sent' ? 'bg-emerald-500/10 text-emerald-600' : ($n->mail_status === 'failed' ? 'bg-rose-500/10 text-rose-600' : 'bg-neutral-100 dark:bg-neutral-800 text-neutral-500') }}" title="{{ $n->mail_error }}">{{ $n->mailStatusLabel() }}</span></td>
                                <td class="py-2 text-neutral-500">{{ $n->read_at ? $n->read_at->format('d.m H:i') : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-6 text-center text-neutral-500">Henüz bildirim yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($mail['failed']->isNotEmpty())
                <div class="rounded-xl border border-rose-500/30 bg-rose-500/5 p-3 space-y-1">
                    <div class="font-semibold text-rose-700 dark:text-rose-300">Başarısız e-postalar (son 10)</div>
                    @foreach($mail['failed'] as $f)
                        <div class="text-[11px] text-neutral-600 dark:text-neutral-300">{{ $f->created_at->format('d.m H:i') }} · {{ $f->user?->email }} · {{ $f->title }} · deneme {{ $f->mail_attempts }}/{{ \App\Models\UserNotification::MAX_MAIL_ATTEMPTS }} · <span class="text-rose-600">{{ mb_substr((string) $f->mail_error, 0, 140) }}</span></div>
                    @endforeach
                </div>
            @endif
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
