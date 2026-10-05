<?php

use App\Models\DriverVehicle;
use App\Support\BodyTypes;
use App\Support\VehicleTypes;
use App\Models\Load;
use App\Models\SavedAddress;
use App\Services\LoadService;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new
#[Layout('components.layouts.cargo-owner')]
#[Title('Yeni İlan Oluştur')]
class extends Component {
    use WithFileUploads;

    public int $currentStep = 1;

    public string $pickup_location = '';

    public string $delivery_location = '';

    public string $pickup_date = '';

    public string $delivery_date = '';

    public string $selected_saved_pickup = '';

    public string $selected_saved_delivery = '';

    public string $goods_type = '';

    public string $vehicle_type = '';

    /** İstenen kasa tipleri (çoklu); boş: fark etmez. */
    public array $body_types = [];

    /** komple | parca */
    public string $load_kind = 'komple';

    public string $weight = '';

    public string $volume = '';

    public string $e_irsaliye_no = '';

    public $e_irsaliye_file = null;

    public string $price = '';

    public bool $terms_accepted = false;

    public function mount(): void
    {
        $this->pickup_date = Carbon::now()->addDay()->format('Y-m-d');
        $this->goods_type = Load::GOODS_TYPES[0];
        $this->vehicle_type = array_key_first(DriverVehicle::getVehicleTypes());
    }

    /** Araç sınıfı değişince o sınıfta geçersiz kasa seçimleri düşer. */
    public function updatedVehicleType(): void
    {
        $this->body_types = array_values(array_intersect(BodyTypes::clean($this->body_types), $this->bodyOptions()));
    }

    /** Seçili araç sınıfında geçerli kasa tipleri. */
    public function bodyOptions(): array
    {
        return BodyTypes::forClass(VehicleTypes::classOf($this->vehicle_type));
    }

    public function updatedSelectedSavedPickup(string $value): void
    {
        if ($address = $this->savedAddress($value)) {
            $this->pickup_location = $address->fullAddress();
        }
    }

    public function updatedSelectedSavedDelivery(string $value): void
    {
        if ($address = $this->savedAddress($value)) {
            $this->delivery_location = $address->fullAddress();
        }
    }

    private function savedAddress(string $id): ?SavedAddress
    {
        if ($id === '' || ! ctype_digit($id)) {
            return null;
        }

        return SavedAddress::query()->whereKey((int) $id)->where('user_id', Auth::id())->first();
    }

    public function nextStep(): void
    {
        if ($this->currentStep === 1) {
            $this->validate([
                'pickup_location' => 'required|string|min:5|max:255',
                'delivery_location' => 'required|string|min:5|max:255',
                'pickup_date' => 'required|date|after_or_equal:today',
                'delivery_date' => 'nullable|date|after_or_equal:pickup_date',
            ], [
                'pickup_location.required' => 'Lütfen yükleme adresini belirtin.',
                'pickup_location.min' => 'Yükleme adresi en az 5 karakter olmalıdır.',
                'delivery_location.required' => 'Lütfen teslimat adresini belirtin.',
                'delivery_location.min' => 'Teslimat adresi en az 5 karakter olmalıdır.',
                'pickup_date.after_or_equal' => 'Yükleme tarihi bugünden önce olamaz.',
                'delivery_date.after_or_equal' => 'Teslimat tarihi, yükleme tarihinden önce olamaz.',
            ]);
            $this->currentStep = 2;

            return;
        }

        if ($this->currentStep === 2) {
            $this->validate([
                'goods_type' => ['required', Rule::in(Load::GOODS_TYPES)],
                'vehicle_type' => ['required', Rule::in(array_keys(DriverVehicle::getVehicleTypes()))],
                'body_types' => ['array'],
                'body_types.*' => [Rule::in($this->bodyOptions())],
                'load_kind' => ['required', Rule::in(array_keys(BodyTypes::LOAD_KINDS))],
                'weight' => 'required|integer|min:1|max:100000',
                'volume' => 'nullable|integer|min:0|max:10000',
                'e_irsaliye_no' => 'nullable|string|max:40',
                'e_irsaliye_file' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
            ], [
                'weight.required' => 'Lütfen tahmini ağırlığı girin.',
                'weight.integer' => 'Ağırlık tam sayı (kg) olmalıdır.',
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

        $this->validate([
            'price' => 'required|numeric|min:'.$minPrice.'|max:10000000',
            'terms_accepted' => 'accepted',
        ], [
            'price.required' => 'Lütfen navlun bedelini belirtin.',
            'price.min' => 'Navlun bedeli en az '.number_format($minPrice, 0, ',', '.').' ₺ olmalıdır.',
            'terms_accepted.accepted' => 'Devam etmek için ilan şartlarını onaylayın.',
        ]);

        $profile = Auth::user()->cargoOwnerProfile;
        if (! $profile) {
            $this->addError('price', 'Yük sahibi profiliniz bulunamadı.');

            return;
        }

        try {
            $load = $loads->publish($profile, [
                'pickup_location' => $this->pickup_location,
                'delivery_location' => $this->delivery_location,
                'pickup_date' => Carbon::parse($this->pickup_date)->startOfDay(),
                'delivery_date' => $this->delivery_date !== '' ? Carbon::parse($this->delivery_date)->endOfDay() : null,
                'vehicle_type' => $this->vehicle_type,
                'body_types' => $this->body_types,
                'load_kind' => $this->load_kind,
                'goods_type' => $this->goods_type,
                'weight' => $this->weight,
                'volume' => $this->volume,
                'price' => (float) $this->price,
                'e_irsaliye_no' => trim($this->e_irsaliye_no) !== '' ? trim($this->e_irsaliye_no) : null,
            ], $this->e_irsaliye_file);
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
        $addresses = SavedAddress::query()
            ->where('user_id', Auth::id())
            ->orderByDesc('is_default')
            ->orderBy('title')
            ->get();

        return [
            'pickupAddresses' => $addresses->whereIn('type', ['pickup', 'both'])->values(),
            'deliveryAddresses' => $addresses->whereIn('type', ['delivery', 'both'])->values(),
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
                    <p class="page-subtitle">Yükün alınacağı ve teslim edileceği açık adresleri yazın veya adres defterinizden seçin.</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-neutral-500 dark:text-neutral-400 mb-1.5">Kayıtlı yükleme adresi (isteğe bağlı)</label>
                        <select wire:model.live="selected_saved_pickup" class="form-input">
                            <option value="">Adres defterinden seç</option>
                            @foreach($pickupAddresses as $addr)
                                <option value="{{ $addr->id }}">{{ $addr->title }} ({{ $addr->district }} / {{ $addr->city }})</option>
                            @endforeach
                        </select>
                        @if($pickupAddresses->isEmpty())
                            <p class="text-[11px] text-neutral-500 mt-1">Henüz kayıtlı adresiniz yok. <a href="{{ route('cargo-owner.address-book.index') }}" wire:navigate class="text-brand-400 hover:underline">Adres defteri</a></p>
                        @endif
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-neutral-500 dark:text-neutral-400 mb-1.5">Kayıtlı teslimat adresi (isteğe bağlı)</label>
                        <select wire:model.live="selected_saved_delivery" class="form-input">
                            <option value="">Adres defterinden seç</option>
                            @foreach($deliveryAddresses as $addr)
                                <option value="{{ $addr->id }}">{{ $addr->title }} ({{ $addr->district }} / {{ $addr->city }})</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="form-label">Yükleme (çıkış) açık adresi <span class="text-brand-500">*</span></label>
                        <textarea wire:model="pickup_location" rows="2" placeholder="Mahalle, cadde, kapı numarası, ilçe / il" class="form-input"></textarea>
                        @error('pickup_location') <span class="form-error">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="form-label">Teslimat (varış) açık adresi <span class="text-brand-500">*</span></label>
                        <textarea wire:model="delivery_location" rows="2" placeholder="Mahalle, cadde, kapı numarası, ilçe / il" class="form-input"></textarea>
                        @error('delivery_location') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="form-label">Yükleme tarihi <span class="text-brand-500">*</span></label>
                        <input type="date" wire:model="pickup_date" min="{{ now()->format('Y-m-d') }}" class="w-full bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 rounded-xl px-4 py-2.5 text-sm text-neutral-900 dark:text-white focus:border-brand-500 focus:outline-none">
                        @error('pickup_date') <span class="form-error">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="form-label">En geç teslim tarihi (isteğe bağlı)</label>
                        <input type="date" wire:model="delivery_date" class="form-input">
                        @error('delivery_date') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>
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

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    <div>
                        <label class="form-label">Yük cinsi <span class="text-brand-500">*</span></label>
                        <select wire:model="goods_type" class="form-input">
                            @foreach($goodsTypes as $type)
                                <option value="{{ $type }}">{{ $type }}</option>
                            @endforeach
                        </select>
                        @error('goods_type') <span class="form-error">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="form-label">Talep edilen araç tipi <span class="text-brand-500">*</span></label>
                        <x-vehicle-type-picker model="vehicle_type" columns="grid-cols-2 sm:grid-cols-5" />
                        @error('vehicle_type') <span class="form-error">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="form-label">Kasa / dorse tipi <span class="text-neutral-400 font-normal">(birden çok seçilebilir; boş bırakırsanız fark etmez)</span></label>
                        <div class="flex flex-wrap gap-2" wire:key="body-options-{{ $vehicle_type }}">
                            @foreach($bodyOptions as $bk)
                                <label class="cursor-pointer">
                                    <input type="checkbox" wire:model="body_types" value="{{ $bk }}" class="peer sr-only">
                                    <span class="inline-flex items-center px-3 py-1.5 rounded-xl border border-neutral-200 dark:border-neutral-700 text-xs font-semibold text-neutral-700 dark:text-neutral-200 transition-colors peer-checked:border-brand-500 peer-checked:bg-brand-500/10 peer-checked:text-brand-600 dark:peer-checked:text-brand-400 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500/40">{{ \App\Support\BodyTypes::label($bk) }}</span>
                                </label>
                            @endforeach
                        </div>
                        <p class="text-[11px] text-neutral-500 mt-1">Şoförler kasasına uyan ilanları görür: dökme yük için "Damperli", soğuk zincir için "Frigo" seçin.</p>
                        @error('body_types.*') <span class="form-error">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="form-label">Yük biçimi <span class="text-brand-500">*</span></label>
                        <div class="grid grid-cols-2 gap-2" role="radiogroup">
                            @foreach(\App\Support\BodyTypes::LOAD_KINDS as $lk => $ll)
                                <label class="cursor-pointer">
                                    <input type="radio" wire:model="load_kind" value="{{ $lk }}" class="peer sr-only">
                                    <span class="flex flex-col rounded-2xl border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 p-3 transition-all peer-checked:border-brand-500 peer-checked:bg-brand-500/10 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500/40">
                                        <span class="text-xs font-semibold text-neutral-800 dark:text-neutral-100">{{ $ll }}</span>
                                        <span class="text-[11px] text-neutral-500">{{ $lk === 'komple' ? 'Aracın tamamı bu yüke ayrılır' : 'Araçta boşluk olan şoför alır (birkaç palet / koli)' }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        @error('load_kind') <span class="form-error">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="form-label">Tahmini ağırlık (kg) <span class="text-brand-500">*</span></label>
                        <input type="number" wire:model="weight" inputmode="numeric" min="1" placeholder="Örn: 24000" class="form-input">
                        @error('weight') <span class="form-error">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="form-label">Hacim (m³, isteğe bağlı)</label>
                        <input type="number" wire:model="volume" inputmode="numeric" min="0" placeholder="Örn: 80" class="form-input">
                        @error('volume') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="p-4 rounded-xl bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 space-y-4">
                    <div class="flex items-center gap-2 text-xs font-semibold text-brand-400">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <span>e-İrsaliye bilgisi (isteğe bağlı)</span>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="form-label">e-İrsaliye numarası</label>
                            <input type="text" wire:model="e_irsaliye_no" maxlength="40" class="form-input tabular-nums">
                            @error('e_irsaliye_no') <span class="form-error">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="form-label">e-İrsaliye belgesi (JPG, PNG, PDF)</label>
                            <input type="file" wire:model="e_irsaliye_file" accept="image/jpeg,image/png,application/pdf" class="w-full text-xs text-neutral-500 dark:text-neutral-400 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-neutral-200 dark:file:bg-neutral-800 file:text-neutral-800 dark:file:text-neutral-200 hover:file:bg-neutral-300 dark:hover:file:bg-neutral-700 cursor-pointer">
                            <div wire:loading wire:target="e_irsaliye_file" class="text-[11px] text-neutral-500 mt-1">Dosya hazırlanıyor...</div>
                            @error('e_irsaliye_file') <span class="form-error">{{ $message }}</span> @enderror
                        </div>
                    </div>
                </div>
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
                    <div class="text-xs font-semibold text-neutral-500 dark:text-neutral-400 uppercase tracking-wider">İlan önizlemesi</div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4 text-xs">
                        <div>
                            <span class="text-neutral-500 block">Çıkış noktası</span>
                            <span class="text-neutral-900 dark:text-white font-medium line-clamp-2">{{ $pickup_location }}</span>
                        </div>
                        <div>
                            <span class="text-neutral-500 block">Varış noktası</span>
                            <span class="text-neutral-900 dark:text-white font-medium line-clamp-2">{{ $delivery_location }}</span>
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
