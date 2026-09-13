<?php

use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Models\Load;
use App\Models\CargoOwnerProfile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon;

new
#[Layout('components.layouts.cargo-owner')]
#[Title('Yeni Yük İlanı Oluştur')]
class extends Component {
    use WithFileUploads;

    public int $currentStep = 1;

    public string $pickup_location = '';
    public string $delivery_location = '';
    public string $pickup_date = '';
    public string $delivery_date = '';
    public string $selected_saved_pickup = '';
    public string $selected_saved_delivery = '';

    public string $goods_type = 'Paletli Yük';
    public string $vehicle_type = 'tir';
    public string $weight = '';
    public string $volume = '';
    public string $e_irsaliye_no = '';
    public $e_irsaliye_file = null;

    public string $price = '';
    public bool $terms_accepted = false;

    public array $savedAddresses = [];

    public array $vehicleTypeOptions = [
        'tir' => 'Tır',
        'kirkayak' => 'Kırkayak',
        '10_teker_kamyon' => '10 Teker Kamyon',
        '8_teker_kamyon' => '8 Teker Kamyon',
        '6_teker_kamyon' => '6 Teker Kamyon',
        'kamyonet' => 'Kamyonet',
        'uzun_panelvan' => 'Uzun Panelvan',
        'orta_panelvan' => 'Orta Panelvan',
        'minivan' => 'Minivan',
        'otomobil' => 'Otomobil'
    ];

    public array $goodsTypes = [
        'Paletli Yük',
        'Dökme Yük',
        'Koli / Paket',
        'Ev / Ofis Eşyası',
        'Soğuk Zincir (Frigo)',
        'İnşaat / Yapı Malzemesi',
        'Makine & Ağır Sanayi',
        'Tehlikeli Madde (ADR)',
        'Diğer / Özel'
    ];

    public function mount(): void
    {
        $this->pickup_date = Carbon::now()->addDay()->format('Y-m-d');
        $this->delivery_date = Carbon::now()->addDays(2)->format('Y-m-d');
    }

    public function getVehicleTypeName(): string
    {
        return $this->vehicleTypeOptions[$this->vehicle_type] ?? $this->vehicle_type;
    }

    public function updatedSelectedSavedPickup(string $val): void
    {
        foreach ($this->savedAddresses as $addr) {
            if ($addr['id'] === $val) {
                $this->pickup_location = $addr['address'];
                break;
            }
        }
    }

    public function updatedSelectedSavedDelivery(string $val): void
    {
        foreach ($this->savedAddresses as $addr) {
            if ($addr['id'] === $val) {
                $this->delivery_location = $addr['address'];
                break;
            }
        }
    }

    public function nextStep(): void
    {
        if ($this->currentStep === 1) {
            $this->validate([
                'pickup_location' => 'required|min:5',
                'delivery_location' => 'required|min:5',
                'pickup_date' => 'required|date',
                'delivery_date' => 'required|date|after_or_equal:pickup_date',
            ], [
                'pickup_location.required' => 'Lütfen yükleme başlangıç adresini belirtin.',
                'delivery_location.required' => 'Lütfen teslimat varış adresini belirtin.',
                'delivery_date.after_or_equal' => 'Teslimat tarihi, yükleme tarihinden önce olamaz.',
            ]);
            $this->currentStep = 2;
        } elseif ($this->currentStep === 2) {
            $this->validate([
                'goods_type' => 'required',
                'vehicle_type' => 'required',
                'weight' => 'required|numeric|min:1',
                'e_irsaliye_no' => 'required|min:8',
                'e_irsaliye_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
            ], [
                'weight.required' => 'Lütfen tahmini ağırlık giriniz.',
                'e_irsaliye_no.required' => 'Yasal zorunluluk gereği e-İrsaliye numarası girmelisiniz.',
            ]);
            $this->currentStep = 3;
        }
    }

    public function previousStep(): void
    {
        if ($this->currentStep > 1) {
            $this->currentStep--;
        }
    }

    public function submitLoad(): void
    {
        $this->validate([
            'price' => 'required|numeric|min:500',
            'terms_accepted' => 'accepted',
        ], [
            'price.required' => 'Lütfen hedef navlun bütçenizi belirtin.',
            'price.min' => 'Minimum navlun bütçesi 500 ₺ olmalıdır.',
            'terms_accepted.accepted' => 'Lütfen taşımacılık ve PayTR havuz şartlarını onaylayın.',
        ]);

        $user = Auth::user();
        if (!$user) {
            return;
        }

        $cargoOwnerProfile = $user->cargoOwnerProfile;

        if (!$cargoOwnerProfile) {
            $cargoOwnerProfile = CargoOwnerProfile::create([
                'user_id' => $user->id,
                'type' => 'individual',
                'kyc_status' => 'unsubmitted',
                'nvi_verified' => false,
                'gib_verified' => false,
            ]);
        }

        Load::create([
            'cargo_owner_profile_id' => $cargoOwnerProfile->id,
            'pickup_location' => $this->pickup_location,
            'delivery_location' => $this->delivery_location,
            'pickup_date' => Carbon::parse($this->pickup_date),
            'delivery_date' => Carbon::parse($this->delivery_date),
            'goods_type' => $this->goods_type,
            'vehicle_type' => $this->vehicle_type,
            'weight' => (int) $this->weight,
            'volume' => $this->volume !== '' ? (int) $this->volume : 1,
            'price' => (float) $this->price,
            'e_irsaliye_no' => $this->e_irsaliye_no,
            'status' => 'active_seeking',
            'escrow_status' => 'pending_payment',
            'pickup_coordinates' => null,
            'delivery_coordinates' => null,
        ]);

        session()->flash('success_message', 'İlanınız başarıyla yayına alındı! Onaylı şoförlerden teklifler toplanmaya başlandı.');

        $this->redirect(route('cargo-owner.loads.index'), navigate: true);
    }
}; ?>

<div class="max-w-4xl mx-auto space-y-8">

    <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-6">
        <div class="flex items-center justify-between relative">
            <div class="absolute left-0 top-1/2 -translate-y-1/2 h-1 bg-neutral-800 w-full z-0"></div>
            <div class="absolute left-0 top-1/2 -translate-y-1/2 h-1 bg-brand-500 transition-all duration-500 z-0"
                 style="width: {{ $currentStep === 1 ? '0%' : ($currentStep === 2 ? '50%' : '100%') }};"></div>

            <div class="relative z-10 flex flex-col items-center gap-2">
                <div class="w-10 h-10 rounded-full flex items-center justify-center font-bold text-sm transition-all duration-300 {{ $currentStep >= 1 ? 'bg-brand-500 text-white shadow-lg shadow-brand-500/30 ring-4 ring-neutral-950' : 'bg-neutral-800 text-neutral-400' }}">
                    1
                </div>
                <span class="text-xs font-semibold {{ $currentStep >= 1 ? 'text-white' : 'text-neutral-500' }}">Rota & Tarih</span>
            </div>

            <div class="relative z-10 flex flex-col items-center gap-2">
                <div class="w-10 h-10 rounded-full flex items-center justify-center font-bold text-sm transition-all duration-300 {{ $currentStep >= 2 ? 'bg-brand-500 text-white shadow-lg shadow-brand-500/30 ring-4 ring-neutral-950' : 'bg-neutral-800 text-neutral-400' }}">
                    2
                </div>
                <span class="text-xs font-semibold {{ $currentStep >= 2 ? 'text-white' : 'text-neutral-500' }}">Yük & e-İrsaliye</span>
            </div>

            <div class="relative z-10 flex flex-col items-center gap-2">
                <div class="w-10 h-10 rounded-full flex items-center justify-center font-bold text-sm transition-all duration-300 {{ $currentStep === 3 ? 'bg-brand-500 text-white shadow-lg shadow-brand-500/30 ring-4 ring-neutral-950' : 'bg-neutral-800 text-neutral-400' }}">
                    3
                </div>
                <span class="text-xs font-semibold {{ $currentStep === 3 ? 'text-white' : 'text-neutral-500' }}">Bütçe & Onay</span>
            </div>
        </div>
    </div>

    <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-6 md:p-8 space-y-6">

        @if($currentStep === 1)
            <div class="space-y-6">
                <div class="border-b border-neutral-800 pb-4">
                    <h3 class="text-lg font-bold text-white flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-brand-500"></span>
                        <span>1. Adım: Yükleme ve Teslimat Rotası</span>
                    </h3>
                    <p class="text-xs text-neutral-400 mt-1">Yükün alınacağı ve teslim edileceği açık adresleri belirleyin veya kayıtlı defterinizden seçin.</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-neutral-400 mb-1.5">Kayıtlı Çıkış Deposu (Opsiyonel)</label>
                        <select wire:model.live="selected_saved_pickup" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-3.5 py-2.5 text-xs text-neutral-200 focus:border-brand-500 focus:outline-none">
                            <option value="">-- Kayıtlı Adreslerden Seç --</option>
                            @foreach($savedAddresses as $addr)
                                <option value="{{ $addr['id'] }}">{{ $addr['title'] }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-neutral-400 mb-1.5">Kayıtlı Varış Deposu (Opsiyonel)</label>
                        <select wire:model.live="selected_saved_delivery" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-3.5 py-2.5 text-xs text-neutral-200 focus:border-brand-500 focus:outline-none">
                            <option value="">-- Kayıtlı Adreslerden Seç --</option>
                            @foreach($savedAddresses as $addr)
                                <option value="{{ $addr['id'] }}">{{ $addr['title'] }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-neutral-300 mb-1.5">Yükleme (Çıkış) Açık Adresi <span class="text-brand-500">*</span></label>
                        <textarea wire:model="pickup_location" rows="2" placeholder="Örn: Ostim OSB 1234. Cadde No:12 Yenimahalle / Ankara" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-4 py-3 text-sm text-white placeholder-neutral-600 focus:border-brand-500 focus:outline-none"></textarea>
                        @error('pickup_location') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-neutral-300 mb-1.5">Teslimat (Varış) Açık Adresi <span class="text-brand-500">*</span></label>
                        <textarea wire:model="delivery_location" rows="2" placeholder="Örn: Aliağa Organize Sanayi Bölgesi 4. Sokak No:5 İzmir" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-4 py-3 text-sm text-white placeholder-neutral-600 focus:border-brand-500 focus:outline-none"></textarea>
                        @error('delivery_location') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-neutral-300 mb-1.5">Yükleme Tarihi <span class="text-brand-500">*</span></label>
                        <input type="date" wire:model="pickup_date" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-4 py-2.5 text-sm text-white focus:border-brand-500 focus:outline-none">
                        @error('pickup_date') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-neutral-300 mb-1.5">En Geç Teslim Tarihi <span class="text-brand-500">*</span></label>
                        <input type="date" wire:model="delivery_date" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-4 py-2.5 text-sm text-white focus:border-brand-500 focus:outline-none">
                        @error('delivery_date') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>
                </div>
            </div>
        @endif

        @if($currentStep === 2)
            <div class="space-y-6">
                <div class="border-b border-neutral-800 pb-4">
                    <h3 class="text-lg font-bold text-white flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-brand-500"></span>
                        <span>2. Adım: Yük Özellikleri & Zorunlu e-İrsaliye</span>
                    </h3>
                    <p class="text-xs text-neutral-400 mt-1">Lojistik güvenliği ve U-ETDS yasal uyumu gereği yük tipi ve e-İrsaliye numarası zorunludur.</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-xs font-medium text-neutral-300 mb-1.5">Yük Cinsi <span class="text-brand-500">*</span></label>
                        <select wire:model="goods_type" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-4 py-3 text-sm text-white focus:border-brand-500 focus:outline-none">
                            @foreach($goodsTypes as $type)
                                <option value="{{ $type }}">{{ $type }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-neutral-300 mb-1.5">Talep Edilen Araç Tipi <span class="text-brand-500">*</span></label>
                        <select wire:model="vehicle_type" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-4 py-3 text-sm text-white focus:border-brand-500 focus:outline-none">
                            @foreach($vehicleTypeOptions as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-neutral-300 mb-1.5">Tahmini Ağırlık (Kg) <span class="text-brand-500">*</span></label>
                        <input type="number" wire:model="weight" placeholder="Örn: 24000" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-4 py-3 text-sm text-white placeholder-neutral-600 focus:border-brand-500 focus:outline-none">
                        @error('weight') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-neutral-300 mb-1.5">Hacim (m³ / Opsiyonel)</label>
                        <input type="number" wire:model="volume" placeholder="Örn: 80" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-4 py-3 text-sm text-white placeholder-neutral-600 focus:border-brand-500 focus:outline-none">
                    </div>
                </div>

                <div class="p-4 rounded-xl bg-neutral-950 border border-neutral-800 space-y-4">
                    <div class="flex items-center gap-2 text-xs font-semibold text-brand-400">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <span>Yasal Zorunluluk: e-İrsaliye Doğrulaması</span>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-neutral-300 mb-1.5">e-İrsaliye Numarası <span class="text-brand-500">*</span></label>
                            <input type="text" wire:model="e_irsaliye_no" placeholder="Örn: GIB2026000001234" class="w-full bg-neutral-900 border border-neutral-800 rounded-xl px-4 py-2.5 text-sm text-white font-mono placeholder-neutral-600 focus:border-brand-500 focus:outline-none">
                            @error('e_irsaliye_no') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-neutral-300 mb-1.5">e-İrsaliye Belgesi / PDF (Opsiyonel)</label>
                            <input type="file" wire:model="e_irsaliye_file" class="w-full text-xs text-neutral-400 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-neutral-800 file:text-neutral-200 hover:file:bg-neutral-700 cursor-pointer">
                        </div>
                    </div>
                </div>
            </div>
        @endif

        @if($currentStep === 3)
            <div class="space-y-6">
                <div class="border-b border-neutral-800 pb-4">
                    <h3 class="text-lg font-bold text-white flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-brand-500"></span>
                        <span>3. Adım: Hedef Bütçe & İlan Özeti</span>
                    </h3>
                    <p class="text-xs text-neutral-400 mt-1">Ödemeniz PayTR havuz sisteminde güvenceye alınır, yük teslim edilene kadar şoföre aktarılmaz.</p>
                </div>

                <div class="p-5 rounded-2xl bg-neutral-950 border border-neutral-800 space-y-3">
                    <div class="text-xs font-semibold text-neutral-400 uppercase tracking-wider">İlan Önizlemesi</div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4 text-xs">
                        <div>
                            <span class="text-neutral-500 block">Çıkış Noktası:</span>
                            <span class="text-white font-medium line-clamp-1">{{ $pickup_location }}</span>
                        </div>
                        <div>
                            <span class="text-neutral-500 block">Varış Noktası:</span>
                            <span class="text-white font-medium line-clamp-1">{{ $delivery_location }}</span>
                        </div>
                        <div>
                            <span class="text-neutral-500 block">Araç & Yük:</span>
                            <span class="text-white font-medium">{{ $this->getVehicleTypeName() }} • {{ $goods_type }}</span>
                        </div>
                        <div>
                            <span class="text-neutral-500 block">Yükleme Tarihi:</span>
                            <span class="text-white font-medium">{{ $pickup_date }}</span>
                        </div>
                        <div>
                            <span class="text-neutral-500 block">e-İrsaliye No:</span>
                            <span class="text-white font-mono font-medium">{{ $e_irsaliye_no }}</span>
                        </div>
                        <div>
                            <span class="text-neutral-500 block">Ağırlık / Hacim:</span>
                            <span class="text-white font-medium">{{ $weight }} Kg / {{ $volume ?: 0 }} m³</span>
                        </div>
                    </div>
                </div>

                <div class="space-y-2">
                    <label class="block text-xs font-medium text-neutral-300">Hedef Navlun Bütçeniz (₺) <span class="text-brand-500">*</span></label>
                    <div class="relative max-w-xs">
                        <input type="number" wire:model="price" placeholder="Örn: 18500" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl pl-4 pr-10 py-3.5 text-lg font-bold text-white font-mono focus:border-brand-500 focus:outline-none">
                        <span class="absolute right-4 top-1/2 -translate-y-1/2 font-bold text-neutral-500 text-lg">₺</span>
                    </div>
                    @error('price') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    <p class="text-[11px] text-neutral-500">Şoförler bu bütçeyi referans alarak tekliflerini iletirler.</p>
                </div>

                <div class="pt-2">
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" wire:model="terms_accepted" class="mt-1 w-4 h-4 rounded bg-neutral-950 border-neutral-800 text-brand-500 focus:ring-brand-500/20">
                        <span class="text-xs text-neutral-400 leading-relaxed">
                            Navlun bedelinin PayTR Escrow havuz hesabına yatırılacağını, yük eksiksiz teslim edilene ve tarafımca onaylanana kadar şoföre aktarılmayacağını kabul ediyorum.
                        </span>
                    </label>
                    @error('terms_accepted') <span class="text-rose-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                </div>
            </div>
        @endif

        <div class="flex items-center justify-between pt-6 border-t border-neutral-800">
            @if($currentStep > 1)
                <button type="button" wire:click="previousStep" class="px-5 py-2.5 rounded-xl bg-neutral-800 hover:bg-neutral-700 text-neutral-300 text-xs font-semibold transition-colors">
                    &larr; Geri Dön
                </button>
            @else
                <div></div>
            @endif

            @if($currentStep < 3)
                <button type="button" wire:click="nextStep" class="px-6 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold shadow-lg shadow-brand-500/20 transition-all">
                    Devam Et &rarr;
                </button>
            @else
                <button type="button" wire:click="submitLoad" class="px-8 py-3 rounded-xl bg-gradient-to-r from-brand-500 to-amber-500 hover:from-brand-600 hover:to-amber-600 text-white text-sm font-bold shadow-xl shadow-brand-500/30 transition-all active:scale-95 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                    <span>İlanı Yayına Al</span>
                </button>
            @endif
        </div>

    </div>
</div>
