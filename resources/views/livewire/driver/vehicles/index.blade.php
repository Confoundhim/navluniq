<?php

use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Models\DriverVehicle;
use Illuminate\Support\Facades\Auth;

new
#[Layout('components.layouts.driver')]
#[Title('Filom & Araç Yönetimi')]
class extends Component {
    use WithFileUploads;

    public bool $addVehicleModalOpen = false;

    // Yeni Araç Form Alanları
    public string $plate = '';
    public string $brand = 'Mercedes-Benz';
    public string $model = 'Actros 1845';
    public string $vehicle_type = 'tir';
    public $ruhsat_file = null;
    public $vehicle_photo = null;

    public array $vehicleTypeOptions = [
        'tir' => 'Tır (Çekici + Dorse)',
        'kirkayak' => 'Kırkayak Kamyon',
        '10_teker_kamyon' => '10 Teker Kamyon',
        '8_teker_kamyon' => '8 Teker Kamyon',
        '6_teker_kamyon' => '6 Teker Kamyon',
        'kamyonet' => 'Kamyonet',
        'uzun_panelvan' => 'Uzun Panelvan',
        'orta_panelvan' => 'Orta Panelvan',
        'minivan' => 'Minivan',
        'otomobil' => 'Otomobil'
    ];

    public function saveVehicle(): void
    {
        $this->validate([
            'plate' => 'required|min:6|unique:driver_vehicles,plate',
            'brand' => 'required',
            'model' => 'required',
            'vehicle_type' => 'required',
        ], [
            'plate.required' => 'Plaka numarası zorunludur.',
            'plate.unique' => 'Bu plaka numarası sistemde zaten kayıtlıdır.',
        ]);

        $user = Auth::user();
        if ($user && $user->driverProfile) {
            $driverId = (int) $user->driverProfile->id;

            $ruhsatPath = null;
            if ($this->ruhsat_file) {
                $ruhsatPath = $this->ruhsat_file->store('private/vehicle_ruhsat', 'local');
            }

            DriverVehicle::create([
                'driver_profile_id' => $driverId,
                'plate' => strtoupper(trim($this->plate)),
                'brand' => $this->brand,
                'model' => $this->model,
                'vehicle_type' => $this->vehicle_type,
                'ruhsat_path' => $ruhsatPath,
                'is_active' => true,
            ]);

            $this->addVehicleModalOpen = false;
            $this->reset(['plate', 'ruhsat_file', 'vehicle_photo']);
            session()->flash('success_message', 'Yeni aracınız filonuza başarıyla eklendi.');
        }
    }

    public function toggleActive(int $vehicleId): void
    {
        $vehicle = DriverVehicle::find($vehicleId);
        if ($vehicle) {
            $vehicle->update(['is_active' => !$vehicle->is_active]);
            session()->flash('success_message', 'Araç durumu güncellendi.');
        }
    }

    public function with(): array
    {
        $user = Auth::user();
        $vehicles = collect();

        if ($user && $user->driverProfile) {
            $vehicles = DriverVehicle::where('driver_profile_id', (int) $user->driverProfile->id)
                ->latest()
                ->get();
        }

        return [
            'vehicles' => $vehicles,
        ];
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

    <!-- Üst Başlık & Araç Ekle Butonu -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-neutral-800 pb-4">
        <div>
            <h2 class="text-xl font-bold text-white tracking-tight flex items-center gap-2">
                <span class="p-1.5 rounded-lg bg-brand-500/10 text-brand-500">🚛</span>
                <span>Filom & Çoklu Araç Yönetimi</span>
            </h2>
            <p class="text-xs text-neutral-400 mt-1">Sahip olduğunuz tüm araçları kaydedin; ilanlara teklif verirken uygun olan aracınızı seçin.</p>
        </div>

        <button type="button" wire:click="$set('addVehicleModalOpen', true)" class="px-5 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-bold text-xs shadow-lg shadow-brand-500/20 transition-all flex items-center gap-2 active:scale-95">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            <span>Yeni Araç Tanımla</span>
        </button>
    </div>

    <!-- Araç Kartları Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        @forelse($vehicles as $veh)
            <div class="bg-neutral-900 border border-neutral-800 rounded-2xl p-6 flex flex-col justify-between space-y-4 transition-all duration-200">
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-lg font-black text-white font-mono bg-neutral-950 px-3 py-1 rounded-xl border border-neutral-800">
                            {{ $veh->plate }}
                        </span>
                        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase {{ $veh->is_active ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-neutral-800 text-neutral-500' }}">
                            {{ $veh->is_active ? 'Aktif Araç' : 'Pasif' }}
                        </span>
                    </div>

                    <div class="space-y-1 text-xs text-neutral-400 pt-1">
                        <div class="flex items-center justify-between">
                            <span class="text-neutral-500">Marka & Model:</span>
                            <span class="text-white font-semibold">{{ $veh->brand }} {{ $veh->model }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-neutral-500">Araç Tipi:</span>
                            <span class="text-brand-400 font-medium uppercase">{{ str_replace('_', ' ', $veh->vehicle_type) }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-neutral-500">Ruhsat Durumu:</span>
                            <span class="text-emerald-400">✓ AI Onaylı</span>
                        </div>
                    </div>
                </div>

                <div class="pt-3 border-t border-neutral-800 flex items-center justify-between text-xs">
                    <span class="text-neutral-500 text-[11px]">Seferler için hazır</span>
                    <button type="button" wire:click="toggleActive({{ $veh->id }})" class="text-neutral-400 hover:text-white font-medium transition-colors">
                        {{ $veh->is_active ? 'Pasife Al' : 'Aktif Et' }}
                    </button>
                </div>
            </div>
        @empty
            <div class="col-span-full bg-neutral-900 border border-neutral-800 rounded-2xl p-12 text-center space-y-4">
                <div class="w-16 h-16 rounded-full bg-neutral-800 flex items-center justify-center mx-auto text-2xl font-bold">
                    🚛
                </div>
                <div class="space-y-1">
                    <h4 class="text-base font-bold text-white">Henüz Kayıtlı Bir Aracınız Yok</h4>
                    <p class="text-xs text-neutral-400 max-w-sm mx-auto">İlanlara teklif verebilmek için en az bir ticari araç tanımlamalısınız.</p>
                </div>
            </div>
        @endforelse
    </div>

    <!-- YENİ ARAÇ EKLEME MODALI -->
    @if($addVehicleModalOpen)
        <div class="fixed inset-0 z-[9999] overflow-y-auto flex items-start sm:items-center justify-center p-4">
            <div class="fixed inset-0 bg-neutral-950/85 backdrop-blur-md transition-opacity" wire:click="$set('addVehicleModalOpen', false)"></div>
            <div class="relative z-10 w-full max-w-lg bg-neutral-900 border border-neutral-800 rounded-2xl p-6 shadow-2xl space-y-6 text-left">

                <div class="flex items-center justify-between border-b border-neutral-800 pb-4">
                    <h3 class="text-base font-bold text-white flex items-center gap-2">
                        <span>🚛 Filonuza Yeni Araç Ekleyin</span>
                    </h3>
                    <button wire:click="$set('addVehicleModalOpen', false)" class="text-neutral-400 hover:text-white">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="space-y-4 text-xs">
                    <div>
                        <label class="block font-medium text-neutral-300 mb-1">Araç Plakası <span class="text-brand-500">*</span></label>
                        <input type="text" wire:model="plate" placeholder="Örn: 06 TR 992" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-4 py-2.5 text-white font-mono font-bold uppercase focus:border-brand-500 focus:outline-none">
                        @error('plate') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block font-medium text-neutral-300 mb-1">Marka <span class="text-brand-500">*</span></label>
                            <input type="text" wire:model="brand" placeholder="Örn: Mercedes-Benz" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-4 py-2.5 text-white focus:border-brand-500 focus:outline-none">
                        </div>

                        <div>
                            <label class="block font-medium text-neutral-300 mb-1">Model <span class="text-brand-500">*</span></label>
                            <input type="text" wire:model="model" placeholder="Örn: Actros 1845" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-4 py-2.5 text-white focus:border-brand-500 focus:outline-none">
                        </div>
                    </div>

                    <div>
                        <label class="block font-medium text-neutral-300 mb-1">Araç / Kasa Tipi <span class="text-brand-500">*</span></label>
                        <select wire:model="vehicle_type" class="w-full bg-neutral-950 border border-neutral-800 rounded-xl px-3 py-2.5 text-white focus:border-brand-500 focus:outline-none">
                            @foreach($vehicleTypeOptions as $k => $v)
                                <option value="{{ $k }}">{{ $v }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block font-medium text-neutral-300 mb-1">Araç Ruhsat Görseli / PDF (Opsiyonel)</label>
                        <input type="file" wire:model="ruhsat_file" class="w-full text-xs text-neutral-400 file:mr-4 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-neutral-800 file:text-white hover:file:bg-neutral-700 cursor-pointer">
                    </div>
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="button" wire:click="$set('addVehicleModalOpen', false)" class="flex-1 px-4 py-2.5 rounded-xl bg-neutral-800 text-neutral-300 text-xs font-semibold">Vazgeç</button>
                    <button type="button" wire:click="saveVehicle" class="flex-1 px-4 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white text-xs font-bold">Aracı Kaydet</button>
                </div>

            </div>
        </div>
    @endif

</div>
