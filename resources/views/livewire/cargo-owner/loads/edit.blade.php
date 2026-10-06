<?php

use App\Livewire\Concerns\ManagesLoadForm;
use App\Models\DriverVehicle;
use App\Models\Load;
use App\Services\LoadService;
use App\Support\Settings;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

/**
 * İlan düzenleme: yalnız teklif bekleyen ilan. Bekleyen teklif yoksa her alan; teklif varsa yalnız tarihler, açık adresler,
 * yükleme yetkilisi ve şoföre not değişir (şoförler teklifi rota, araç ve bedele göre verdi). Form alanları sihirbazla ortak
 * (ManagesLoadForm + partials).
 */
new
#[Layout('components.layouts.cargo-owner')]
#[Title('İlanı Düzenle')]
class extends Component {
    use ManagesLoadForm;

    #[Locked]
    public int $loadId = 0;

    #[Locked]
    public bool $restricted = false;

    public function mount(int $loadId): void
    {
        $this->loadId = $loadId;
        $load = $this->ownerLoad();

        if (! $load) {
            session()->flash('error_message', 'İlan bulunamadı veya size ait değil.');
            $this->redirect(route('cargo-owner.loads.index'), navigate: true);

            return;
        }
        if (! $load->isEditableByOwner()) {
            session()->flash('error_message', 'Yalnız teklif bekleyen ilan düzenlenebilir.');
            $this->redirect(route('cargo-owner.loads.index'), navigate: true);

            return;
        }

        $this->restricted = $load->offers()->where('status', 'pending')->exists();
        $this->fillFromLoad($load);
    }

    private function ownerLoad(): ?Load
    {
        return Load::query()
            ->whereKey($this->loadId)
            ->where('cargo_owner_profile_id', (int) Auth::user()->cargoOwnerProfile?->id)
            ->first();
    }

    public function save(LoadService $loads): void
    {
        $load = $this->ownerLoad();
        if (! $load) {
            session()->flash('error_message', 'İlan bulunamadı.');
            $this->redirect(route('cargo-owner.loads.index'), navigate: true);

            return;
        }

        if ($this->restricted) {
            [$rules, $messages] = $this->dateRules();
            [$privateRules, $privateMessages] = $this->privateRules();
            $this->validate($rules + $privateRules, $messages + $privateMessages);
        } else {
            [$routeRules, $routeMessages] = $this->routeRules();
            [$cargoRules, $cargoMessages] = $this->cargoRules();
            [$priceRules, $priceMessages] = $this->priceRules(Settings::float('min_load_price'));
            $this->validate($routeRules + $cargoRules + $priceRules, $routeMessages + $cargoMessages + $priceMessages);
        }

        $profile = Auth::user()->cargoOwnerProfile;
        try {
            $changed = $loads->update($load, $profile, $this->formPayload());
        } catch (\RuntimeException $e) {
            session()->flash('error_message', $e->getMessage());

            return;
        }

        session()->flash('success_message', $changed === [] ? 'İlanda değişiklik yapılmadı.' : 'İlan #'.$load->id.' güncellendi.');
        $this->redirect(route('cargo-owner.loads.index'), navigate: true);
    }

    public function with(): array
    {
        return $this->savedAddressOptions() + [
            'load' => $this->ownerLoad(),
            'vehicleTypes' => DriverVehicle::getVehicleTypes(),
            'bodyOptions' => $this->bodyOptions(),
            'goodsTypes' => Load::GOODS_TYPES,
            'minPrice' => Settings::float('min_load_price'),
        ];
    }
}; ?>

<div class="max-w-4xl mx-auto space-y-6">

    @if (session()->has('error_message'))
        <div class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-600 dark:text-rose-400 text-xs font-semibold">
            {{ session('error_message') }}
        </div>
    @endif

    <div class="border-b border-neutral-200 dark:border-neutral-800 pb-4">
        <a href="{{ route('cargo-owner.loads.index') }}" wire:navigate class="text-xs text-neutral-500 dark:text-neutral-400 hover:text-brand-400 font-semibold inline-flex items-center gap-1.5 mb-1 transition-colors">
            &larr; İlanlarıma dön
        </a>
        <h2 class="text-xl font-bold text-neutral-900 dark:text-white tracking-tight flex items-center gap-2">
            <span>İlanı düzenle</span>
            <span class="px-2.5 py-0.5 rounded-full bg-brand-500/10 text-brand-400 tabular-nums text-xs font-bold border border-brand-500/20">#{{ $loadId }}</span>
        </h2>
        @if($restricted)
            <p class="page-subtitle">Bu ilana teklif geldi: şoförler teklifini rotaya, araca ve bedele göre verdi. Yükleme tarihi, açık adres, yükleme yetkilisi ve şoföre not değiştirilebilir.</p>
        @else
            <p class="page-subtitle">Henüz teklif gelmedi; ilanın her alanı değiştirilebilir.</p>
        @endif
    </div>

    @if($load)
        <form wire:submit.prevent="save" class="space-y-6">
            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 md:p-8 space-y-6">
                <h3 class="section-title">Rota ve tarih</h3>
                @include('livewire.cargo-owner.loads.partials.route-fields', ['restricted' => $restricted])
            </div>

            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 md:p-8 space-y-6">
                <h3 class="section-title">Yük ve not</h3>
                @include('livewire.cargo-owner.loads.partials.cargo-fields', ['restricted' => $restricted, 'showFile' => false])
            </div>

            <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 md:p-8 space-y-4">
                <h3 class="section-title">Navlun bedeli</h3>
                <fieldset @disabled($restricted) class="min-w-0 space-y-2 disabled:opacity-60">
                    <div class="relative max-w-xs">
                        <input type="number" wire:model="price" inputmode="decimal" min="{{ (int) $minPrice }}" step="1" class="form-input text-lg tabular-nums">
                        <span class="absolute right-4 top-1/2 -translate-y-1/2 font-bold text-neutral-500 text-lg">₺</span>
                    </div>
                    @error('price') <span class="form-error">{{ $message }}</span> @enderror
                    <p class="text-[11px] text-neutral-500">Asgari navlun bedeli {{ number_format($minPrice, 0, ',', '.') }} ₺.</p>
                </fieldset>

                <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 pt-4 border-t border-neutral-200 dark:border-neutral-800">
                    <a href="{{ route('cargo-owner.loads.index') }}" wire:navigate class="btn-secondary py-2 text-xs">Vazgeç</a>
                    <button type="submit" wire:loading.attr="disabled" class="btn-primary py-2.5 text-xs">
                        <span wire:loading.remove wire:target="save">Değişiklikleri kaydet</span>
                        <span wire:loading wire:target="save">Kaydediliyor...</span>
                    </button>
                </div>
            </div>
        </form>
    @endif
</div>
