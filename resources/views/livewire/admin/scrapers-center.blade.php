<?php

use Livewire\Volt\Component;
use App\Models\Scraper;
use App\Models\ScrapedLoad;
use App\Services\AiParserService;

new class extends Component {
    // LLM ve Çalışma Ayarları
    public string $activeLlm = 'gemini'; // Sizin gerçek Gemini API'niz öncelikli!
    public string $selectedSourceType = 'whatsapp';

    // Formlar
    public string $newSourceName = '';
    public string $newSourceIdentifier = '';

    // AI Test İstasyonu Formu
    public string $testRawText = 'İzmir Bornovadan acil tır lazım yük rulo saç ağırlık 21 ton fiyat 14000 tl arayın 05325556677 Süleyman Usta';
    public ?array $parsedResult = null;

    public function mount()
    {
        if (!auth()->user()->can('manage scrapers')) {
            abort(403, 'Bu alana erişim yetkiniz bulunmamaktadır.');
        }
    }

    

    /**
     * Yeni Kaynak Ekleme
     */
    public function addSource()
    {
        $this->validate([
            'newSourceName' => 'required|string|min:5',
            'newSourceIdentifier' => 'required|string',
        ], [
            'newSourceName.required' => 'Kaynak adı girmek zorunludur.',
            'newSourceIdentifier.required' => 'Grup adı veya link girmek zorunludur.'
        ]);

        Scraper::create([
            'name' => $this->newSourceName,
            'type' => $this->selectedSourceType,
            'source_identifier' => $this->newSourceIdentifier,
            'is_active' => true
        ]);

        session()->flash('success', 'Yeni kazıma kaynağı başarıyla sisteme kaydedildi.');
        $this->reset(['newSourceName', 'newSourceIdentifier']);
    }

    /**
     * Yapay Zeka Test İstasyonu Çözümlemesi
     */
    public function runAiParserTest()
    {
        $this->validate([
            'testRawText' => 'required|string|min:10'
        ]);

        $parser = new AiParserService();
        $this->parsedResult = $parser->parseMessage($this->testRawText, $this->activeLlm);

        // Çözümlenen veriyi veritabanındaki dış kaynak ilanları (scraped_loads) tablosuna kaydet
        // Sadece geçerli ve telefon numarası olanlar kaydedilir
        $isSuccess = (isset($this->parsedResult['success']) && $this->parsedResult['success'] === true);
        $hasPhone = (isset($this->parsedResult['sender_phone']) && $this->parsedResult['sender_phone'] !== 'Bilinmiyor' && !empty($this->parsedResult['sender_phone']));

        if ($isSuccess && $hasPhone) {
            ScrapedLoad::create([
                'raw_message' => $this->testRawText,
                'sender_phone' => $this->parsedResult['sender_phone'],
                'pickup_location' => $this->parsedResult['pickup_location'],
                'delivery_location' => $this->parsedResult['delivery_location'],
                'goods_type' => $this->parsedResult['goods_type'],
                'weight' => $this->parsedResult['weight'],
                'price' => $this->parsedResult['price'],
                'status' => 'parsed_success',
                'parsed_by_llm' => $this->parsedResult['parsed_by_llm']
            ]);
        }
    }

    /**
     * REAKTİF TERMINAL LOG YÖNETİCİSİ
     */
    public function getTerminalLogsProperty(): array
    {
        $logs = [
            '[' . now()->format('H:i:s') . '] [Sistem] Otonom kazıma motoru dinleme modunda...',
            '[' . now()->format('H:i:s') . '] [Sistem] WhatsApp burner soket bağlantısı aktif ve kararlı.'
        ];

        $latestScraped = ScrapedLoad::with('scraper')->latest()->take(5)->get()->reverse();

        foreach ($latestScraped as $load) {
            $time = $load->created_at->format('H:i:s');
            $group = $load->scraper->name ?? 'Yapay Zeka Test';

            $logs[] = "[{$time}] [YAKALANDI] \"{$group}\" grubundan ham WhatsApp mesajı alındı.";
            if ($load->status === 'parsed_success') {
                // Burada da log çıktısını HTML entegrasyonu yerine terminal uyumlu TL ile güncelledik
                $logs[] = "[{$time}] [AI-SÜZÜLDÜ] Rota: {$load->pickup_location} -> {$load->delivery_location} | Fiyat: TL " . number_format($load->price, 2) . " | Telefon: {$load->masked_phone} [{$load->parsed_by_llm}]";
            } else {
                $logs[] = "[{$time}] [ALAKASIZ/ELENDİ] Ham metin süzme filtrelerine takıldı ve otonom olarak çöpe atıldı.";
            }
        }

        return $logs;
    }

    /**
     * Kaynakları getirir
     */
    private function getScrapers()
    {
        return Scraper::latest()->get();
    }

    /**
     * Süzülen son 10 ilanı getirir
     * 🚀 Sadece yapay zekanın "success: true" döndüğü gerçek ilanları çeker!
     */
    private function getScrapedLoads()
    {
        return ScrapedLoad::with('scraper')
            ->where('status', 'parsed_success')
            ->latest()
            ->take(10)
            ->get();
    }
}; ?>

<!-- wire:poll.3s direktifi ile tüm sayfa 3 saniyede bir arka planda sessizce kendini günceller! -->
<div class="max-w-7xl mx-auto space-y-8 animate-fade-in" wire:poll.3s>
    <!-- Bildirim Banner'ları -->
    @if (session()->has('success'))
        <div class="p-4 bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200/50 dark:border-emerald-800/30 text-emerald-600 dark:text-emerald-400 text-sm rounded-2xl flex items-center space-x-2 animate-fade-in shadow-apple-sm">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span>{{ session('success') }}</span>
        </div>
    @endif

    <!-- Üst Başlık -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">Yapay Zeka ve Otonom Kazıma Merkezi</h1>
            <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">Lojistik gruplarından derlenen ham verileri yapay zeka modelleriyle ilanlara dönüştürün.</p>
        </div>
    </div>

    <!-- Üst Grid: Kaynak Ekleme ve QR Entegrasyon Simülatörü -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

        <!-- Kart 1: Yeni Kazıma Kaynağı Ekle -->
        <div class="apple-glass rounded-3xl p-6 space-y-4">
            <div class="flex justify-between items-center pb-3 border-b border-neutral-100 dark:border-neutral-800/50">
                <h3 class="text-sm font-bold text-neutral-800 dark:text-neutral-200 uppercase tracking-wider">KAYNAK YÖNETİMİ</h3>
                @if(\App\Models\Scraper::count() === 0)
@endif
            </div>

            <form wire:submit.prevent="addSource" class="space-y-4 text-xs">
                <div class="space-y-1.5">
                    <label class="font-semibold text-neutral-500">Kaynak Sınıfı</label>
                    <select wire:model.defer="selectedSourceType" class="w-full p-3 bg-neutral-100 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20">
                        <option value="whatsapp">WhatsApp Grubu</option>
                        <option value="telegram">Telegram Kanalı</option>
                        <option value="facebook">Facebook Nakliye Grubu</option>
                        <option value="web_url">Özel Lojistik Web Sayfası</option>
                    </select>
                </div>

                <div class="space-y-1.5">
                    <label class="font-semibold text-neutral-500">Grup / Kanal Adı</label>
                    <input type="text" wire:model.defer="newSourceName" placeholder="Örn: Marmara Nakliyeciler WhatsApp" class="w-full p-3 bg-neutral-100 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20">
                    @error('newSourceName') <span class="text-red-500 text-[10px] block mt-1 pl-1 font-semibold">{{ $message }}</span> @enderror
                </div>

                <div class="space-y-1.5">
                    <label class="font-semibold text-neutral-500">Kaynak Bağlantı Tanımlayıcı (Link / Grup Başlığı)</label>
                    <input type="text" wire:model.defer="newSourceIdentifier" placeholder="Örn: t.me/nakliyegrubu veya WhatsApp Başlığı" class="w-full p-3 bg-neutral-100 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20">
                    @error('newSourceIdentifier') <span class="text-red-500 text-[10px] block mt-1 pl-1 font-semibold">{{ $message }}</span> @enderror
                </div>

                <button type="submit" class="w-full btn-apple-primary py-3 text-xs">
                    Kaynağı Sisteme Bağla
                </button>
            </form>
        </div>

        <!-- Kart 2: Yapay Zeka Model Ayarları -->
        <div class="apple-glass rounded-3xl p-6 space-y-4">
            <h3 class="text-sm font-bold text-neutral-800 dark:text-neutral-200 uppercase tracking-wider pb-3 border-b border-neutral-100 dark:border-neutral-800/50">YAPAY ZEKA API YÖNETİCİSİ</h3>

            <div class="space-y-4 text-xs">
                <div class="space-y-1.5">
                    <label class="font-semibold text-neutral-500">Aktif Dil Modeli (LLM Provider)</label>
                    <select wire:model.live="activeLlm" class="w-full p-3 bg-neutral-100 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20">
                        <option value="gemini">Google Gemini-1.5-Flash (Hızlı, Güvenli ve Sizin Anahtarınız!)</option>
                        <option value="kimi">Moonshot AI (Kimi-v1-8k)</option>
                        <option value="claude">Anthropic Claude-3.5-Sonnet (Güçlü Müşteri Asistanı)</option>
                    </select>
                </div>

                <div class="p-4 bg-brand-500/5 border border-brand-500/10 rounded-2xl space-y-2">
                    <span class="font-bold text-brand-500 block">Akıllı Fallback / Kota Yönetimi</span>
                    <p class="text-[11px] text-neutral-500 leading-relaxed">
                        Sistem, aktif seçtiğiniz modelin günlük kotaları bittiğinde veya sunucu hatası aldığında, arka planda otomatik olarak en ekonomik olan yedek modele geçiş gerçekleştirir.
                    </p>
                </div>
            </div>
        </div>

        <!-- Kart 3: WhatsApp QR Entegrasyon Simülatörü -->
        <div class="apple-glass rounded-3xl p-6 space-y-4">
            <h3 class="text-sm font-bold text-neutral-800 dark:text-neutral-200 uppercase tracking-wider pb-3 border-b border-neutral-100 dark:border-neutral-800/50">OTONOM SÜRÜCÜ WHATSAPP BAĞLANTISI</h3>

            <div class="flex flex-col items-center justify-center space-y-3 text-center">
                <div class="w-32 h-32 bg-white p-2.5 rounded-2xl border border-neutral-200/60 flex items-center justify-center relative overflow-hidden shadow-apple-sm">
                    <svg class="w-full h-full text-neutral-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M12 4v1m-3 3h3m-3 3h3m-3 3h3m-3 3h3m6-12v1m-3 3h3m-3 3h3m-3 3h3m-3 3h3M4 6V4a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2zm0 14v-2a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2zM14 6V4a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    <div class="absolute inset-0 bg-emerald-500/10 flex items-center justify-center font-bold text-emerald-600 text-[10px] tracking-wider uppercase">BAĞLANTI AKTİF</div>
                </div>
                <div class="text-xs">
                    <span class="font-bold text-emerald-500 block">Sanal Hat Durumu: AKTİF</span>
                    <span class="text-[11px] text-neutral-400 mt-1 block">Burner test numaramız sisteme QR kod ile bağlıdır, lojistik grupları dinleniyor.</span>
                </div>
            </div>
        </div>

    </div>

    <!-- Orta Bölüm: Canlı Akan Çözümleme Terminali ve Yapay Zeka Test Alanı -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 items-start">

        <!-- Sol: Yapay Zeka Test İstasyonu -->
        <div class="apple-glass rounded-3xl p-6 space-y-5">
            <h3 class="text-sm font-bold text-neutral-800 dark:text-neutral-200 uppercase tracking-wider pb-3 border-b border-neutral-100 dark:border-neutral-800/50">YAPAY ZEKA TEST İSTASYONU</h3>

            <p class="text-xs text-neutral-500">WhatsApp'tan gelen karmaşık, bozuk Türkçe lojistik mesajını buraya yazıp yapay zekanın bunu saniyeler içinde nasıl temiz bir ilana dönüştürdüğünü test edin!</p>

            <div class="space-y-4 text-xs">
                <textarea wire:model.defer="testRawText" rows="4" class="w-full p-4 bg-neutral-50 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20"></textarea>

                <button wire:click="runAiParserTest" class="w-full btn-apple-brand py-3 text-xs flex items-center justify-center space-x-2">
                    <span wire:loading.remove wire:target="runAiParserTest">Yapay Zekaya Gönder ve Çözümle</span>
                    <span wire:loading wire:target="runAiParserTest" class="w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
                </button>
            </div>

            <!-- Çözümleme Rapor Paneli (Sonuç) -->
            @if($parsedResult)
                <div class="p-5 bg-neutral-900 text-white dark:bg-neutral-950 rounded-2xl font-mono leading-relaxed space-y-2 animate-slide-up text-xs">
                    <span class="text-brand-400 block font-sans font-bold">🎯 YAPAY ZEKA ÇIKTI RAPORU:</span>
                    <p class="text-neutral-200 font-semibold">{{ isset($parsedResult['success']) && $parsedResult['success'] === true ? 'Çözümleme Başarılı!' : 'Mesaj Elendi (Yük İlanı Değil veya Eksik Bilgi)' }}</p>
                    @if(isset($parsedResult['success']) && $parsedResult['success'] === true)
                        <div class="space-y-1 text-neutral-300">
                            <div>[Başlangıç]: {{ $parsedResult['pickup_location'] }}</div>
                            <div>[Varış]: {{ $parsedResult['delivery_location'] }}</div>
                            <div>[Yük Cinsi]: {{ $parsedResult['goods_type'] }}</div>
                            <div>[Ağırlık]: {{ number_format($parsedResult['weight'] ?? 0) }} kg</div>
                            <div>[Navlun Fiyatı]: &#8378;{{ number_format($parsedResult['price'] ?? 0, 2) }}</div>
                            <div>[Telefon]: {{ $parsedResult['sender_phone'] }}</div>
                            <div>[Çözümleyen Model]: {{ $parsedResult['parsed_by_llm'] }}</div>
                        </div>
                    @else
                        <p class="text-red-400 text-[11px] mt-1">Bu mesaj güvenlik filtrelerini aşamadığı için sisteme ilan olarak kaydedilmeyecektir.</p>
                    @endif
                </div>
            @endif
        </div>

        <!-- Sağ: Canlı Otonom Kazıma Terminali (Linux Terminal UI) -->
        <div class="bg-neutral-950 text-emerald-400 p-6 rounded-3xl font-mono text-[11px] leading-relaxed shadow-apple-lg border border-neutral-800 h-[380px] flex flex-col justify-between relative overflow-hidden">
            <!-- Üst Başlık -->
            <div class="flex justify-between items-center border-b border-neutral-800 pb-2.5 mb-3">
                <div class="flex items-center space-x-1.5">
                    <span class="w-3 h-3 rounded-full bg-red-500"></span>
                    <span class="w-3 h-3 rounded-full bg-amber-500"></span>
                    <span class="w-3 h-3 rounded-full bg-emerald-500"></span>
                    <span class="text-[10px] text-neutral-500 font-sans font-bold uppercase tracking-wider pl-2">OTONOM TERMINAL STREAM (CANLI AKIŞ)</span>
                </div>
            </div>

            <!-- Log Akışı -->
            <div class="flex-1 overflow-y-auto space-y-1.5 scrollbar-thin">
                @foreach($this->terminalLogs as $log)
                    <div class="animate-fade-in">{{ $log }}</div>
                @endforeach
            </div>

            <!-- Alt Durum Barı -->
            <div class="border-t border-neutral-800 pt-2.5 mt-3 text-neutral-500 flex justify-between items-center text-[10px]">
                <span>Status: LISTENING (WhatsApp API active)</span>
                <span>Active Model: {{ strtoupper($activeLlm) }}</span>
            </div>
        </div>

    </div>

    <!-- YAPAY ZEKA İLAN HAVUZU (CANLI AKIŞ) -->
    <div class="apple-glass rounded-3xl p-6 space-y-4">
        <h3 class="text-sm font-bold text-neutral-800 dark:text-neutral-200 uppercase tracking-wider pb-3 border-b border-neutral-100 dark:border-neutral-800/50">YAPAY ZEKA İLAN HAVUZU (CANLI AKIŞ)</h3>

        <table class="w-full text-left border-collapse text-xs">
            <thead>
                <tr class="text-neutral-400 font-bold border-b border-neutral-100 dark:border-neutral-800/50">
                    <th class="pb-3">Kaynak Grup</th>
                    <th class="pb-3">Çözümlenen Rota</th>
                    <th class="pb-3 font-mono">Yük / Tonaj</th>
                    <th class="pb-3">İletişim / Tel</th>
                    <th class="pb-3">Önerilen Fiyat</th>
                    <th class="pb-3">Süzme Durumu</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/30">
                @forelse($this->getScrapedLoads() as $load)
                    <tr class="hover:bg-neutral-50/50 dark:hover:bg-neutral-800/20 transition-all duration-200">
                        <td class="py-3 font-bold text-neutral-900 dark:text-white">{{ $load->scraper->name ?? 'Yapay Zeka Test' }}</td>
                        <td class="py-3">
                            @if($load->status === 'parsed_success')
                                <span class="font-semibold text-neutral-950 dark:text-white">
                                    {{ $load->pickup_location }} -> {{ $load->delivery_location }}
                                </span>
                            @else
                                <span class="text-neutral-400 italic">Grup Metni Süzülemedi (Yetersiz Veri / Alakasız)</span>
                            @endif
                        </td>
                        <td class="py-3 font-mono text-[11px] text-neutral-500">
                            @if($load->status === 'parsed_success')
                                {{ $load->goods_type }} ({{ number_format($load->weight) }} kg)
                            @else
                                -
                            @endif
                        </td>
                        <td class="py-3 font-semibold text-brand-500">
                            @if($load->formatted_phone && $load->formatted_phone !== 'Bilinmiyor')
                                @if($load->formatted_phone === 'LID')
                                    <!-- 🛡️ KUSURSUZ GİZLİLİK VE VERİ KALİTESİ KORUMASI -->
                                    <span class="text-neutral-400 font-medium text-xs italic" title="Bu kullanıcı rehberinizde kayıtlı olmadığı için numarası gizlenmiştir.">Gizli Numara (LID)</span>
                                @else
                                    <!-- 🚀 TIKLANABİLİR MASKE LİNKİ (533 444 ** ** formatında) -->
                                    <div class="flex items-center space-x-2">
                                        <a href="tel:{{ preg_replace('/[^0-9+]/', '', $load->sender_phone) }}" class="hover:underline text-brand-500 hover:text-brand-600 transition-colors font-bold text-xs">
                                            {{ substr($load->formatted_phone, 0, 7) . ' ** **' }}
                                        </a>
                                        <!-- WhatsApp üzerinden mesaj şablonu butonu -->
                                        @php
                                            $msgTemplate = "Merhaba, NavlunIQ platformundan ulaşıyorum. Profili doğrulanmış bir şoför olarak, yayınlamış olduğunuz " . $load->pickup_location . " -> " . $load->delivery_location . " (" . $load->goods_type . " - " . number_format($load->weight) . " kg) ilanınızla ilgili bilgi almak istiyorum.";
                                            $encodedMsg = urlencode($msgTemplate);
                                        @endphp
                                        <a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $load->sender_phone) }}?text={{ $encodedMsg }}" target="_blank" class="p-1 rounded-md bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-600 transition-all duration-300">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                                        </a>
                                    </div>
                                @endif
                            @else
                                <span class="text-neutral-400 font-medium">Bilinmiyor</span>
                            @endif
                        </td>
                        <td class="py-3 font-bold">
                            <!-- 🚀 KESİN ÇÖZÜM: TL Simgesi yerine W3C HTML Entity kodunu enjekte ederek uyuşmazlığı çözüyoruz -->
                            &#8378;{{ number_format($load->price, 2) }}
                        </td>
                        <td class="py-3">
                            <span class="px-2.5 py-0.5 rounded-full font-bold text-[10px] bg-emerald-500/10 text-emerald-600">
                                İlan Çözümlendi
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="py-8 text-center text-neutral-400">Yapay zeka tarafından süzülmüş otonom bir ilan henüz bulunmuyor. Gruptan test mesajı göndererek canlı akışı izleyebilirsiniz!</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- Bağlı Olan Kaynakların Listesi -->
    <div class="apple-glass rounded-3xl p-6 space-y-4">
        <h3 class="text-sm font-bold text-neutral-800 dark:text-neutral-200 uppercase tracking-wider pb-3 border-b border-neutral-100 dark:border-neutral-800/50">BAĞLI KAZIMA KAYNAKLARI</h3>

        <table class="w-full text-left border-collapse text-xs">
            <thead>
                <tr class="text-neutral-400 font-bold border-b border-neutral-100 dark:border-neutral-800/50">
                    <th class="pb-3">Kaynak Adı</th>
                    <th class="pb-3">Platform</th>
                    <th class="pb-3">Grup / Adres</th>
                    <th class="pb-3">Durum</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/30">
                @forelse($this->getScrapers() as $src)
                    <tr>
                        <td class="py-3 font-bold text-neutral-900 dark:text-white">{{ $src->name }}</td>
                        <td class="py-3 capitalize text-neutral-500">{{ $src->type }}</td>
                        <td class="py-3 font-mono text-[11px] text-neutral-400">{{ $src->source_identifier }}</td>
                        <td class="py-3">
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-500/10 text-emerald-600">Aktif Dinleniyor</span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="py-6 text-center text-neutral-400">Herhangi bir kayıtlı grup kaynağı bulunamadı.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
