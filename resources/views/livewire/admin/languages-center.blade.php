<?php

use Livewire\Volt\Component;
use App\Models\Language;
use App\Models\Translation;

new class extends Component {
    // Aktif Seçili Dil
    public string $selectedLangCode = 'en';

    // Yeni Dil Form Verileri
    public string $newLangCode = '';
    public string $newLangName = '';

    // Çeviri Güncelleme Dizisi
    public array $translations = [];

    public function mount()
    {
        if (!auth()->user()->can('manage settings')) {
            abort(403, 'Bu alana erişim yetkiniz bulunmamaktadır.');
        }

        $this->loadTranslations();
    }

    

    /**
     * Seçili Dildeki Çevirileri Yükler
     */
    public function loadTranslations()
    {
        $records = Translation::where('language_code', $this->selectedLangCode)->get();
        $this->translations = [];

        foreach ($records as $rec) {
            $this->translations[$rec->id] = [
                'key' => $rec->key,
                'value' => $rec->value,
            ];
        }
    }

    /**
     * Dil Değişince Çevirileri Güncelle
     */
    public function updatedSelectedLangCode()
    {
        $this->loadTranslations();
    }

    /**
     * Yeni Dil Ekleme
     */
    public function addLanguage()
    {
        $this->validate([
            'newLangCode' => 'required|string|max:5|unique:languages,code',
            'newLangName' => 'required|string|min:2'
        ], [
            'newLangCode.required' => 'Dil kodu girmek zorunludur.',
            'newLangCode.unique' => 'Bu dil kodu zaten eklenmiş.'
        ]);

        Language::create([
            'code' => strtolower($this->newLangCode),
            'name' => $this->newLangName,
            'is_default' => false
        ]);

        $this->selectedLangCode = strtolower($this->newLangCode);
        $this->reset(['newLangCode', 'newLangName']);
        session()->flash('success', 'Yeni dil paketi sisteme eklendi.');
        $this->loadTranslations();
    }

    /**
     * Varsayılan Dil Yap
     */
    public function setDefaultLanguage(string $code)
    {
        Language::query()->update(['is_default' => false]);
        Language::where('code', $code)->update(['is_default' => true]);

        session()->flash('success', "{$code} dili platformun varsayılan dili olarak belirlendi.");
    }

    /**
     * Çeviri Sözlüğü Güncellemelerini Kaydet
     */
    public function saveTranslations()
    {
        foreach ($this->translations as $id => $data) {
            Translation::where('id', $id)->update([
                'value' => $data['value']
            ]);
        }

        session()->flash('success', 'Çeviri sözlüğü güncellendi ve tüm siteye anında uygulandı!');
    }

    /**
     * Dilleri getirir
     */
    private function getLanguages()
    {
        return Language::all();
    }
}; ?>

<div class="max-w-7xl mx-auto space-y-8 animate-fade-in">
    <!-- Bildirim Banner'ları -->
    @if (session()->has('success'))
        <div class="p-4 bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200/50 dark:border-emerald-800/30 text-emerald-600 dark:text-emerald-400 text-sm rounded-2xl flex items-center space-x-2 animate-fade-in shadow-apple-sm">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span>{{ session('success') }}</span>
        </div>
    @endif

    <!-- Üst Başlık ve Akıllı Tohumlayıcı -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">Çoklu Dil ve Çeviri Yöneticisi</h1>
            <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">Sitedeki tüm sabit metinlerin diğer dillerdeki karşılıklarını dinamik sözlük üzerinden yönetin.</p>
        </div>

        @if(\App\Models\Language::count() <= 1)
@endif
    </div>

    <!-- Üst Grid: Dil Tanımlama ve Varsayılan Seçici -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 items-start">

        <!-- Kart 1: Yeni Dil Tanımlama -->
        <div class="apple-glass rounded-3xl p-6 space-y-4 text-xs">
            <h3 class="text-sm font-bold text-neutral-800 dark:text-neutral-200 uppercase tracking-wider pb-3 border-b border-neutral-100 dark:border-neutral-800/50">YENİ DİL EKLE</h3>

            <form wire:submit.prevent="addLanguage" class="space-y-4">
                <div class="space-y-1.5">
                    <label class="font-semibold text-neutral-500">ISO Dil Kodu (Örn: 'en', 'ru', 'ar')</label>
                    <input type="text" wire:model="newLangCode" placeholder="en" maxlength="5" class="w-full p-3 bg-neutral-100 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none uppercase">
                    @error('newLangCode') <span class="text-red-500 text-[10px] block mt-1 pl-1 font-semibold">{{ $message }}</span> @enderror
                </div>

                <div class="space-y-1.5">
                    <label class="font-semibold text-neutral-500">Dil Resmi Adı</label>
                    <input type="text" wire:model="newLangName" placeholder="English" class="w-full p-3 bg-neutral-100 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl focus:outline-none">
                    @error('newLangName') <span class="text-red-500 text-[10px] block mt-1 pl-1 font-semibold">{{ $message }}</span> @enderror
                </div>

                <button type="submit" class="w-full btn-apple-primary py-3 text-xs">
                    Dili Sisteme Kaydet
                </button>
            </form>
        </div>

        <!-- Kart 2: Tanımlı Diller ve Varsayılan Butonları -->
        <div class="lg:col-span-2 apple-glass rounded-3xl p-6 space-y-4">
            <h3 class="text-sm font-bold text-neutral-800 dark:text-neutral-200 uppercase tracking-wider pb-3 border-b border-neutral-100 dark:border-neutral-800/50">TANIMLI DİLLER & VARSAYILAN SEÇİMİ</h3>

            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="text-neutral-400 font-bold border-b border-neutral-100 dark:border-neutral-800/50">
                        <th class="pb-3">Dil Adı</th>
                        <th class="pb-3">ISO Kodu</th>
                        <th class="pb-3">Varsayılan Durum</th>
                        <th class="pb-3 text-right">Aksiyon</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/30">
                    @forelse($this->getLanguages() as $lang)
                        <tr class="hover:bg-neutral-50/50 dark:hover:bg-neutral-800/20 transition-all duration-200">
                            <td class="py-3 font-bold text-neutral-900 dark:text-white">{{ $lang->name }}</td>
                            <td class="py-3 font-mono text-[11px] uppercase text-neutral-400">{{ $lang->code }}</td>
                            <td class="py-3">
                                @if($lang->is_default)
                                    <span class="px-2.5 py-0.5 rounded-full font-bold text-[10px] bg-emerald-500/10 text-emerald-600">VARSAYILAN DİL</span>
                                @else
                                    <span class="text-neutral-400 text-[11px]">İkincil Dil</span>
                                @endif
                            </td>
                            <td class="py-3 text-right">
                                @if(!$lang->is_default)
                                    <button wire:click="setDefaultLanguage('{{ $lang->code }}')" class="btn-apple-secondary py-1 px-3 text-[10px]">Varsayılan Yap</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-6 text-center text-neutral-400">Herhangi bir tanımlı dil bulunmuyor.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

    </div>

    <!-- Alt Bölüm: Dinamik Çeviri Dosyası (JSON Dictionary Table) -->
    <div class="apple-glass rounded-3xl p-6 space-y-6">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 pb-4 border-b border-neutral-100 dark:border-neutral-800/50">
            <div>
                <h3 class="text-sm font-bold text-neutral-800 dark:text-neutral-200 uppercase tracking-wider">DİNAMİK ÇEVİRİ SÖZLÜĞÜ (JSON DICTIONARY)</h3>
                <p class="text-xs text-neutral-400 mt-1">Sol taraftaki Türkçe anahtarın seçilen dildeki karşılığını düzenleyin.</p>
            </div>

            <!-- Çevrilecek Dil Seçici -->
            <div class="flex items-center space-x-2 text-xs">
                <label class="font-bold text-neutral-500">Düzenlenen Dil:</label>
                <select wire:model.live="selectedLangCode" class="p-2 bg-neutral-100 dark:bg-neutral-900 border border-neutral-200/40 text-neutral-900 dark:text-white rounded-xl font-bold uppercase">
                    @foreach($this->getLanguages() as $l)
                        @if($l->code !== 'tr')
                            <option value="{{ $l->code }}">{{ $l->name }} ({{ strtoupper($l->code) }})</option>
                        @endif
                    @endforeach
                </select>
            </div>
        </div>

        <!-- Çeviri Tablosu -->
        <form wire:submit.prevent="saveTranslations" class="space-y-6">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="text-neutral-400 font-bold border-b border-neutral-100 dark:border-neutral-800/50">
                            <th class="p-3 w-1/2">Türkçe Sabit Metin (Key)</th>
                            <th class="p-3 w-1/2">Seçilen Dildeki Karşılığı (Value)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/30">
                        @forelse($translations as $id => $item)
                            <tr>
                                <td class="p-3 font-bold text-neutral-900 dark:text-white bg-neutral-50/50 dark:bg-neutral-900/50 rounded-l-xl">
                                    {{ $item['key'] }}
                                </td>
                                <td class="p-3">
                                    <input type="text" wire:model="translations.{{ $id }}.value" class="w-full p-2.5 bg-white dark:bg-neutral-800 border border-neutral-200/40 text-neutral-900 dark:text-white text-xs font-semibold rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500/20">
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="2" class="p-8 text-center text-neutral-400">Bu dil için henüz tanımlanmış çeviri sözlüğü bulunmuyor.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if(count($translations) > 0)
                <div class="flex justify-end pt-4 border-t border-neutral-100 dark:border-neutral-800/50">
                    <button type="submit" class="btn-apple-brand py-3.5 px-6 text-xs font-semibold">
                        Çeviri Sözlüğünü Kaydet ve Tüm Siteye Uygula
                    </button>
                </div>
            @endif
        </form>
    </div>
</div>
