{{--
    İl → ilçe seçici (yük sahibi ilan formu ve adres defteri). İki yerel açılır kutu: telefonda (390 px) alt alta,
    geniş ekranda yan yana; büyük yazı kipinde taşmaz. İl değişince ilçe listesi sunucudan yeniden çizilir (wire:model.live).
    Kullanım: <x-place-picker province-model="pickup_province_code" district-model="pickup_district" :province-code="$pickup_province_code" label="Yükleme" />
--}}
@props([
    'provinceModel',
    'districtModel',
    'provinceCode' => null,
    'label' => 'Yer',
    'required' => true,
    'disabled' => false,
    'districtPlaceholder' => 'İl geneli',
])
@php
    $code = $provinceCode !== null && $provinceCode !== '' ? (int) $provinceCode : null;
    $districts = $code ? \App\Support\TurkishLocations::districtsOf($code) : [];
@endphp
<div {{ $attributes->merge(['class' => 'grid grid-cols-1 sm:grid-cols-2 gap-3']) }}>
    <div class="min-w-0">
        <label class="form-label">{{ $label }} ili @if($required)<span class="text-brand-500">*</span>@endif</label>
        <select wire:model.live="{{ $provinceModel }}" class="form-input" @disabled($disabled) aria-label="{{ $label }} ili">
            <option value="">İl seçin</option>
            @foreach(\App\Support\TurkishLocations::provinces() as $province)
                <option value="{{ $province['code'] }}">{{ $province['name'] }}</option>
            @endforeach
        </select>
        @error($provinceModel) <span class="form-error">{{ $message }}</span> @enderror
    </div>
    <div class="min-w-0" wire:key="{{ $districtModel }}-{{ $code ?? 0 }}">
        <label class="form-label">{{ $label }} ilçesi</label>
        <select wire:model="{{ $districtModel }}" class="form-input" @disabled($disabled || $code === null) aria-label="{{ $label }} ilçesi">
            <option value="">{{ $code === null ? 'Önce il seçin' : $districtPlaceholder }}</option>
            @foreach($districts as $district)
                <option value="{{ $district }}">{{ $district }}</option>
            @endforeach
        </select>
        @error($districtModel) <span class="form-error">{{ $message }}</span> @enderror
    </div>
</div>
