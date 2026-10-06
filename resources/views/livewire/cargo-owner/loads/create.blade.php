<?php

use App\Livewire\Concerns\ManagesLoadForm;
use App\Models\DriverVehicle;
use App\Models\Load;
use App\Services\LoadService;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new
#[Layout('components.layouts.cargo-owner')]
#[Title('Yeni İlan Oluştur')]
class extends Component {
    use ManagesLoadForm, WithFileUploads;

    public int $currentStep = 1;

    public $e_irsaliye_file = null;

    public bool $terms_accepted = false;

    public function mount(): void
    {
        $this->pickup_date = Carbon::now()->addDay()->format('Y-m-d');
        $this->goods_type = Load::GOODS_TYPES[0];
        $this->vehicle_type = array_key_first(DriverVehicle::getVehicleTypes());
    }

    public function nextStep(): void
    {
        if ($this->currentStep === 1) {
            [$rules, $messages] = $this->routeRules();
            $this->validate($rules, $messages);
            $this->currentStep = 2;

            return;
        }

        if ($this->currentStep === 2) {
            [$rules, $messages] = $this->cargoRules();
            $this->validate($rules + ['e_irsaliye_file' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240'], $messages + [
                'e_irsaliye_file.mimes' => 'Belge JPG, PNG veya PDF olmalıdır.',
                'e_irsaliye_file.max' => 'Belge en fazla 10 MB olabilir.',
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

    public function submitLoad(LoadService $loads): void
    {
        $minPrice = $this->minPrice();
        [$rules, $messages] = $this->priceRules($minPrice);
        $this->validate($rules + ['terms_accepted' => 'accepted'], $messages + ['terms_accepted.accepted' => 'Devam etmek için ilan şartlarını onaylayın.']);

        $profile = Auth::user()->cargoOwnerProfile;
        if (! $profile) {
            $this->addError('price', 'Yük sahibi profiliniz bulunamadı.');

            return;
        }

        try {
            $load = $loads->publish($profile, $this->formPayload(), $this->e_irsaliye_file);
        } catch (\RuntimeException $e) {
            $this->addError('price', $e->getMessage());

            return;
        }

        session()->flash('success_message', 'İlanınız #'.$load->id.' yayına alındı; premium şoförlere anında bildirildi, '.app(\App\Services\LoadReleaseService::class)->delayMinutes().' dakika sonra tüm şoförlere ve Telegram kanalına açılır. Teklifleri ilan listenizden takip edebilirsiniz.');
        $this->redirect(route('cargo-owner.loads.index'), navigate: true);
    }

    public function minPrice(): float
    {
        return Settings::float('min_load_price');
    }

    public function with(): array
    {
        return $this->savedAddressOptions() + [
            'vehicleTypes' => DriverVehicle::getVehicleTypes(),
            'bodyOptions' => $this->bodyOptions(),
            'goodsTypes' => Load::GOODS_TYPES,
            'minPrice' => $this->minPrice(),
        ];
    }
}; ?>

<div class="max-w-4xl mx-auto space-y-8">

    <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6">
        <div class="flex items-center justify-between relative">
            <div class="absolute left-0 top-1/2 -translate-y-1/2 h-1 bg-neutral-100 dark:bg-neutral-800 w-full z-0"></div>
            <div class="absolute left-0 top-1/2 -translate-y-1/2 h-1 bg-brand-500 transition-all duration-500 z-0"
                 style="width: {{ $currentStep === 1 ? '0%' : ($currentStep === 2 ? '50%' : '100%') }};"></div>

            <div class="relative z-10 flex flex-col items-center gap-2">
                <div class="w-10 h-10 rounded-full flex items-center justify-center font-bold text-sm transition-all duration-300 {{ $currentStep >= 1 ? 'bg-brand-500 text-white shadow-lg shadow-brand-500/30 ring-4 ring-white dark:ring-neutral-950' : 'bg-neutral-100 dark:bg-neutral-800 text-neutral-500 dark:text-neutral-400' }}">1</div>
                <span class="text-xs font-semibold {{ $currentStep >= 1 ? 'text-neutral-900 dark:text-white' : 'text-neutral-500' }}">Rota & tarih</span>
            </div>

            <div class="relative z-10 flex flex-col items-center gap-2">
                <div class="w-10 h-10 rounded-full flex items-center justify-center font-bold text-sm transition-all duration-300 {{ $currentStep >= 2 ? 'bg-brand-500 text-white shadow-lg shadow-brand-500/30 ring-4 ring-white dark:ring-neutral-950' : 'bg-neutral-100 dark:bg-neutral-800 text-neutral-500 dark:text-neutral-400' }}">2</div>
                <span class="text-xs font-semibold {{ $currentStep >= 2 ? 'text-neutral-900 dark:text-white' : 'text-neutral-500' }}">Yük & belge</span>
            </div>

            <div class="relative z-10 flex flex-col items-center gap-2">
                <div class="w-10 h-10 rounded-full flex items-center justify-center font-bold text-sm transition-all duration-300 {{ $currentStep === 3 ? 'bg-brand-500 text-white shadow-lg shadow-brand-500/30 ring-4 ring-white dark:ring-neutral-950' : 'bg-neutral-100 dark:bg-neutral-800 text-neutral-500 dark:text-neutral-400' }}">3</div>
                <span class="text-xs font-semibold {{ $currentStep === 3 ? 'text-neutral-900 dark:text-white' : 'text-neutral-500' }}">Bütçe & onay</span>
            </div>
        </div>
    </div>

    <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 md:p-8 space-y-6">

        @if($currentStep === 1)
            <div class="space-y-6">
                <div class="border-b border-neutral-200 dark:border-neutral-800 pb-4">
                    <h3 class="text-lg font-bold text-neutral-900 dark:text-white flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-brand-500"></span>
                        <span>1. Adım: Yükleme ve teslimat rotası</span>
                    </h3>
                    <p class="page-subtitle">Şoförler ilanda yalnız il ve ilçeyi görür; açık adres, teklifini kabul edip ödemesini yaptığınız şoföre açılır.</p>
                </div>

                @include('livewire.cargo-owner.loads.partials.route-fields')
            </div>
        @endif

        @if($currentStep === 2)
            <div class="space-y-6">
                <div class="border-b border-neutral-200 dark:border-neutral-800 pb-4">
                    <h3 class="text-lg font-bold text-neutral-900 dark:text-white flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-brand-500"></span>
                        <span>2. Adım: Yük özellikleri ve e-İrsaliye</span>
                    </h3>
                    <p class="page-subtitle">Şoförlerin doğru teklif verebilmesi için yük tipi, araç tipi ve ağırlık bilgisi gerekir. e-İrsaliye bilgisi varsa ekleyebilirsiniz.</p>
                </div>

                @include('livewire.cargo-owner.loads.partials.cargo-fields', ['showFile' => true])
            </div>
        @endif

        @if($currentStep === 3)
            <div class="space-y-6">
                <div class="border-b border-neutral-200 dark:border-neutral-800 pb-4">
                    <h3 class="text-lg font-bold text-neutral-900 dark:text-white flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-brand-500"></span>
                        <span>3. Adım: Navlun bedeli ve ilan özeti</span>
                    </h3>
                    <p class="page-subtitle">Şoförler bu bedeli referans alarak teklif verir. Kabul ettiğiniz teklif tutarını lisanslı ödeme kuruluşu üzerinden ödersiniz; ödeme teslimat onayınızla şoföre tamamlanır.</p>
                </div>

                <div class="p-5 rounded-2xl bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 space-y-3">
                    <div class="text-xs font-semibold text-neutral-500 dark:text-neutral-400 uppercase tracking-wider">İlan önizlemesi (şoförlerin gördüğü)</div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4 text-xs">
                        <div>
                            <span class="text-neutral-500 block">Çıkış noktası</span>
                            <span class="text-neutral-900 dark:text-white font-medium">{{ $this->routePreview('pickup') }}</span>
                        </div>
                        <div>
                            <span class="text-neutral-500 block">Varış noktası</span>
                            <span class="text-neutral-900 dark:text-white font-medium">{{ $this->routePreview('delivery') }}</span>
                        </div>
                        <div>
                            <span class="text-neutral-500 block">Araç & yük</span>
                            <span class="text-neutral-900 dark:text-white font-medium">{{ implode(' · ', array_filter([$vehicleTypes[$vehicle_type] ?? $vehicle_type, \App\Support\BodyTypes::summary($body_types), \App\Support\BodyTypes::LOAD_KINDS[$load_kind] ?? null, $goods_type])) }}</span>
                        </div>
                        <div>
                            <span class="text-neutral-500 block">Yükleme tarihi</span>
                            <span class="text-neutral-900 dark:text-white font-medium">{{ $pickup_date !== '' ? \Illuminate\Support\Carbon::parse($pickup_date)->format('d.m.Y') : '—' }}</span>
                        </div>
                        <div>
                            <span class="text-neutral-500 block">En geç teslim</span>
                            <span class="text-neutral-900 dark:text-white font-medium">{{ $delivery_date !== '' ? \Illuminate\Support\Carbon::parse($delivery_date)->format('d.m.Y') : 'Belirtilmedi' }}</span>
                        </div>
                        <div>
                            <span class="text-neutral-500 block">Ağırlık / hacim</span>
                            <span class="text-neutral-900 dark:text-white font-medium">{{ number_format((int) ($weight ?: 0), 0, ',', '.') }} kg / {{ $volume !== '' ? $volume.' m³' : '—' }}</span>
                        </div>
                        <div>
                            <span class="text-neutral-500 block">e-İrsaliye no</span>
                            <span class="text-neutral-900 dark:text-white tabular-nums font-medium">{{ $e_irsaliye_no !== '' ? $e_irsaliye_no : '—' }}</span>
                        </div>
                    </div>
                    @if(trim($pickup_address_private) !== '' || trim($delivery_address_private) !== '' || trim($notes) !== '')
                        <p class="text-[11px] text-neutral-500 border-t border-neutral-200 dark:border-neutral-800 pt-3">Açık adres{{ trim($notes) !== '' ? ' ve şoföre not' : '' }} havuzda görünmez; ödemesi alınan şoföre açılır.</p>
                    @endif
                </div>

                <div class="space-y-2">
                    <label class="block text-xs font-medium text-neutral-700 dark:text-neutral-300">Navlun bedeli (₺) <span class="text-brand-500">*</span></label>
                    <div class="relative max-w-xs">
                        <input type="number" wire:model="price" inputmode="decimal" min="{{ (int) $minPrice }}" step="1" class="form-input text-lg tabular-nums">
                        <span class="absolute right-4 top-1/2 -translate-y-1/2 font-bold text-neutral-500 text-lg">₺</span>
                    </div>
                    @error('price') <span class="form-error">{{ $message }}</span> @enderror
                    <p class="text-[11px] text-neutral-500">Asgari navlun bedeli {{ number_format($minPrice, 0, ',', '.') }} ₺.</p>
                </div>

                <div class="pt-2">
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" wire:model="terms_accepted" class="form-input h-4">
                        <span class="text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">
                            Navlun ödemesi teslimat onayınızla şoföre tamamlanır. İlan bilgilerinin doğru olduğunu ve <a href="{{ route('contracts', 'kullanici-sozlesmesi') }}" target="_blank" rel="noopener" class="text-brand-400 hover:underline">kullanıcı sözleşmesini</a> kabul ediyorum.
                        </span>
                    </label>
                    @error('terms_accepted') <span class="form-error">{{ $message }}</span> @enderror
                </div>
            </div>
        @endif

        <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 pt-6 border-t border-neutral-200 dark:border-neutral-800">
            @if($currentStep > 1)
                <button type="button" wire:click="previousStep" class="btn-secondary py-2 text-xs">
                    &larr; Geri
                </button>
            @else
                <div></div>
            @endif

            @if($currentStep < 3)
                <button type="button" wire:click="nextStep" wire:loading.attr="disabled" class="btn-primary py-2 text-xs">
                    Devam et &rarr;
                </button>
            @else
                <button type="button" wire:click="submitLoad" wire:loading.attr="disabled" class="btn-primary py-3">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                    <span wire:loading.remove wire:target="submitLoad">İlanı yayına al</span>
                    <span wire:loading wire:target="submitLoad">Yayınlanıyor...</span>
                </button>
            @endif
        </div>

    </div>
</div>
