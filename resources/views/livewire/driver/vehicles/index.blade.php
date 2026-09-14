<?php

use App\Models\DriverVehicle;
use App\Models\Shipment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new
#[Layout('components.layouts.driver')]
#[Title('Araçlarım')]
class extends Component {
    use WithFileUploads;

    public bool $formOpen = false;

    #[Locked]
    public ?int $editingId = null;

    public string $plate = '';

    public string $brand = '';

    public string $model = '';

    public string $vehicle_type = '';

    public $ruhsat = null;

    private function profileId(): int
    {
        return (int) (Auth::user()->driverProfile?->id ?? 0);
    }

    private function ownedVehicle(int $id): ?DriverVehicle
    {
        return DriverVehicle::query()->where('driver_profile_id', $this->profileId())->whereKey($id)->first();
    }

    public function openCreate(): void
    {
        $this->reset(['editingId', 'plate', 'brand', 'model', 'ruhsat']);
        $this->vehicle_type = array_key_first(DriverVehicle::getVehicleTypes());
        $this->resetErrorBag();
        $this->formOpen = true;
    }

    public function openEdit(int $vehicleId): void
    {
        $vehicle = $this->ownedVehicle($vehicleId);
        if (! $vehicle) {
            session()->flash('error_message', 'Araç bulunamadı.');

            return;
        }

        $this->editingId = $vehicle->id;
        $this->plate = $vehicle->plate;
        $this->brand = $vehicle->brand;
        $this->model = $vehicle->model;
        $this->vehicle_type = $vehicle->vehicle_type;
        $this->ruhsat = null;
        $this->resetErrorBag();
        $this->formOpen = true;
    }

    public function closeForm(): void
    {
        $this->formOpen = false;
        $this->reset(['editingId', 'plate', 'brand', 'model', 'vehicle_type', 'ruhsat']);
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->plate = DriverVehicle::normalizePlate($this->plate);

        $this->validate([
            'plate' => ['required', 'string', 'max:32', DriverVehicle::PLATE_RULE, Rule::unique('driver_vehicles', 'plate')->ignore($this->editingId)],
            'brand' => ['required', 'string', 'min:2', 'max:80'],
            'model' => ['required', 'string', 'min:1', 'max:80'],
            'vehicle_type' => ['required', Rule::in(array_keys(DriverVehicle::getVehicleTypes()))],
            'ruhsat' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ], [
            'plate.required' => 'Plaka zorunludur.',
            'plate.regex' => 'Geçerli bir Türk plakası girin (örn. 34ABC123).',
            'plate.unique' => 'Bu plaka sistemde zaten kayıtlı.',
            'brand.required' => 'Marka zorunludur.',
            'model.required' => 'Model zorunludur.',
            'vehicle_type.in' => 'Geçerli bir araç türü seçin.',
            'ruhsat.mimes' => 'Ruhsat JPG, PNG veya PDF olmalıdır.',
            'ruhsat.max' => 'Ruhsat dosyası en fazla 10 MB olabilir.',
        ]);

        $profileId = $this->profileId();
        if ($profileId === 0) {
            $this->addError('plate', 'Şoför profili bulunamadı.');

            return;
        }

        $data = [
            'plate' => $this->plate,
            'brand' => trim($this->brand),
            'model' => trim($this->model),
            'vehicle_type' => $this->vehicle_type,
        ];

        if ($this->ruhsat) {
            $data['ruhsat_path'] = $this->ruhsat->storeAs(
                'vehicles/'.$profileId,
                'ruhsat-'.now()->format('YmdHis').'-'.$this->plate.'.'.strtolower($this->ruhsat->getClientOriginalExtension()),
                'private'
            );
        }

        if ($this->editingId) {
            $vehicle = $this->ownedVehicle($this->editingId);
            if (! $vehicle) {
                $this->addError('plate', 'Araç bulunamadı.');

                return;
            }
            $vehicle->update($data);
            $message = 'Araç bilgileri güncellendi.';
        } else {
            $hasActive = DriverVehicle::query()->where('driver_profile_id', $profileId)->where('is_active', true)->exists();
            DriverVehicle::create($data + ['driver_profile_id' => $profileId, 'is_active' => ! $hasActive]);
            $message = 'Araç eklendi.';
        }

        $this->closeForm();
        session()->flash('success_message', $message);
    }

    public function activate(int $vehicleId): void
    {
        $vehicle = $this->ownedVehicle($vehicleId);
        if (! $vehicle) {
            session()->flash('error_message', 'Araç bulunamadı.');

            return;
        }

        DriverVehicle::query()->where('driver_profile_id', $this->profileId())->whereKeyNot($vehicle->id)->update(['is_active' => false]);
        $vehicle->update(['is_active' => true]);

        session()->flash('success_message', $vehicle->plate.' aktif araç olarak ayarlandı.');
    }

    public function delete(int $vehicleId): void
    {
        $vehicle = $this->ownedVehicle($vehicleId);
        if (! $vehicle) {
            session()->flash('error_message', 'Araç bulunamadı.');

            return;
        }

        $activeCount = DriverVehicle::query()->where('driver_profile_id', $this->profileId())->where('is_active', true)->count();
        if ($vehicle->is_active && $activeCount <= 1) {
            session()->flash('error_message', 'Tek aktif aracınızı silemezsiniz. Önce başka bir aracı aktif yapın.');

            return;
        }

        $inUse = Shipment::query()->where('vehicle_id', $vehicle->id)
            ->whereIn('status', [Shipment::STATUS_AWAITING_PICKUP, Shipment::STATUS_IN_TRANSIT, Shipment::STATUS_DELIVERED, Shipment::STATUS_DISPUTED])
            ->exists();
        if ($inUse) {
            session()->flash('error_message', 'Bu araç devam eden bir sevkiyata bağlı olduğu için silinemez.');

            return;
        }

        $vehicle->forceDelete();
        session()->flash('success_message', 'Araç silindi.');
    }

    public function with(): array
    {
        return [
            'vehicles' => DriverVehicle::query()->where('driver_profile_id', $this->profileId())->orderByDesc('is_active')->latest('id')->get(),
            'vehicleTypes' => DriverVehicle::getVehicleTypes(),
        ];
    }
}; ?>

<div class="space-y-6">

    @if (session()->has('success_message'))
        <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-600 dark:text-emerald-400 text-xs font-semibold">{{ session('success_message') }}</div>
    @endif
    @if (session()->has('error_message'))
        <div class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-700 dark:text-rose-300 text-xs font-semibold">{{ session('error_message') }}</div>
    @endif

    <div class="border-b border-neutral-200 dark:border-neutral-800 pb-4 flex flex-col sm:flex-row sm:items-end justify-between gap-3">
        <div>
            <h2 class="text-xl font-bold text-neutral-900 dark:text-white tracking-tight">Araçlarım</h2>
            <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-1">Teklif verebilmek için en az bir aktif aracınız olmalı. Aktif araç, kabul edilen sevkiyata atanır.</p>
        </div>
        <button type="button" wire:click="openCreate" class="px-5 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-bold text-xs shadow-lg shadow-brand-500/20">Araç ekle</button>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        @forelse($vehicles as $vehicle)
            <div class="bg-white dark:bg-neutral-900 border {{ $vehicle->is_active ? 'border-brand-500/40' : 'border-neutral-200 dark:border-neutral-800' }} rounded-2xl p-6 space-y-3 text-xs">
                <div class="flex items-center justify-between gap-2">
                    <div class="text-base font-black text-neutral-900 dark:text-white font-mono">{{ $vehicle->plate }}</div>
                    @if($vehicle->is_active)
                        <span class="px-2.5 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-600 dark:text-emerald-400 font-bold text-[11px]">Aktif</span>
                    @else
                        <span class="px-2.5 py-1 rounded-full bg-neutral-100 dark:bg-neutral-800 border border-neutral-300 dark:border-neutral-700 text-neutral-700 dark:text-neutral-300 font-bold text-[11px]">Pasif</span>
                    @endif
                </div>
                <div class="text-neutral-700 dark:text-neutral-300">{{ $vehicle->brand }} {{ $vehicle->model }}</div>
                <div class="text-neutral-500">{{ $vehicleTypes[$vehicle->vehicle_type] ?? $vehicle->vehicle_type }}</div>
                <div class="text-[11px] {{ $vehicle->ruhsat_path ? 'text-emerald-600 dark:text-emerald-400' : 'text-neutral-500' }}">
                    {{ $vehicle->ruhsat_path ? 'Ruhsat yüklendi' : 'Ruhsat yüklenmedi' }}
                </div>
                <div class="pt-3 border-t border-neutral-200 dark:border-neutral-800 flex flex-col sm:flex-row gap-2">
                    @if(! $vehicle->is_active)
                        <button type="button" wire:click="activate({{ $vehicle->id }})" class="px-3 py-2 rounded-xl bg-brand-500/10 border border-brand-500/30 text-brand-400 font-bold hover:bg-brand-500/20">Aktif yap</button>
                    @endif
                    <button type="button" wire:click="openEdit({{ $vehicle->id }})" class="px-3 py-2 rounded-xl bg-neutral-100 dark:bg-neutral-800 border border-neutral-300 dark:border-neutral-700 text-neutral-900 dark:text-white font-bold hover:bg-neutral-200 dark:hover:bg-neutral-700">Düzenle</button>
                    <button type="button" wire:click="delete({{ $vehicle->id }})" wire:confirm="{{ $vehicle->plate }} plakalı aracı silmek istediğinize emin misiniz?" class="px-3 py-2 rounded-xl border border-rose-500/30 text-rose-700 dark:text-rose-300 font-bold hover:bg-rose-500/10">Sil</button>
                </div>
            </div>
        @empty
            <div class="sm:col-span-2 p-6 bg-white dark:bg-neutral-900 border border-dashed border-neutral-200 dark:border-neutral-800 rounded-2xl text-center text-xs text-neutral-500 dark:text-neutral-400">Henüz kayıtlı aracınız yok. Teklif verebilmek için bir araç ekleyin.</div>
        @endforelse
    </div>

    @if($formOpen)
        <div class="fixed inset-0 z-[9999] overflow-y-auto flex items-start sm:items-center justify-center p-4">
            <div class="fixed inset-0 bg-neutral-950/70 backdrop-blur-md" wire:click="closeForm"></div>
            <form wire:submit.prevent="save" class="relative z-10 w-full max-w-lg bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 shadow-2xl space-y-4 text-left text-xs">
                <h3 class="text-base font-bold text-neutral-900 dark:text-white border-b border-neutral-200 dark:border-neutral-800 pb-3">{{ $editingId ? 'Aracı düzenle' : 'Yeni araç' }}</h3>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1">Plaka</label>
                        <input type="text" wire:model="plate" placeholder="34ABC123" class="w-full bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl px-4 py-2.5 text-neutral-900 dark:text-white font-mono uppercase focus:border-brand-500 focus:outline-none">
                        @error('plate') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1">Araç türü</label>
                        <select wire:model="vehicle_type" class="w-full bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl px-3 py-2.5 text-neutral-900 dark:text-white focus:border-brand-500 focus:outline-none">
                            @foreach($vehicleTypes as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('vehicle_type') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1">Marka</label>
                        <input type="text" wire:model="brand" class="w-full bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl px-4 py-2.5 text-neutral-900 dark:text-white focus:border-brand-500 focus:outline-none">
                        @error('brand') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1">Model</label>
                        <input type="text" wire:model="model" class="w-full bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl px-4 py-2.5 text-neutral-900 dark:text-white focus:border-brand-500 focus:outline-none">
                        @error('model') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div>
                    <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1">Ruhsat (JPG, PNG, PDF; en fazla 10 MB, isteğe bağlı)</label>
                    <input type="file" wire:model="ruhsat" accept="image/jpeg,image/png,application/pdf" class="w-full text-neutral-500 dark:text-neutral-400 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-neutral-200 dark:file:bg-neutral-800 file:text-neutral-900 dark:file:text-white">
                    @error('ruhsat') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    <div wire:loading wire:target="ruhsat" class="text-[11px] text-neutral-500 mt-1">Dosya hazırlanıyor...</div>
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="button" wire:click="closeForm" class="flex-1 px-4 py-2.5 rounded-xl bg-neutral-100 dark:bg-neutral-800 hover:bg-neutral-200 dark:hover:bg-neutral-700 text-neutral-700 dark:text-neutral-300 font-semibold">Vazgeç</button>
                    <button type="submit" class="flex-1 px-4 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-bold" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="save">Kaydet</span>
                        <span wire:loading wire:target="save">Kaydediliyor...</span>
                    </button>
                </div>
            </form>
        </div>
    @endif
</div>
