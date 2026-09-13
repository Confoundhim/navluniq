<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Illuminate\Support\Facades\Auth;

new
#[Layout('components.layouts.cargo-owner')]
#[Title('Kayıtlı Adres Defterim')]
class extends Component {
    public bool $modalOpen = false;

    // Form Alanları
    public string $title = '';
    public string $contact_person = '';
    public string $contact_phone = '';
    public string $city = 'Ankara';
    public string $district = 'Yenimahalle';
    public string $address_detail = '';
    public string $type = 'both'; // 'pickup', 'delivery', 'both'

    // Kayıtlı Adres Listesi (Reaktif / State)
    public array $addresses = [
        [
            'id' => 1,
            'title' => 'Ankara Merkez Depo (Ostim OSB)',
            'contact_person' => 'Ahmet Yılmaz',
            'contact_phone' => '0532 100 20 30',
            'city' => 'Ankara',
            'district' => 'Yenimahalle',
            'address_detail' => 'Ostim OSB 1234. Cadde No:12 Yenimahalle / Ankara',
            'type' => 'pickup',
        ],
        [
            'id' => 2,
            'title' => 'İzmir Aliağa Fabrika & Depolama',
            'contact_person' => 'Kemal Sönmez',
            'contact_phone' => '0542 300 40 50',
            'city' => 'İzmir',
            'district' => 'Aliağa',
            'address_detail' => 'Aliağa Organize Sanayi Bölgesi 4. Sokak No:5 Aliağa / İzmir',
            'type' => 'delivery',
        ],
        [
            'id' => 3,
            'title' => 'İstanbul Hadımköy Dağıtım Merkezi',
            'contact_person' => 'Mustafa Kaya',
            'contact_phone' => '0533 555 66 77',
            'city' => 'İstanbul',
            'district' => 'Arnavutköy',
            'address_detail' => 'Hadımköy Mah. Lojistik Cad. No:88 Arnavutköy / İstanbul',
            'type' => 'both',
        ],
    ];

    public function openModal(): void
    {
        $this->reset(['title', 'contact_person', 'contact_phone', 'address_detail']);
        $this->modalOpen = true;
    }

    public function saveAddress(): void
    {
        $this->validate([
            'title' => 'required|min:3',
            'contact_person' => 'required|min:3',
            'contact_phone' => 'required|min:10',
            'city' => 'required',
            'district' => 'required',
            'address_detail' => 'required|min:10',
        ], [
            'title.required' => 'Lütfen adrese bir başlık veriniz (Örn: Merkez Depo).',
            'contact_person.required' => 'Yetkili kişi adı zorunludur.',
            'contact_phone.required' => 'İletişim telefonu zorunludur.',
            'address_detail.required' => 'Açık adres detayını eksiksiz giriniz.',
        ]);

        $this->addresses[] = [
            'id' => count($this->addresses) + 1,
            'title' => $this->title,
            'contact_person' => $this->contact_person,
            'contact_phone' => $this->contact_phone,
            'city' => $this->city,
            'district' => $this->district,
            'address_detail' => $this->address_detail,
            'type' => $this->type,
        ];

        $this->modalOpen = false;
        session()->flash('success_message', 'Yeni adres defterinize başarıyla kaydedildi. İlan açarken doğrudan seçebilirsiniz.');
    }

    public function deleteAddress(int $id): void
    {
        $this->addresses = array_values(array_filter($this->addresses, fn($a) => $a['id'] !== $id));
        session()->flash('success_message', 'Adres kaydı defterinizden kaldırıldı.');
    }
}; ?>

<div class="space-y-6">

    <!-- Başarı Bildirimi -->
    @if (session()->has('success_message'))
        <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-xs font-semibold flex items-center justify-between">
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>{{ session('success_message') }}</span>
            </div>
        </div>
    @endif

    <!-- Üst Başlık & Ekleme Butonu -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-neutral-800 pb-4">
        <div>
            <h2 class="text-xl font-bold text-white tracking-tight flex items-center gap-2">
                <span class="p-1.5 rounded-lg bg-brand-500/10 text-brand-400">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                </span>
                <span>Kayıtlı Adres Defterim</span>
            </h2>
            <p class="text-xs text-neutral-400 mt-1">Sık kullandığınız yükleme ve teslimat noktalarını kaydedin, ilan açarken tek tıkla seçin.</p>
        </div>

        <button type="button" wire:click="openModal" class="px-5 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-bold text-xs shadow-lg shadow-brand-500/20 transition-all flex items-center gap-2 active:scale-95">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            <span>Yeni Adres Tanımla</span>
        </button>
    </div>

    <!-- Adres Kartları Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        @forelse($addresses as $addr)
            <div class="bg-neutral-900 border border-neutral-800 hover:border-neutral-700 rounded-2xl p-6 flex flex-col justify-between space-y-4 transition-all duration-200">

                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-bold text-white tracking-tight">{{ $addr['title'] }}</h3>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase {{ $addr['type'] === 'pickup' ? 'bg-blue-500/10 text-blue-400 border border-blue-500/20' : ($addr['type'] === 'delivery' ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-brand-500/10 text-brand-400 border border-brand-500/20') }}">
                            {{ $addr['type'] === 'pickup' ? 'Yükleme Noktası' : ($addr['type'] === 'delivery' ? 'Teslimat Noktası' : 'Çıkış & Varış') }}
                        </span>
                    </div>

                    <p class="text-xs text-neutral-300 bg-neutral-950 p-3 rounded-xl border border-neutral-800/80 leading-relaxed">
                        {{ $addr['address_detail'] }}
                    </p>

                    <div class="space-y-1 text-xs text-neutral-400 pt-1">
                        <div class="flex items-center justify-between">
                            <span class="text-neutral-500">Yetkili / Sorumlu:</span>
                            <span class="text-neutral-200 font-medium">{{ $addr['contact_person'] }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-neutral-500">İletişim:</span>
                            <span class="text-neutral-200 font-mono">{{ $addr['contact_phone'] }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-neutral-500">Bölge:</span>
                            <span class="text-neutral-200 font-medium">{{ $addr['district'] }} / {{ $addr['city'] }}</span>
                        </div>
                    </div>
                </div>

                <div class="pt-3 border-t border-neutral-800 flex items-center justify-between text-xs">
                    <span class="text-neutral-500 font-mono text-[11px]">ID #ADR-{{ $addr['id'] }}</span>
                    <button type="button" wire:click="deleteAddress({{ $addr['id'] }})" wire:confirm="Bu adresi defterinizden silmek istediğinize emin misiniz?" class="text-rose-400 hover:text-rose-300 font-medium flex items-center gap-1 transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                        <span>Sil</span>
                    </button>
                </div>

            </div>
        @empty
            <div class="col-span-full bg-neutral-900 border border-neutral-800 rounded-2xl p-12 text-center space-y-4">
                <div class="w-16 h-16 rounded-full bg-neutral-800 flex items-center justify-center mx-auto text-neutral-500">
                    <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                </div>
                <div class="space-y-1">
                    <h4 class="text-base font-bold text-white">Henüz Kayıtlı Adresiniz Yok</h4>
                    <p class="text-xs text-neutral-400 max-w-sm mx-auto">Sık kullandığınız yükleme depolarınızı veya teslimat noktalarınızı kaydedin.</p>
                </div>
            </div>
        @endforelse
    </div>

    <!-- Yeni Adres Ekleme Modalı -->
    @if($modalOpen)
        <div class="fixed inset-0 z-[9999] overflow-y-auto flex items-start sm:items-center justify-center p-4">
            <div class="fixed inset-0 bg-neutral-950/85 backdrop-blur-md transition-opacity" wire:click="$set('modalOpen', false)"></div>
            <div class="relative z-10 w-full max-w-lg bg-neutral-900 border border-neutral-800 rounded-2xl p-6 shadow-2xl space-y-6 text-left">

                <div class="flex items-center justify-between border-b border-neutral-800 pb-4">
                    <h3 class="text-base font-bold text-white flex items-center gap-2">
                        <span class="p-1.5 rounded-lg bg-brand-500/10 text-brand-500">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                            </svg>
                        </span>
                        <span>Yeni Kayıtlı Adres Ekle</span>
                    </h3>
                    <button wire:click="$set('modalOpen', false)" class="text-neutral-400 hover:text-white">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="space-y-4 text-xs">
                    <div>
                        <label class="block font-medium text-neutral-300 mb-1">Adres Başlığı <span class="text-brand-500">*</span></label>
                        <input type="text" wire:model="title" placeholder="Örn: Ostim Merkez Depo" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-4 py-2.5 text-white focus:border-brand-500 focus:outline-none">
                        @error('title') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block font-medium text-neutral-300 mb-1">Yetkili Kişi <span class="text-brand-500">*</span></label>
                            <input type="text" wire:model="contact_person" placeholder="Ad Soyad" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-4 py-2.5 text-white focus:border-brand-500 focus:outline-none">
                            @error('contact_person') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block font-medium text-neutral-300 mb-1">Yetkili Telefon <span class="text-brand-500">*</span></label>
                            <input type="text" wire:model="contact_phone" placeholder="05XX XXX XX XX" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-4 py-2.5 text-white font-mono focus:border-brand-500 focus:outline-none">
                            @error('contact_phone') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block font-medium text-neutral-300 mb-1">İl <span class="text-brand-500">*</span></label>
                            <input type="text" wire:model="city" placeholder="Örn: Ankara" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-4 py-2.5 text-white focus:border-brand-500 focus:outline-none">
                        </div>

                        <div>
                            <label class="block font-medium text-neutral-300 mb-1">İlçe <span class="text-brand-500">*</span></label>
                            <input type="text" wire:model="district" placeholder="Örn: Yenimahalle" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-4 py-2.5 text-white focus:border-brand-500 focus:outline-none">
                        </div>
                    </div>

                    <div>
                        <label class="block font-medium text-neutral-300 mb-1">Açık Adres Detayı <span class="text-brand-500">*</span></label>
                        <textarea wire:model="address_detail" rows="3" placeholder="Mahalle, Cadde, Sokak, No ve Depo Kapı Numarası..." class="w-full bg-neutral-950 border border-neutral-800 rounded-xl p-3 text-white placeholder-neutral-600 focus:border-brand-500 focus:outline-none"></textarea>
                        @error('address_detail') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block font-medium text-neutral-300 mb-1">Adres Kullanım Amacı</label>
                        <select wire:model="type" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-3.5 py-2.5 text-neutral-200 focus:border-brand-500 focus:outline-none">
                            <option value="both">Hem Yükleme Hem Teslimat Noktası</option>
                            <option value="pickup">Sadece Yükleme (Çıkış Noktası)</option>
                            <option value="delivery">Sadece Teslimat (Varış Noktası)</option>
                        </select>
                    </div>
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="button" wire:click="$set('modalOpen', false)" class="flex-1 px-4 py-2.5 rounded-xl bg-neutral-800 hover:bg-neutral-700 text-neutral-300 text-xs font-semibold transition-colors">
                        Vazgeç
                    </button>
                    <button type="button" wire:click="saveAddress" class="flex-1 px-4 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white text-xs font-bold shadow-lg shadow-brand-500/20 transition-all">
                        Adresi Kaydet
                    </button>
                </div>

            </div>
        </div>
    @endif

</div>
