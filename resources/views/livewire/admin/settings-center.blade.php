<?php

use Livewire\Volt\Component;
use App\Models\CmsContent;

new class extends Component {
    // Sekme Kontrolü
    public string $activeTab = 'general'; // 'general', 'paytr', 'commission', 'notifications', 'templates'

    // Genel & SEO Ayarları Formu
    public string $siteTitle = '';
    public string $metaDescription = '';
    public bool $maintenanceMode = false;
    public string $defaultCurrency = 'TRY';
    public string $defaultTimezone = 'Europe/Istanbul';

    // PayTR Escrow Ayarları Formu
    public string $paytrMerchantId = '';
    public string $paytrMerchantKey = '';
    public string $paytrMerchantSalt = '';
    public bool $paytrSandboxMode = true;

    // Komisyon Oranları Formu
    public float $commissionStandardDriver = 5.00; // %5.00 Standart
    public float $commissionDiscountedPremium = 3.00; // %3.00 Premium
    public float $commissionCargoOwner = 2.50; // %2.50 Yük Sahibi

    // NetGSM & SMS Formu
    public string $netgsmUser = '';
    public string $netgsmPass = '';
    public string $netgsmHeader = '';

    // Otomatik Bildirim / SMS & Mail Şablonları
    public string $templateOtpSms = '';
    public string $templatePaymentReceived = '';
    public string $templateDeliveryCompleted = '';

    public function mount()
    {
        if (!auth()->user()->can('manage settings')) {
            abort(403, 'Bu alana erişim yetkiniz bulunmamaktadır.');
        }

        $this->loadSettings();
    }

    

    /**
     * Sistem ayarlarını yükler. Gizli entegrasyon değerleri yalnız config/.env üzerinden okunur.
     */
    public function loadSettings(): void
    {
        $this->siteTitle = (string) (CmsContent::getVal('system_site_title') ?: 'NavlunIQ - Akıllı Lojistik Ağı');
        $this->metaDescription = (string) (CmsContent::getVal('system_meta_description') ?: 'Yük sahipleri ve doğrulanmış taşıyıcıları buluşturan lojistik platformu.');
        $this->maintenanceMode = (bool) CmsContent::getVal('system_maintenance_mode', false);
        $this->defaultCurrency = (string) (CmsContent::getVal('system_default_currency') ?: 'TRY');
        $this->defaultTimezone = (string) (CmsContent::getVal('system_default_timezone') ?: 'Europe/Istanbul');
        $this->paytrMerchantId = config('services.paytr.merchant_id') ? 'Yapılandırıldı' : 'Eksik';
        $this->paytrMerchantKey = config('services.paytr.merchant_key') ? '••••••••' : '';
        $this->paytrMerchantSalt = config('services.paytr.merchant_salt') ? '••••••••' : '';
        $this->paytrSandboxMode = (bool) config('services.paytr.sandbox', true);
        $this->commissionStandardDriver = (float) CmsContent::getVal('commission_standard_driver', 5.00);
        $this->commissionDiscountedPremium = (float) CmsContent::getVal('commission_discounted_premium', 3.00);
        $this->commissionCargoOwner = (float) CmsContent::getVal('commission_cargo_owner', 2.50);
        $this->netgsmUser = config('services.netgsm.user') ? 'Yapılandırıldı' : 'Eksik';
        $this->netgsmPass = config('services.netgsm.password') ? '••••••••' : '';
        $this->netgsmHeader = (string) config('services.netgsm.header', 'NavlunIQ');
        $this->templateOtpSms = (string) (CmsContent::getVal('template_otp_sms') ?: 'NavlunIQ doğrulama kodunuz: {OTP_CODE}. Kod 5 dakika geçerlidir.');
        $this->templatePaymentReceived = (string) (CmsContent::getVal('template_payment_received') ?: '{LOAD_ID} numaralı sevkiyat için ödeme sağlayıcı onayı alınmıştır.');
        $this->templateDeliveryCompleted = (string) (CmsContent::getVal('template_delivery_completed') ?: '{LOAD_ID} numaralı sevkiyat teslim edildi olarak kaydedilmiştir.');
    }

    /**
     * Genel & SEO Ayarlarını Kaydet
     */
    public function saveGeneralSettings()
    {
        CmsContent::updateOrCreate(['key' => 'system_site_title'], ['value' => $this->siteTitle]);
        CmsContent::updateOrCreate(['key' => 'system_meta_description'], ['value' => $this->metaDescription]);
        CmsContent::updateOrCreate(['key' => 'system_maintenance_mode'], ['value' => $this->maintenanceMode ? '1' : '0']);

        session()->flash('success', 'Genel site ve SEO ayarları başarıyla güncellendi.');
    }

    /**
     * PayTR Ödeme Ayarlarını Kaydet
     */
    public function savePaytrSettings()
    {
        session()->flash('error', 'PayTR kimlik bilgileri güvenlik nedeniyle yalnız sunucu .env dosyasından yönetilir.');
    }

    /**
     * Komisyon Oranlarını Kaydet
     */
    public function saveCommissionSettings()
    {
        CmsContent::updateOrCreate(['key' => 'commission_standard_driver'], ['value' => $this->commissionStandardDriver]);
        CmsContent::updateOrCreate(['key' => 'commission_discounted_premium'], ['value' => $this->commissionDiscountedPremium]);
        CmsContent::updateOrCreate(['key' => 'commission_cargo_owner'], ['value' => $this->commissionCargoOwner]);

        session()->flash('success', 'Platform komisyon ve hizmet bedeli oranları güncellendi.');
    }

    /**
     * SMS ve Şablon Ayarlarını Kaydet
     */
    public function saveTemplateSettings()
    {
        CmsContent::updateOrCreate(['key' => 'netgsm_user'], ['value' => $this->netgsmUser]);
        CmsContent::updateOrCreate(['key' => 'netgsm_header'], ['value' => $this->netgsmHeader]);

        CmsContent::updateOrCreate(['key' => 'template_otp_sms'], ['value' => $this->templateOtpSms]);
        CmsContent::updateOrCreate(['key' => 'template_payment_received'], ['value' => $this->templatePaymentReceived]);
        CmsContent::updateOrCreate(['key' => 'template_delivery_completed'], ['value' => $this->templateDeliveryCompleted]);

        session()->flash('success', 'NetGSM bilgileri ve otomatik bildirim şablonları güncellendi.');
    }
}; ?>

<div class="max-w-7xl mx-auto space-y-8 animate-fade-in">
    <!-- Bildirim Banner'ları -->
    @if (session()->has('success'))
        <div
            class="p-4 bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200/50 dark:border-emerald-800/30 text-emerald-600 dark:text-emerald-400 text-sm rounded-2xl flex items-center space-x-2 animate-fade-in shadow-apple-sm">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span>{{ session('success') }}</span>
        </div>
    @endif

    <!-- Üst Başlık ve Akıllı Tohumlayıcı -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">Sistem ve Temel Entegrasyon
                Ayarları</h1>
            <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">PayTR ödeme anahtarlarını, komisyon
                oranlarını, NetGSM ve SMS şablonlarını yönetin.</p>
        </div>

        @if(!\App\Models\CmsContent::where('key', 'paytr_merchant_id')->exists())
@endif
    </div>

    <!-- Filtre Segment Kontrolleri -->
    <div
        class="flex p-0.5 bg-neutral-200/50 dark:bg-neutral-900 rounded-2xl w-full md:w-max border border-neutral-200/10 shadow-apple-sm no-print">
        <button wire:click="$set('activeTab', 'general')"
            class="flex-1 md:flex-none px-6 py-2.5 text-xs font-semibold rounded-xl transition-all duration-300 {{ $activeTab === 'general' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">
            Genel & SEO
        </button>
        <button wire:click="$set('activeTab', 'paytr')"
            class="flex-1 md:flex-none px-6 py-2.5 text-xs font-semibold rounded-xl transition-all duration-300 {{ $activeTab === 'paytr' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">
            PayTR Ödeme & Escrow
        </button>
        <button wire:click="$set('activeTab', 'commission')"
            class="flex-1 md:flex-none px-6 py-2.5 text-xs font-semibold rounded-xl transition-all duration-300 {{ $activeTab === 'commission' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">
            Komisyon Oranları
        </button>
        <button wire:click="$set('activeTab', 'templates')"
            class="flex-1 md:flex-none px-6 py-2.5 text-xs font-semibold rounded-xl transition-all duration-300 {{ $activeTab === 'templates' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">
            NetGSM & Bildirim Şablonları
        </button>
    </div>

    <!-- Sekme İçerikleri -->
    @if($activeTab === 'general')
        <!-- SEKME 1: GENEL VE SEO AYARLARI -->
        <div class="apple-glass rounded-3xl p-6 space-y-6">
            <h3
                class="text-sm font-bold text-neutral-800 dark:text-neutral-200 uppercase tracking-wider pb-3 border-b border-neutral-100 dark:border-neutral-800/50">
                GENEL SİTE VE SEO YAPILANDIRMASI</h3>

            <form wire:submit.prevent="saveGeneralSettings" class="space-y-6 text-xs">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">Site Başlığı (Title)</label>
                        <input type="text" wire:model.defer="siteTitle"
                            class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none">
                    </div>
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">Varsayılan Para Birimi & Zaman Dilimi</label>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <input type="text" wire:model.defer="defaultCurrency" readonly
                                class="w-full p-3 bg-neutral-200/50 dark:bg-neutral-800 border border-neutral-200/40 text-neutral-500 rounded-xl font-bold">
                            <input type="text" wire:model.defer="defaultTimezone" readonly
                                class="w-full p-3 bg-neutral-200/50 dark:bg-neutral-800 border border-neutral-200/40 text-neutral-500 rounded-xl font-bold">
                        </div>
                    </div>
                </div>

                <div class="space-y-1.5">
                    <label class="font-semibold text-neutral-500">Meta Açıklamaları (SEO Description)</label>
                    <textarea wire:model.defer="metaDescription" rows="3"
                        class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none"></textarea>
                </div>

                <div
                    class="p-4 bg-neutral-50 dark:bg-neutral-900 rounded-2xl border border-neutral-200/40 flex justify-between items-center">
                    <div>
                        <span class="font-bold text-neutral-900 dark:text-white block">Bakım Modu (Maintenance Mode)</span>
                        <span class="text-[11px] text-neutral-400">Aktif edildiğinde site ziyaretçilere kapalı, sadece
                            adminlere açık olur.</span>
                    </div>
                    <input type="checkbox" wire:model.defer="maintenanceMode"
                        class="w-5 h-5 accent-brand-500 rounded cursor-pointer">
                </div>

                <div class="flex justify-end pt-4 border-t border-neutral-100 dark:border-neutral-800/50">
                    <button type="submit" class="btn-apple-brand py-3.5 px-6 text-xs font-semibold">
                        Genel Ayarları Kaydet
                    </button>
                </div>
            </form>
        </div>

    @elseif($activeTab === 'paytr')
        <!-- SEKME 2: PAYTR ESCROW ÖDEME AYARLARI -->
        <div class="apple-glass rounded-3xl p-6 space-y-6">
            <h3
                class="text-sm font-bold text-neutral-800 dark:text-neutral-200 uppercase tracking-wider pb-3 border-b border-neutral-100 dark:border-neutral-800/50">
                PAYTR SANAL POS & GÜVENLİ HAVUZ (ESCROW) PARAMETRELERİ</h3>

            <form wire:submit.prevent="savePaytrSettings" class="space-y-6 text-xs">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">PayTR Merchant ID (Mağaza No)</label>
                        <input type="text" wire:model.defer="paytrMerchantId" readonly
                            class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white font-mono rounded-xl focus:outline-none">
                    </div>
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">PayTR API Key</label>
                        <input type="password" wire:model.defer="paytrMerchantKey" readonly
                            class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white font-mono rounded-xl focus:outline-none">
                    </div>
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">PayTR Secret Salt</label>
                        <input type="password" wire:model.defer="paytrMerchantSalt" readonly
                            class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white font-mono rounded-xl focus:outline-none">
                    </div>
                </div>

                <div class="p-4 bg-brand-500/5 border border-brand-500/10 rounded-2xl flex justify-between items-center">
                    <div>
                        <span class="font-bold text-brand-500 block">PayTR Test / Sandbox Modu</span>
                        <span class="text-[11px] text-neutral-500">Açık olduğunda gerçek kartlar çekilmez, PayTR test ortamı
                            kullanılır.</span>
                    </div>
                    <input type="checkbox" wire:model.defer="paytrSandboxMode"
                        class="w-5 h-5 accent-brand-500 rounded cursor-pointer">
                </div>

                <div class="flex justify-end pt-4 border-t border-neutral-100 dark:border-neutral-800/50">
                    <button type="submit" class="btn-apple-brand py-3.5 px-6 text-xs font-semibold">
                        PayTR anahtarları .env üzerinden yönetilir
                    </button>
                </div>
            </form>
        </div>

    @elseif($activeTab === 'commission')
        <!-- SEKME 3: KOMİSYON ORANLARI YÖNETİMİ -->
        <div class="apple-glass rounded-3xl p-6 space-y-6">
            <h3
                class="text-sm font-bold text-neutral-800 dark:text-neutral-200 uppercase tracking-wider pb-3 border-b border-neutral-100 dark:border-neutral-800/50">
                PLATFORM KOMİSYON VE HİZMET BEDELİ ORANLARI</h3>

            <form wire:submit.prevent="saveCommissionSettings" class="space-y-6 text-xs">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="space-y-1.5 p-4 bg-neutral-50 dark:bg-neutral-900 rounded-2xl border border-neutral-200/40">
                        <label class="font-bold text-neutral-900 dark:text-white block">Standart Şoför Komisyonu (%)</label>
                        <input type="number" step="0.01" wire:model.defer="commissionStandardDriver"
                            class="w-full p-3 bg-white dark:bg-neutral-800 border border-neutral-200/40 text-neutral-900 dark:text-white font-bold text-lg rounded-xl focus:outline-none">
                        <span class="text-[10px] text-neutral-400 mt-1 block">Başarılı teslimatlardan şoförden kesilen
                            standart oran.</span>
                    </div>

                    <div class="space-y-1.5 p-4 bg-brand-500/5 rounded-2xl border border-brand-500/10">
                        <label class="font-bold text-brand-500 block">Premium Şoför İndirimli Komisyon (%)</label>
                        <input type="number" step="0.01" wire:model.defer="commissionDiscountedPremium"
                            class="w-full p-3 bg-white dark:bg-neutral-800 border border-neutral-200/40 text-neutral-900 dark:text-white font-bold text-lg rounded-xl focus:outline-none">
                        <span class="text-[10px] text-neutral-400 mt-1 block">Aylık Premium üye olan şoförlere uygulanan
                            indirimli oran.</span>
                    </div>

                    <div class="space-y-1.5 p-4 bg-neutral-50 dark:bg-neutral-900 rounded-2xl border border-neutral-200/40">
                        <label class="font-bold text-neutral-900 dark:text-white block">Yük Sahibi Hizmet Bedeli (%)</label>
                        <input type="number" step="0.01" wire:model.defer="commissionCargoOwner"
                            class="w-full p-3 bg-white dark:bg-neutral-800 border border-neutral-200/40 text-neutral-900 dark:text-white font-bold text-lg rounded-xl focus:outline-none">
                        <span class="text-[10px] text-neutral-400 mt-1 block">Yük sahibinden PayTR havuz aşamasında alınan
                            hizmet bedeli.</span>
                    </div>
                </div>

                <div class="flex justify-end pt-4 border-t border-neutral-100 dark:border-neutral-800/50">
                    <button type="submit" class="btn-apple-brand py-3.5 px-6 text-xs font-semibold">
                        Komisyon Oranlarını Güncelle
                    </button>
                </div>
            </form>
        </div>

    @elseif($activeTab === 'templates')
        <!-- SEKME 4: NETGSM & BİLDİRİM ŞABLONLARI -->
        <div class="apple-glass rounded-3xl p-6 space-y-6">
            <h3
                class="text-sm font-bold text-neutral-800 dark:text-neutral-200 uppercase tracking-wider pb-3 border-b border-neutral-100 dark:border-neutral-800/50">
                AUTOMATED SMS & E-POSTA METİN ŞABLONLARI</h3>

            <form wire:submit.prevent="saveTemplateSettings" class="space-y-6 text-xs">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">NetGSM Kullanıcı Kodu</label>
                        <input type="text" wire:model.defer="netgsmUser"
                            class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none">
                    </div>
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">NetGSM SMS / WhatsApp Başlık (Header)</label>
                        <input type="text" wire:model.defer="netgsmHeader"
                            class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none">
                    </div>
                </div>

                <!-- Şablon Editörleri -->
                <div class="space-y-4 pt-4 border-t border-neutral-100 dark:border-neutral-800/50">
                    <div class="space-y-1.5">
                        <label class="font-bold text-neutral-900 dark:text-white block">"Şifremi Unuttum / OTP" SMS Şablonu</label>
                        <textarea wire:model.defer="templateOtpSms" rows="2"
                            class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none font-mono"></textarea>
                    </div>

                    <div class="space-y-1.5">
                        <label class="font-bold text-neutral-900 dark:text-white block">"Ödeme Alındı / Escrow Bloke"
                            Bildirim Şablonu</label>
                        <textarea wire:model.defer="templatePaymentReceived" rows="2"
                            class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none font-mono"></textarea>
                    </div>

                    <div class="space-y-1.5">
                        <label class="font-bold text-neutral-900 dark:text-white block">"Teslimat Tamamlandı / EFT Ödendi"
                            Şablonu</label>
                        <textarea wire:model.defer="templateDeliveryCompleted" rows="2"
                            class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none font-mono"></textarea>
                    </div>
                </div>

                <div class="flex justify-end pt-4 border-t border-neutral-100 dark:border-neutral-800/50">
                    <button type="submit" class="btn-apple-brand py-3.5 px-6 text-xs font-semibold">
                        Tüm Şablonları Güncelle
                    </button>
                </div>
            </form>
        </div>
    @endif
</div>
