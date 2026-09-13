<?php

use Livewire\Volt\Component;
use App\Models\CmsContent;
use App\Models\Page;
use App\Models\Faq;
use Illuminate\Support\Str;

new class extends Component {
    // Sekme Kontrolü
    public string $activeTab = 'slider'; // 'slider', 'footer', 'faqs', 'pages', 'contracts'

    // Form Değişkenleri (Slider & Sabit Bloklar)
    public string $sliderOwnerTitle = '';
    public string $sliderOwnerDesc = '';
    public string $sliderDriverTitle = '';
    public string $sliderDriverDesc = '';
    public string $hakkimizdaOzet = '';
    public string $scrollSpeed = '25';

    // Footer ve Sosyal Medya Form Değişkenleri
    public string $footerSlogan = '';
    public string $etbisCode = '';
    public string $socialInstagram = '';
    public string $socialWhatsapp = '';
    public string $socialTelegram = '';

    // SSS Form Verileri
    public string $faqQuestion = '';
    public string $faqAnswer = '';
    public int $faqLimit = 5;

    // Dinamik Sayfa Form Verileri
    public string $pageTitle = '';
    public string $pageContent = '';

    // 5 Yasal Sözleşme Form Verileri
    public string $contractKvkk = '';
    public string $contractTerms = '';
    public string $contractPrivacy = '';
    public string $contractDistanceSale = '';
    public string $contractCancellation = '';

    public function mount(): void
    {
        if (auth()->check() && !auth()->user()->can('manage cms') && auth()->user()->current_role !== 'admin') {
            abort(403, 'Bu alana erişim yetkiniz bulunmamaktadır.');
        }

        $this->loadCmsFields();
    }

    

    /**
     * Veritabanındaki düzenlenebilir CMS alanlarını forma yükler.
     */
    public function loadCmsFields(): void
    {
        $this->sliderOwnerTitle = (string) CmsContent::getVal('slider_owner_title', 'Ödemeler Kontrollü Ödeme Süreciyle Korunur');
        $this->sliderOwnerDesc = (string) CmsContent::getVal('slider_owner_desc', 'İlanlarınıza gelen teklifleri anlık olarak değerlendirebilirsiniz.');
        $this->sliderDriverTitle = (string) CmsContent::getVal('slider_driver_title', 'İlanları Tek Panelden Takip Edin');
        $this->sliderDriverDesc = (string) CmsContent::getVal('slider_driver_desc', 'Onaylı iç ve dış kaynak ilanlarını panelinizden takip edin.');
        $this->hakkimizdaOzet = (string) CmsContent::getVal('hakkimizda_ozet', 'NavlunIQ, yük sahipleri ve doğrulanmış taşıyıcıları dijital ortamda buluşturan lojistik platformudur.');
        $this->scrollSpeed = (string) CmsContent::getVal('scroll_speed', '25');
        $this->footerSlogan = (string) CmsContent::getVal('footer_slogan', 'Akıllı ve kontrollü taşımacılık ekosistemi.');
        $this->etbisCode = (string) CmsContent::getVal('etbis_code', '');
        $this->socialInstagram = (string) CmsContent::getVal('social_instagram', '');
        $this->socialWhatsapp = (string) CmsContent::getVal('social_whatsapp', '');
        $this->socialTelegram = (string) CmsContent::getVal('social_telegram', '');
        $this->faqLimit = (int) CmsContent::getVal('faq_limit', 5);
        $this->contractKvkk = (string) CmsContent::getVal('contract_kvkk', '');
        $this->contractTerms = (string) CmsContent::getVal('contract_terms', '');
        $this->contractPrivacy = (string) CmsContent::getVal('contract_privacy', '');
        $this->contractDistanceSale = (string) CmsContent::getVal('contract_distance_sale', '');
        $this->contractCancellation = (string) CmsContent::getVal('contract_cancellation', '');
    }

    /**
     * Slider ve Sabit Blok Güncellemelerini Kaydet
     */
    public function saveSliderAndBlocks(): void
    {
        CmsContent::updateOrCreate(['key' => 'slider_owner_title'], ['value' => $this->sliderOwnerTitle]);
        CmsContent::updateOrCreate(['key' => 'slider_owner_desc'], ['value' => $this->sliderOwnerDesc]);
        CmsContent::updateOrCreate(['key' => 'slider_driver_title'], ['value' => $this->sliderDriverTitle]);
        CmsContent::updateOrCreate(['key' => 'slider_driver_desc'], ['value' => $this->sliderDriverDesc]);
        CmsContent::updateOrCreate(['key' => 'hakkimizda_ozet'], ['value' => $this->hakkimizdaOzet]);
        CmsContent::updateOrCreate(['key' => 'scroll_speed'], ['value' => $this->scrollSpeed]);

        session()->flash('success', 'Slider metinleri ve genel blok ayarları başarıyla güncellendi.');
    }

    /**
     * Footer ve Sosyal Medya Ayarlarını Kaydet
     */
    public function saveFooterSettings(): void
    {
        CmsContent::updateOrCreate(['key' => 'footer_slogan'], ['value' => $this->footerSlogan]);
        CmsContent::updateOrCreate(['key' => 'etbis_code'], ['value' => $this->etbisCode]);
        CmsContent::updateOrCreate(['key' => 'social_instagram'], ['value' => $this->socialInstagram]);
        CmsContent::updateOrCreate(['key' => 'social_whatsapp'], ['value' => $this->socialWhatsapp]);
        CmsContent::updateOrCreate(['key' => 'social_telegram'], ['value' => $this->socialTelegram]);

        session()->flash('success', 'Footer ve Sosyal Medya bağlantı ayarları başarıyla güncellendi.');
    }

    /**
     * Yeni SSS Ekleme
     */
    public function addFaq(): void
    {
        $this->validate([
            'faqQuestion' => 'required|string|min:5',
            'faqAnswer' => 'required|string|min:10'
        ]);

        Faq::create([
            'question' => $this->faqQuestion,
            'answer' => $this->faqAnswer,
            'order_num' => Faq::count() + 1
        ]);

        $this->reset(['faqQuestion', 'faqAnswer']);
        session()->flash('success', 'Yeni Sıkça Sorulan Soru başarıyla arayüze eklendi.');
    }

    /**
     * SSS Limit Güncelleme
     */
    public function saveFaqLimit(): void
    {
        CmsContent::updateOrCreate(['key' => 'faq_limit'], ['value' => $this->faqLimit]);
        session()->flash('success', 'Anasayfa SSS limit ayarı güncellendi.');
    }

    /**
     * Sınırsız Dinamik Sayfa Oluşturucu
     */
    public function createPage(): void
    {
        $this->validate([
            'pageTitle' => 'required|string|min:5',
            'pageContent' => 'required|string|min:15'
        ]);

        Page::create([
            'title' => $this->pageTitle,
            'slug' => Str::slug($this->pageTitle),
            'content' => $this->pageContent
        ]);

        $this->reset(['pageTitle', 'pageContent']);
        session()->flash('success', 'Yeni statik HTML sayfa başarıyla oluşturuldu ve yayına alındı.');
    }

    /**
     * 5 Yasal Sözleşmenin Tamamını Kaydet
     */
    public function saveContracts(): void
    {
        CmsContent::updateOrCreate(['key' => 'contract_kvkk'], ['value' => $this->contractKvkk]);
        CmsContent::updateOrCreate(['key' => 'contract_terms'], ['value' => $this->contractTerms]);
        CmsContent::updateOrCreate(['key' => 'contract_privacy'], ['value' => $this->contractPrivacy]);
        CmsContent::updateOrCreate(['key' => 'contract_distance_sale'], ['value' => $this->contractDistanceSale]);
        CmsContent::updateOrCreate(['key' => 'contract_cancellation'], ['value' => $this->contractCancellation]);

        session()->flash('success', '5 yasal sözleşmenin tamamı güncellendi ve sitede anında yayına alındı!');
    }
}; ?>

<div class="max-w-7xl mx-auto space-y-8 animate-fade-in">

    <!-- Bildirim Banner'ı -->
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
            <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">İçerik ve Arayüz Yönetimi
                (CMS)</h1>
            <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">Sitenin ön yüzündeki tüm metinleri, footer ve
                5 yasal sözleşmeyi kod bilmeden yönetin.</p>
        </div>

</div>

    <!-- Segment Kontrolleri -->
    <div
        class="flex flex-wrap p-1 bg-neutral-200/50 dark:bg-neutral-900 rounded-2xl w-full md:w-max border border-neutral-200/10 shadow-apple-sm gap-1">
        <button wire:click="$set('activeTab', 'slider')"
            class="px-5 py-2 text-xs font-semibold rounded-xl transition-all duration-300 {{ $activeTab === 'slider' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">
            Slider & Sabit Bloklar
        </button>
        <button wire:click="$set('activeTab', 'footer')"
            class="px-5 py-2 text-xs font-semibold rounded-xl transition-all duration-300 {{ $activeTab === 'footer' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">
            Footer & Sosyal Medya
        </button>
        <button wire:click="$set('activeTab', 'faqs')"
            class="px-5 py-2 text-xs font-semibold rounded-xl transition-all duration-300 {{ $activeTab === 'faqs' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">
            Sıkça Sorulan Sorular (SSS)
        </button>
        <button wire:click="$set('activeTab', 'pages')"
            class="px-5 py-2 text-xs font-semibold rounded-xl transition-all duration-300 {{ $activeTab === 'pages' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">
            Dinamik Sayfalar (HTML)
        </button>
        <button wire:click="$set('activeTab', 'contracts')"
            class="px-5 py-2 text-xs font-semibold rounded-xl transition-all duration-300 {{ $activeTab === 'contracts' ? 'bg-white dark:bg-neutral-800 text-neutral-900 dark:text-white shadow-apple-sm' : 'text-neutral-500' }}">
            📜 5 Yasal Sözleşme Masası
        </button>
    </div>

    <!-- 1. SEKME: SLIDER VE SABİT BLOKLAR -->
    @if($activeTab === 'slider')
        <div class="apple-glass rounded-3xl p-6 space-y-6">
            <h3
                class="text-sm font-bold text-neutral-800 dark:text-neutral-200 uppercase tracking-wider pb-3 border-b border-neutral-100 dark:border-neutral-800/50">
                DİNAMİK SLIDER & BLOK METİNLERİ
            </h3>

            <form wire:submit.prevent="saveSliderAndBlocks" class="grid grid-cols-1 md:grid-cols-2 gap-6 text-xs">
                <!-- Yük Sahibi Slider -->
                <div class="space-y-4 p-4 bg-neutral-50 dark:bg-neutral-900 rounded-2xl border border-neutral-200/40">
                    <span class="font-bold text-brand-500 block">YÜK SAHİBİ SLIDER ALANI</span>
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">Slider Ana Başlığı</label>
                        <input type="text" wire:model="sliderOwnerTitle"
                            class="w-full p-3 bg-white dark:bg-neutral-800 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none">
                    </div>
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">Slider Açıklama Metni</label>
                        <textarea wire:model="sliderOwnerDesc" rows="3"
                            class="w-full p-3 bg-white dark:bg-neutral-800 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none"></textarea>
                    </div>
                </div>

                <!-- Şoför Slider -->
                <div class="space-y-4 p-4 bg-neutral-50 dark:bg-neutral-900 rounded-2xl border border-neutral-200/40">
                    <span class="font-bold text-brand-500 block">ŞOFÖR SLIDER ALANI</span>
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">Slider Ana Başlığı</label>
                        <input type="text" wire:model="sliderDriverTitle"
                            class="w-full p-3 bg-white dark:bg-neutral-800 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none">
                    </div>
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">Slider Açıklama Metni</label>
                        <textarea wire:model="sliderDriverDesc" rows="3"
                            class="w-full p-3 bg-white dark:bg-neutral-800 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none"></textarea>
                    </div>
                </div>

                <!-- Hakkımızda Özet ve Araç Galerisi Hızı -->
                <div
                    class="md:col-span-2 space-y-4 p-4 bg-neutral-50 dark:bg-neutral-900 rounded-2xl border border-neutral-200/40">
                    <span class="font-bold text-brand-500 block">GENEL BLOKLAR & GALERİ AYARLARI</span>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div class="md:col-span-2 space-y-1.5">
                            <label class="font-semibold text-neutral-500">Hakkımızda Özet Metni (Anasayfa İçin)</label>
                            <textarea wire:model="hakkimizdaOzet" rows="3"
                                class="w-full p-3 bg-white dark:bg-neutral-800 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none"></textarea>
                        </div>
                        <div class="space-y-1.5">
                            <label class="font-semibold text-neutral-500">Araç Galerisi Kayma Hızı (px/sn)</label>
                            <input type="number" wire:model="scrollSpeed"
                                class="w-full p-3 bg-white dark:bg-neutral-800 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none">
                        </div>
                    </div>
                </div>

                <div class="md:col-span-2 flex justify-end">
                    <button type="submit" class="btn-apple-brand py-3 px-6 text-xs font-semibold">
                        Arayüz Metinlerini Güncelle
                    </button>
                </div>
            </form>
        </div>

        <!-- 2. SEKME: FOOTER VE SOSYAL MEDYA -->
    @elseif($activeTab === 'footer')
        <div class="apple-glass rounded-3xl p-6 space-y-6">
            <h3
                class="text-sm font-bold text-neutral-800 dark:text-neutral-200 uppercase tracking-wider pb-3 border-b border-neutral-100 dark:border-neutral-800/50">
                FOOTER & SOSYAL MEDYA YÖNETİMİ
            </h3>

            <form wire:submit.prevent="saveFooterSettings" class="space-y-6 text-xs">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">Footer Sloganı</label>
                        <input type="text" wire:model="footerSlogan"
                            class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none">
                    </div>
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">ETBİS Kayıt Numarası</label>
                        <input type="text" wire:model="etbisCode"
                            class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none">
                    </div>
                </div>

                <div class="space-y-4 pt-4 border-t border-neutral-100 dark:border-neutral-800/50">
                    <span class="font-bold text-brand-500 block">RESMİ SOSYAL MEDYA KANAL KÖPRÜLERİ</span>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div class="space-y-1.5">
                            <label class="font-semibold text-neutral-500">Instagram Hesabı URL</label>
                            <input type="url" wire:model="socialInstagram"
                                class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="font-semibold text-neutral-500">WhatsApp Kanalı URL</label>
                            <input type="url" wire:model="socialWhatsapp"
                                class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="font-semibold text-neutral-500">Telegram Kanalı URL</label>
                            <input type="url" wire:model="socialTelegram"
                                class="w-full p-3 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none">
                        </div>
                    </div>
                </div>

                <div class="flex justify-end pt-4 border-t border-neutral-100 dark:border-neutral-800/50">
                    <button type="submit" class="btn-apple-brand py-3.5 px-6 text-xs font-semibold">
                        Footer ve Sosyal Bağlantıları Kaydet
                    </button>
                </div>
            </form>
        </div>

        <!-- 3. SEKME: SSS -->
    @elseif($activeTab === 'faqs')
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 items-start">
            <div class="apple-glass rounded-3xl p-6 space-y-4 text-xs">
                <h3
                    class="text-sm font-bold text-neutral-800 dark:text-neutral-200 uppercase tracking-wider pb-3 border-b border-neutral-100 dark:border-neutral-800/50">
                    YENİ SORU EKLE
                </h3>

                <form wire:submit.prevent="addFaq" class="space-y-4">
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">Soru Metni</label>
                        <input type="text" wire:model="faqQuestion"
                            placeholder="Örn: NavlunIQ komisyon oranları nedir?"
                            class="w-full p-3 bg-neutral-100 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none">
                    </div>
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">Gerekçeli Cevap</label>
                        <textarea wire:model="faqAnswer" rows="5" placeholder="Soruya verilecek resmi cevabı yazın..."
                            class="w-full p-3 bg-neutral-100 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none"></textarea>
                    </div>

                    <button type="submit" class="w-full btn-apple-primary py-3 text-xs">
                        Soruyu Yayına Al
                    </button>
                </form>
            </div>

            <div class="lg:col-span-2 apple-glass rounded-3xl p-6 space-y-6">
                <div class="flex justify-between items-center pb-3 border-b border-neutral-100 dark:border-neutral-800/50">
                    <h3 class="text-sm font-bold text-neutral-800 dark:text-neutral-200 uppercase tracking-wider">KAYITLI
                        SORULAR & GÖSTERİM LİMİTİ</h3>
                    <div class="flex items-center space-x-2 text-xs">
                        <label class="font-semibold text-neutral-500">Gösterim Limiti:</label>
                        <input type="number" wire:model="faqLimit"
                            class="w-16 p-1.5 bg-neutral-100 dark:bg-neutral-900 border border-neutral-200/40 rounded-lg text-center font-bold">
                        <button wire:click="saveFaqLimit"
                            class="bg-neutral-900 text-white dark:bg-white dark:text-neutral-900 px-2 py-1.5 rounded-lg font-bold">Kaydet</button>
                    </div>
                </div>

                <div class="space-y-4 text-xs">
                    @forelse(\App\Models\Faq::latest()->get() as $faq)
                        <div class="p-4 bg-neutral-50 dark:bg-neutral-900 rounded-2xl border border-neutral-200/40 space-y-1.5">
                            <span class="font-bold text-brand-500 block">Soru: {{ $faq->question }}</span>
                            <p class="text-neutral-500 dark:text-neutral-400 leading-relaxed">Cevap: {{ $faq->answer }}</p>
                        </div>
                    @empty
                        <p class="text-center text-neutral-400">Henüz kayıtlı sıkça sorulan soru bulunmuyor.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <!-- 4. SEKME: DİNAMİK SAYFALAR -->
    @elseif($activeTab === 'pages')
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 items-start">
            <div class="apple-glass rounded-3xl p-6 space-y-4 text-xs">
                <h3
                    class="text-sm font-bold text-neutral-800 dark:text-neutral-200 uppercase tracking-wider pb-3 border-b border-neutral-100 dark:border-neutral-800/50">
                    DİNAMİK SAYFA OLUŞTURUCU
                </h3>

                <form wire:submit.prevent="createPage" class="space-y-4">
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">Sayfa Başlığı</label>
                        <input type="text" wire:model="pageTitle" placeholder="Örn: Yaz Sezonu İndirim Kampanyası"
                            class="w-full p-3 bg-neutral-100 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none">
                    </div>
                    <div class="space-y-1.5">
                        <label class="font-semibold text-neutral-500">HTML / Metin Sayfa İçeriği</label>
                        <textarea wire:model="pageContent" rows="6"
                            placeholder="Sayfanın içeriğini HTML formatında veya düz metin olarak buraya yazın..."
                            class="w-full p-3 bg-neutral-100 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none"></textarea>
                    </div>

                    <button type="submit" class="w-full btn-apple-brand py-3 text-xs">
                        Sayfayı Yayına Al (Slug Üret)
                    </button>
                </form>
            </div>

            <div class="lg:col-span-2 apple-glass rounded-3xl p-6 space-y-4">
                <h3
                    class="text-sm font-bold text-neutral-800 dark:text-neutral-200 uppercase tracking-wider pb-3 border-b border-neutral-100 dark:border-neutral-800/50">
                    YAYINDAKİ DİNAMİK SAYFALAR
                </h3>

                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="text-neutral-400 font-bold border-b border-neutral-100 dark:border-neutral-800/50">
                            <th class="pb-3">Sayfa Başlığı</th>
                            <th class="pb-3">URL Takısı (Slug)</th>
                            <th class="pb-3">Oluşturulma Tarihi</th>
                            <th class="pb-3 text-right">Durum</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/30">
                        @forelse(\App\Models\Page::latest()->get() as $page)
                            <tr>
                                <td class="py-3 font-bold text-neutral-900 dark:text-white">{{ $page->title }}</td>
                                <td class="py-3 font-mono text-[11px] text-neutral-400">/sayfa/{{ $page->slug }}</td>
                                <td class="py-3 text-neutral-400">{{ $page->created_at->format('Y-m-d H:i') }}</td>
                                <td class="py-3 text-right">
                                    <span
                                        class="px-2.5 py-0.5 rounded-full font-bold text-[10px] bg-emerald-500/10 text-emerald-600">YAYINDA</span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="py-6 text-center text-neutral-400">Henüz oluşturulmuş dinamik bir sayfa
                                    bulunmuyor.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 5. SEKME: 5 YASAL SÖZLEŞMENİN TAMAMI -->
    @elseif($activeTab === 'contracts')
        <div class="apple-glass rounded-3xl p-6 space-y-6">
            <div class="flex items-center justify-between pb-3 border-b border-neutral-100 dark:border-neutral-800/50">
                <div>
                    <h3 class="text-sm font-bold text-neutral-800 dark:text-neutral-200 uppercase tracking-wider">
                        5 YASAL SÖZLEŞME VE POLİTİKA EDİTÖRLERİ
                    </h3>
                    <p class="text-xs text-neutral-400 mt-0.5">Buradan güncellediğiniz metinler ön yüzde ve kayıt onay
                        kutularında anında güncellenir.</p>
                </div>
                <a href="/sozlesmeler/kvkk" target="_blank" class="text-xs text-brand-500 font-bold hover:underline">Sitede
                    Önizle &rarr;</a>
            </div>

            <form wire:submit.prevent="saveContracts" class="space-y-6 text-xs">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                    <!-- 1. KVKK Aydınlatma Metni -->
                    <div class="space-y-1.5 p-4 bg-neutral-50 dark:bg-neutral-900 rounded-2xl border border-neutral-200/40">
                        <div class="flex items-center justify-between mb-1">
                            <label class="font-bold text-neutral-700 dark:text-neutral-200">1. KVKK Aydınlatma Metni</label>
                            <span class="text-[10px] font-mono text-neutral-400">contract_kvkk</span>
                        </div>
                        <textarea wire:model="contractKvkk" rows="10"
                            class="w-full p-4 bg-white dark:bg-neutral-800 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none font-mono text-[11px] leading-relaxed"></textarea>
                    </div>

                    <!-- 2. Kullanıcı Sözleşmesi -->
                    <div class="space-y-1.5 p-4 bg-neutral-50 dark:bg-neutral-900 rounded-2xl border border-neutral-200/40">
                        <div class="flex items-center justify-between mb-1">
                            <label class="font-bold text-neutral-700 dark:text-neutral-200">2. Kullanıcı Sözleşmesi</label>
                            <span class="text-[10px] font-mono text-neutral-400">contract_terms</span>
                        </div>
                        <textarea wire:model="contractTerms" rows="10"
                            class="w-full p-4 bg-white dark:bg-neutral-800 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none font-mono text-[11px] leading-relaxed"></textarea>
                    </div>

                    <!-- 3. Gizlilik Politikası -->
                    <div class="space-y-1.5 p-4 bg-neutral-50 dark:bg-neutral-900 rounded-2xl border border-neutral-200/40">
                        <div class="flex items-center justify-between mb-1">
                            <label class="font-bold text-neutral-700 dark:text-neutral-200">3. Gizlilik Politikası</label>
                            <span class="text-[10px] font-mono text-neutral-400">contract_privacy</span>
                        </div>
                        <textarea wire:model="contractPrivacy" rows="10"
                            class="w-full p-4 bg-white dark:bg-neutral-800 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none font-mono text-[11px] leading-relaxed"></textarea>
                    </div>

                    <!-- 4. Mesafeli Satış Sözleşmesi -->
                    <div class="space-y-1.5 p-4 bg-neutral-50 dark:bg-neutral-900 rounded-2xl border border-neutral-200/40">
                        <div class="flex items-center justify-between mb-1">
                            <label class="font-bold text-neutral-700 dark:text-neutral-200">4. Mesafeli Satış
                                Sözleşmesi</label>
                            <span class="text-[10px] font-mono text-neutral-400">contract_distance_sale</span>
                        </div>
                        <textarea wire:model="contractDistanceSale" rows="10"
                            class="w-full p-4 bg-white dark:bg-neutral-800 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none font-mono text-[11px] leading-relaxed"></textarea>
                    </div>

                    <!-- 5. İade ve İptal Politikası -->
                    <div
                        class="md:col-span-2 space-y-1.5 p-4 bg-neutral-50 dark:bg-neutral-900 rounded-2xl border border-neutral-200/40">
                        <div class="flex items-center justify-between mb-1">
                            <label class="font-bold text-neutral-700 dark:text-neutral-200">5. İade ve İptal
                                Politikası</label>
                            <span class="text-[10px] font-mono text-neutral-400">contract_cancellation</span>
                        </div>
                        <textarea wire:model="contractCancellation" rows="8"
                            class="w-full p-4 bg-white dark:bg-neutral-800 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none font-mono text-[11px] leading-relaxed"></textarea>
                    </div>

                </div>

                <div class="flex justify-end pt-4 border-t border-neutral-100 dark:border-neutral-800/50">
                    <button type="submit" class="btn-apple-brand py-3.5 px-8 text-xs font-semibold shadow-apple-sm">
                        5 Sözleşmenin Tamamını Kaydet & Yayına Al
                    </button>
                </div>
            </form>
        </div>
    @endif
</div>
