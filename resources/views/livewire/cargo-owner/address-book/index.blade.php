<?php

use App\Models\SavedAddress;
use App\Support\Phone;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.cargo-owner')]
#[Title('Adres Defteri')]
class extends Component {
    public bool $modalOpen = false;

    #[Locked]
    public ?int $editingId = null;

    public string $title = '';

    public string $contact_person = '';

    public string $contact_phone = '';

    public string $city = '';

    public string $district = '';

    public string $address_detail = '';

    public string $type = 'both';

    public bool $is_default = false;

    private function ownerAddress(int $id): ?SavedAddress
    {
        return SavedAddress::query()->whereKey($id)->where('user_id', Auth::id())->first();
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'title', 'contact_person', 'contact_phone', 'city', 'district', 'address_detail', 'type', 'is_default']);
        $this->resetErrorBag();
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->modalOpen = true;
    }

    public function openEdit(int $id): void
    {
        $address = $this->ownerAddress($id);
        if (! $address) {
            session()->flash('error_message', 'Adres bulunamadı.');

            return;
        }

        $this->resetForm();
        $this->editingId = $address->id;
        $this->title = $address->title;
        $this->contact_person = $address->contact_person;
        $this->contact_phone = Phone::format($address->contact_phone);
        $this->city = $address->city;
        $this->district = $address->district;
        $this->address_detail = $address->address_detail;
        $this->type = $address->type;
        $this->is_default = (bool) $address->is_default;
        $this->modalOpen = true;
    }

    public function save(): void
    {
        $this->contact_phone = preg_replace('/\s+/', '', $this->contact_phone) ?? '';

        $this->validate([
            'title' => 'required|string|min:3|max:120',
            'contact_person' => 'required|string|min:3|max:120',
            'contact_phone' => ['required', Phone::RULE],
            'city' => 'required|string|min:2|max:96',
            'district' => 'required|string|min:2|max:96',
            'address_detail' => 'required|string|min:10|max:1000',
            'type' => 'required|in:pickup,delivery,both',
            'is_default' => 'boolean',
        ], [
            'title.required' => 'Adres için bir başlık girin.',
            'contact_person.required' => 'Yetkili kişi adı zorunludur.',
            'contact_phone.required' => 'İletişim telefonu zorunludur.',
            'contact_phone.regex' => 'Geçerli bir cep telefonu numarası girin (05XX XXX XX XX).',
            'address_detail.required' => 'Açık adresi girin.',
            'address_detail.min' => 'Açık adres en az 10 karakter olmalıdır.',
        ]);

        $userId = Auth::id();
        $data = [
            'title' => trim($this->title),
            'contact_person' => trim($this->contact_person),
            'contact_phone' => Phone::normalize($this->contact_phone) ?? $this->contact_phone,
            'city' => trim($this->city),
            'district' => trim($this->district),
            'address_detail' => trim($this->address_detail),
            'type' => $this->type,
            'is_default' => $this->is_default,
        ];

        $editing = $this->editingId ? $this->ownerAddress($this->editingId) : null;
        if ($this->editingId && ! $editing) {
            $this->addError('title', 'Düzenlenen adres bulunamadı.');

            return;
        }

        DB::transaction(function () use ($userId, $data, $editing): void {
            if ($data['is_default']) {
                SavedAddress::query()->where('user_id', $userId)->update(['is_default' => false]);
            }

            if ($editing) {
                $editing->update($data);
            } else {
                SavedAddress::create($data + ['user_id' => $userId]);
            }
        });

        $this->modalOpen = false;
        $this->resetForm();
        session()->flash('success_message', $editing ? 'Adres güncellendi.' : 'Adres defterinize eklendi. İlan oluştururken seçebilirsiniz.');
    }

    public function deleteAddress(int $id): void
    {
        $address = $this->ownerAddress($id);
        if (! $address) {
            session()->flash('error_message', 'Adres bulunamadı.');

            return;
        }

        $address->delete();
        session()->flash('success_message', 'Adres defterinizden kaldırıldı.');
    }

    public function setDefault(int $id): void
    {
        $address = $this->ownerAddress($id);
        if (! $address) {
            session()->flash('error_message', 'Adres bulunamadı.');

            return;
        }

        DB::transaction(function () use ($address): void {
            SavedAddress::query()->where('user_id', Auth::id())->whereKeyNot($address->id)->update(['is_default' => false]);
            $address->update(['is_default' => true]);
        });

        session()->flash('success_message', '"'.$address->title.'" varsayılan adres olarak ayarlandı.');
    }

    public function with(): array
    {
        return [
            'addresses' => SavedAddress::query()->where('user_id', Auth::id())->orderByDesc('is_default')->latest()->get(),
            'typeLabels' => ['pickup' => 'Yükleme noktası', 'delivery' => 'Teslimat noktası', 'both' => 'Yükleme ve teslimat'],
        ];
    }
}; ?>

<div class="space-y-6">

    @if (session()->has('success_message'))
        <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-600 dark:text-emerald-400 text-xs font-semibold">
            {{ session('success_message') }}
        </div>
    @endif

    @if (session()->has('error_message'))
        <div class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-600 dark:text-rose-400 text-xs font-semibold">
            {{ session('error_message') }}
        </div>
    @endif

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-neutral-200 dark:border-neutral-800 pb-4">
        <div>
            <h2 class="text-xl font-bold text-neutral-900 dark:text-white tracking-tight flex items-center gap-2">
                <span class="p-1.5 rounded-lg bg-brand-500/10 text-brand-400">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                </span>
                <span>Kayıtlı adres defterim</span>
            </h2>
            <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-1">Sık kullandığınız yükleme ve teslimat noktalarını kaydedin, ilan oluştururken tek tıkla seçin.</p>
        </div>

        <button type="button" wire:click="openCreate" class="px-5 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-bold text-xs shadow-lg shadow-brand-500/20 transition-all flex items-center justify-center gap-2 active:scale-95">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            <span>Yeni adres</span>
        </button>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
        @forelse($addresses as $addr)
            <div class="bg-white dark:bg-neutral-900 border {{ $addr->is_default ? 'border-brand-500/40' : 'border-neutral-200 dark:border-neutral-800 hover:border-neutral-300 dark:hover:border-neutral-700' }} rounded-2xl p-6 flex flex-col justify-between space-y-4 transition-all duration-200">

                <div class="space-y-3">
                    <div class="flex items-start justify-between gap-2">
                        <h3 class="text-sm font-bold text-neutral-900 dark:text-white tracking-tight break-words">{{ $addr->title }}</h3>
                        <div class="flex flex-col items-end gap-1 shrink-0">
                            <span class="px-2 py-0.5 rounded-full text-[11px] font-bold {{ $addr->type === 'pickup' ? 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/20' : ($addr->type === 'delivery' ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20' : 'bg-brand-500/10 text-brand-400 border border-brand-500/20') }}">
                                {{ $typeLabels[$addr->type] ?? $addr->type }}
                            </span>
                            @if($addr->is_default)
                                <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-neutral-100 dark:bg-neutral-800 border border-neutral-300 dark:border-neutral-700 text-neutral-700 dark:text-neutral-300">Varsayılan</span>
                            @endif
                        </div>
                    </div>

                    <p class="text-xs text-neutral-700 dark:text-neutral-300 bg-neutral-50 dark:bg-neutral-950 p-3 rounded-xl border border-neutral-200 dark:border-neutral-800/80 leading-relaxed break-words">{{ $addr->fullAddress() }}</p>

                    <div class="space-y-1 text-xs text-neutral-500 dark:text-neutral-400 pt-1">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-neutral-500">Yetkili</span>
                            <span class="text-neutral-800 dark:text-neutral-200 font-medium text-right">{{ $addr->contact_person }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-neutral-500">Telefon</span>
                            <span class="text-neutral-800 dark:text-neutral-200 tabular-nums">{{ Phone::format($addr->contact_phone) }}</span>
                        </div>
                    </div>
                </div>

                <div class="pt-3 border-t border-neutral-200 dark:border-neutral-800 flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-xs">
                    <div class="flex items-center gap-3">
                        <button type="button" wire:click="openEdit({{ $addr->id }})" class="text-neutral-700 dark:text-neutral-300 hover:text-neutral-900 dark:hover:text-white font-medium">Düzenle</button>
                        @if(! $addr->is_default)
                            <button type="button" wire:click="setDefault({{ $addr->id }})" class="text-neutral-500 dark:text-neutral-400 hover:text-brand-400 font-medium">Varsayılan yap</button>
                        @endif
                    </div>
                    <button type="button" wire:click="deleteAddress({{ $addr->id }})" wire:confirm="Bu adresi defterinizden silmek istediğinize emin misiniz?" class="text-rose-600 dark:text-rose-400 hover:text-rose-800 dark:hover:text-rose-300 font-medium flex items-center gap-1 transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                        <span>Sil</span>
                    </button>
                </div>

            </div>
        @empty
            <div class="col-span-full bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-12 text-center space-y-4">
                <div class="w-16 h-16 rounded-full bg-neutral-100 dark:bg-neutral-800 flex items-center justify-center mx-auto text-neutral-500">
                    <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                </div>
                <div class="space-y-1">
                    <h4 class="text-base font-bold text-neutral-900 dark:text-white">Henüz kayıtlı adresiniz yok</h4>
                    <p class="text-xs text-neutral-500 dark:text-neutral-400 max-w-sm mx-auto">Sık kullandığınız depo veya teslimat noktalarını kaydedin.</p>
                </div>
            </div>
        @endforelse
    </div>

    @if($modalOpen)
        <div class="fixed inset-0 z-[9999] overflow-y-auto flex items-start sm:items-center justify-center p-4">
            <div class="fixed inset-0 bg-neutral-950/70 backdrop-blur-md" wire:click="$set('modalOpen', false)"></div>
            <form wire:submit.prevent="save" class="relative z-10 w-full max-w-lg bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 shadow-2xl space-y-5 text-left">

                <div class="flex items-center justify-between border-b border-neutral-200 dark:border-neutral-800 pb-4">
                    <h3 class="text-base font-bold text-neutral-900 dark:text-white">{{ $editingId ? 'Adresi düzenle' : 'Yeni adres ekle' }}</h3>
                    <button type="button" wire:click="$set('modalOpen', false)" class="text-neutral-500 dark:text-neutral-400 hover:text-neutral-900 dark:hover:text-white">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="space-y-4 text-xs">
                    <div>
                        <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1">Adres başlığı <span class="text-brand-500">*</span></label>
                        <input type="text" wire:model="title" maxlength="120" placeholder="Örn: Merkez depo" class="w-full bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl px-4 py-2.5 text-neutral-900 dark:text-white focus:border-brand-500 focus:outline-none">
                        @error('title') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1">Yetkili kişi <span class="text-brand-500">*</span></label>
                            <input type="text" wire:model="contact_person" maxlength="120" class="w-full bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl px-4 py-2.5 text-neutral-900 dark:text-white focus:border-brand-500 focus:outline-none">
                            @error('contact_person') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1">Yetkili telefonu <span class="text-brand-500">*</span></label>
                            <input type="text" wire:model="contact_phone" inputmode="tel" placeholder="05XX XXX XX XX" class="w-full bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl px-4 py-2.5 text-neutral-900 dark:text-white tabular-nums focus:border-brand-500 focus:outline-none">
                            @error('contact_phone') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1">İl <span class="text-brand-500">*</span></label>
                            <input type="text" wire:model="city" maxlength="96" class="w-full bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl px-4 py-2.5 text-neutral-900 dark:text-white focus:border-brand-500 focus:outline-none">
                            @error('city') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1">İlçe <span class="text-brand-500">*</span></label>
                            <input type="text" wire:model="district" maxlength="96" class="w-full bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl px-4 py-2.5 text-neutral-900 dark:text-white focus:border-brand-500 focus:outline-none">
                            @error('district') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div>
                        <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1">Açık adres <span class="text-brand-500">*</span></label>
                        <textarea wire:model="address_detail" rows="3" maxlength="1000" placeholder="Mahalle, cadde, sokak, kapı numarası" class="w-full bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl p-3 text-neutral-900 dark:text-white placeholder-neutral-400 dark:placeholder-neutral-600 focus:border-brand-500 focus:outline-none"></textarea>
                        @error('address_detail') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 items-end">
                        <div>
                            <label class="block font-medium text-neutral-700 dark:text-neutral-300 mb-1">Kullanım amacı</label>
                            <select wire:model="type" class="w-full bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl px-3.5 py-2.5 text-neutral-800 dark:text-neutral-200 focus:border-brand-500 focus:outline-none">
                                <option value="both">Yükleme ve teslimat</option>
                                <option value="pickup">Yalnız yükleme (çıkış)</option>
                                <option value="delivery">Yalnız teslimat (varış)</option>
                            </select>
                            @error('type') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                        </div>
                        <label class="flex items-center gap-2 cursor-pointer py-2.5">
                            <input type="checkbox" wire:model="is_default" class="w-4 h-4 rounded bg-neutral-50 dark:bg-neutral-950 border-neutral-200 dark:border-neutral-800 text-brand-500 focus:ring-brand-500/20">
                            <span class="text-neutral-700 dark:text-neutral-300">Varsayılan adres olsun</span>
                        </label>
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row gap-3 pt-1">
                    <button type="button" wire:click="$set('modalOpen', false)" class="flex-1 px-4 py-2.5 rounded-xl bg-neutral-100 dark:bg-neutral-800 hover:bg-neutral-200 dark:hover:bg-neutral-700 text-neutral-700 dark:text-neutral-300 text-xs font-semibold transition-colors">Vazgeç</button>
                    <button type="submit" wire:loading.attr="disabled" class="flex-1 px-4 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white text-xs font-bold shadow-lg shadow-brand-500/20 transition-all">
                        <span wire:loading.remove wire:target="save">{{ $editingId ? 'Değişiklikleri kaydet' : 'Adresi kaydet' }}</span>
                        <span wire:loading wire:target="save">Kaydediliyor...</span>
                    </button>
                </div>

            </form>
        </div>
    @endif

</div>
