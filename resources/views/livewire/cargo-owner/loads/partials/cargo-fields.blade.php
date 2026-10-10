@php
    $privateHint = \App\Support\FreightPayment::direct() ? 'teklifini kabul ettiğiniz şoför görür' : 'yalnız ödeme sonrası atanan şoför görür';
@endphp
{{--
    Yük alanları (ilan oluşturma 2. adım ve ilan düzenleme): cins, araç, kasa, biçim, ağırlık, hacim, şoföre not, e-İrsaliye.
    $restricted = true (bekleyen teklif var): yalnız şoföre not değişir. $showFile: e-İrsaliye dosya alanı (yalnız sihirbaz).
--}}
@php $restricted = $restricted ?? false; $showFile = $showFile ?? false; @endphp
<div class="space-y-6">
    <fieldset @disabled($restricted) class="min-w-0 grid grid-cols-1 sm:grid-cols-2 gap-6 disabled:opacity-60">
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
    </fieldset>

    <div>
        <label class="form-label">Şoföre not <span class="text-neutral-400 font-normal">(yükleme saati, forklift, palet sayısı…; {{ $privateHint }})</span></label>
        <textarea wire:model="notes" rows="3" maxlength="{{ \App\Models\Load::NOTES_MAX }}" class="form-input"></textarea>
        @error('notes') <span class="form-error">{{ $message }}</span> @enderror
    </div>

    <fieldset @disabled($restricted) class="min-w-0 p-4 rounded-xl bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800 space-y-4 disabled:opacity-60">
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

            @if($showFile)
                <div>
                    <label class="form-label">e-İrsaliye belgesi (JPG, PNG, PDF)</label>
                    <input type="file" wire:model="e_irsaliye_file" accept="image/jpeg,image/png,application/pdf" class="w-full text-xs text-neutral-500 dark:text-neutral-400 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-neutral-200 dark:file:bg-neutral-800 file:text-neutral-800 dark:file:text-neutral-200 hover:file:bg-neutral-300 dark:hover:file:bg-neutral-700 cursor-pointer">
                    <div wire:loading wire:target="e_irsaliye_file" class="text-[11px] text-neutral-500 mt-1">Dosya hazırlanıyor...</div>
                    @error('e_irsaliye_file') <span class="form-error">{{ $message }}</span> @enderror
                </div>
            @endif
        </div>
    </fieldset>
</div>
