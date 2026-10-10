{{-- Filtre çubuğu (ilan havuzu + dış kaynak): arama, rota, hızlı çipler, kayıtlı filtreler, etkin rozetler; ayrıntılı süzgeç
     telefonda alttan, masaüstünde sağdan açılan panelde. Durum Livewire'da (filters); panelin açık/kapalı durumu advancedOpen ile eşlenir. --}}
@php
    $f = $normalizedFilters;
    $isExternal = $tab === 'external';
    // Eksik bilgili bölümde araç süzgeci uygulanmaz; çip gösterilmez (açık görünüp sayılmasın).
    $quick = $isExternal && $incomplete ? [] : [
        ['key' => 'mine', 'label' => 'Aracıma uygun', 'on' => $f['vehicle_mode'] === 'mine'],
    ];
    $quick = array_merge($quick, [
        ['key' => 'priced', 'label' => 'Fiyatlı', 'on' => $f['only_priced'] || $f['min_price'] !== null],
        ['key' => 'today', 'label' => 'Bugün', 'on' => $f['seen_within_hours'] === '24'],
    ]);
    if ($isExternal) {
        $quick[] = ['key' => 'urgent', 'label' => 'Acil', 'on' => $f['urgent']];
    }
    $quick = array_merge($quick, [
        ['key' => 'komple', 'label' => 'Komple', 'on' => $f['load_kind'] === 'komple'],
        ['key' => 'parca', 'label' => 'Parça', 'on' => $f['load_kind'] === 'parca'],
        ['key' => 'uzun', 'label' => '13.60', 'on' => $f['trailer_length'] === 'uzun'],
        ['key' => 'kisa', 'label' => 'Kısa dorse', 'on' => $f['trailer_length'] === 'kisa'],
        ['key' => 'body:damperli', 'label' => 'Damperli', 'on' => in_array('damperli', $f['body_types'], true)],
        ['key' => 'body:frigo', 'label' => 'Frigo', 'on' => in_array('frigo', $f['body_types'], true)],
    ]);
    $chip = 'shrink-0 inline-flex items-center gap-1 rounded-full border px-3 py-1.5 text-2xs font-semibold transition-colors whitespace-nowrap';
    $chipOff = 'border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 text-neutral-600 dark:text-neutral-300 hover:border-brand-500/60';
    $chipOn = 'border-brand-500 bg-brand-500 text-white';
    $pill = 'px-3 py-1.5 rounded-xl border text-2xs font-semibold transition-colors';
    $pillOff = 'border-neutral-200 dark:border-neutral-700 text-neutral-600 dark:text-neutral-300 hover:border-brand-500/50';
    $pillOn = 'border-brand-500 bg-brand-500/10 text-brand-600 dark:text-brand-400';
    $section = 'space-y-2';
    $heading = 'text-2xs font-bold uppercase tracking-wider text-neutral-400';
@endphp
<div x-data="{ sheet: $wire.entangle('advancedOpen') }"
     x-init="document.body.classList.toggle('overflow-hidden', !!sheet); $watch('sheet', v => document.body.classList.toggle('overflow-hidden', v)); document.addEventListener('livewire:navigating', () => document.body.classList.remove('overflow-hidden'), { once: true })"
     class="space-y-3">
    <div class="bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-3 sm:p-4 space-y-3 text-xs">
        {{-- Arama + Filtreler --}}
        <div class="flex items-center gap-2">
            <div class="relative flex-1 min-w-0">
                <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-neutral-400 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z"/></svg>
                <input type="search" wire:model.live.debounce.400ms="search" placeholder="Şehir, ilçe ya da yük ara" class="form-input pl-9 pr-9 h-11" autocomplete="off">
                @if($search !== '')
                    <button type="button" wire:click="$set('search', '')" class="absolute right-2 top-1/2 -translate-y-1/2 w-7 h-7 rounded-full text-neutral-400 hover:text-rose-500 hover:bg-neutral-100 dark:hover:bg-neutral-800" aria-label="Aramayı temizle">×</button>
                @endif
            </div>
            <button type="button" @click="sheet = true" class="h-11 shrink-0 inline-flex items-center gap-1.5 rounded-xl border px-3 font-bold transition-colors {{ $activeFilterCount > 0 ? 'border-brand-500 bg-brand-500/10 text-brand-600 dark:text-brand-400' : 'border-neutral-200 dark:border-neutral-700 text-neutral-700 dark:text-neutral-200 hover:border-brand-500/60' }}">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5h18M6 12h12M10 19h4"/></svg>
                <span>Filtreler</span>
                @if($activeFilterCount > 0)<span class="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1 rounded-full bg-brand-500 text-white text-3xs tabular-nums">{{ $activeFilterCount }}</span>@endif
            </button>
        </div>

        {{-- Rota: çıkış ⇄ varış --}}
        {{-- Telefonda kutular alt alta (yer adları sığar), ⇄ ilk satırın sağında; geniş ekranda yan yana --}}
        <div class="grid grid-cols-[minmax(0,1fr)_2.25rem] sm:grid-cols-[minmax(0,1fr)_2.25rem_minmax(0,1fr)] items-center gap-1.5 sm:gap-2 min-w-0">
            @include('livewire.driver.loads.partials.place-filter', ['side' => 'pickup', 'sideLabel' => 'Çıkış'])
            <button type="button" wire:click="swapSides" class="w-9 h-9 shrink-0 rounded-full border border-neutral-200 dark:border-neutral-700 text-neutral-500 hover:text-brand-500 hover:border-brand-500/60 flex items-center justify-center" title="Çıkış ve varışı yer değiştir" aria-label="Çıkış ve varışı yer değiştir">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"/></svg>
            </button>
            <div class="col-span-2 sm:col-span-1 min-w-0">@include('livewire.driver.loads.partials.place-filter', ['side' => 'delivery', 'sideLabel' => 'Varış'])</div>
        </div>

        {{-- Hızlı çipler: telefonda yatay kayar, taşmaz --}}
        <div class="flex gap-2 overflow-x-auto no-scrollbar -mx-3 px-3 sm:mx-0 sm:px-0 sm:flex-wrap pb-0.5">
            @if($f['near_radius_km'] !== null)
                <button type="button" wire:click="clearNear" class="{{ $chip }} {{ $chipOn }}">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21s7-6.2 7-11a7 7 0 10-14 0c0 4.8 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
                    {{ $f['near_label'] ?: 'Konumum' }} {{ $f['near_radius_km'] }} km <span class="opacity-80">×</span>
                </button>
            @else
                <button type="button" x-data
                    @click="if (!navigator.geolocation) { alert('Tarayıcınız konum desteklemiyor.'); return; } $el.disabled = true; navigator.geolocation.getCurrentPosition(p => { $wire.useMyLocation(p.coords.latitude, p.coords.longitude); $el.disabled = false; }, () => { alert('Konum alınamadı. Telefonun konum izni açık olmalı.'); $el.disabled = false; }, { enableHighAccuracy: false, timeout: 10000 })"
                    class="{{ $chip }} {{ $chipOff }}">
                    <svg class="w-3.5 h-3.5 text-sky-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21s7-6.2 7-11a7 7 0 10-14 0c0 4.8 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
                    Yakınımda
                </button>
            @endif
            @foreach($quick as $qc)
                <button type="button" wire:click="toggleQuick('{{ $qc['key'] }}')" class="{{ $chip }} {{ $qc['on'] ? $chipOn : $chipOff }}">{{ $qc['label'] }}</button>
            @endforeach
        </div>

        {{-- Kayıtlı filtreler --}}
        <div class="flex items-center gap-2 overflow-x-auto no-scrollbar -mx-3 px-3 sm:mx-0 sm:px-0 sm:flex-wrap">
            <span class="{{ $heading }} shrink-0">Kayıtlı</span>
            <button type="button" wire:click="applyPreset(null)" class="{{ $chip }} {{ $presetId === null ? 'border-neutral-900 dark:border-white bg-neutral-900 dark:bg-white text-white dark:text-neutral-900' : $chipOff }}">Serbest</button>
            @foreach($presets as $preset)
                <button type="button" wire:click="applyPreset({{ $preset->id }})" class="{{ $chip }} {{ $presetId === $preset->id ? 'border-neutral-900 dark:border-white bg-neutral-900 dark:bg-white text-white dark:text-neutral-900' : $chipOff }}" title="{{ implode(' · ', \App\Services\LoadFilterService::chips(\App\Services\LoadFilterService::normalize((array) $preset->filters))) }}">
                    @if($preset->is_default)<span class="text-amber-400">★</span>@endif{{ $preset->name }}
                </button>
            @endforeach
            <button type="button" wire:click="openPresetModal" class="{{ $chip }} border-dashed border-brand-400/60 text-brand-600 dark:text-brand-400 hover:bg-brand-500/10">{{ $presetId ? 'Güncelle' : '+ Kaydet' }}</button>
        </div>

        {{-- Etkin rozetler (× ile kaldırılır) --}}
        @if($activeFilterCount > 0 || $search !== '')
            <div class="flex flex-wrap items-center gap-1.5 pt-2 border-t border-neutral-100 dark:border-neutral-800">
                @foreach($chipItems as $ci)
                    @if($ci['key'] === 'vehicle' && $f['vehicle_mode'] === 'mine')
                        @continue
                    @endif
                    <span class="inline-flex items-center gap-1 rounded-full bg-neutral-100 dark:bg-neutral-800 pl-2.5 pr-1 py-0.5 text-2xs text-neutral-700 dark:text-neutral-200">
                        {{ $ci['label'] }}
                        <button type="button" wire:click="removeChip('{{ $ci['key'] }}')" class="w-5 h-5 rounded-full hover:bg-neutral-200 dark:hover:bg-neutral-700 hover:text-rose-500 inline-flex items-center justify-center" aria-label="{{ $ci['label'] }} süzgecini kaldır">×</button>
                    </span>
                @endforeach
                @if($search !== '')
                    <span class="inline-flex items-center gap-1 rounded-full bg-neutral-100 dark:bg-neutral-800 pl-2.5 pr-1 py-0.5 text-2xs text-neutral-700 dark:text-neutral-200">Arama: {{ $search }}<button type="button" wire:click="$set('search', '')" class="w-5 h-5 rounded-full hover:text-rose-500 inline-flex items-center justify-center" aria-label="Aramayı kaldır">×</button></span>
                @endif
                <button type="button" wire:click="clearFilters" class="ml-auto text-2xs font-bold text-neutral-500 hover:text-rose-500">Tümünü temizle</button>
            </div>
        @endif
    </div>

    {{-- Ayrıntılı süzgeç paneli --}}
    <div x-show="sheet" x-cloak class="fixed inset-0 z-[70]" role="dialog" aria-modal="true" aria-label="Filtreler" @keydown.escape.window="sheet = false">
        <div class="absolute inset-0 bg-neutral-950/60 backdrop-blur-sm" x-show="sheet" x-transition.opacity @click="sheet = false"></div>
        <div class="absolute inset-x-0 bottom-0 sm:inset-y-0 sm:left-auto sm:right-0 sm:w-[30rem] max-h-[90vh] sm:max-h-none bg-white dark:bg-neutral-900 rounded-t-3xl sm:rounded-none shadow-2xl flex flex-col text-xs"
             x-show="sheet" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-y-full sm:translate-y-0 sm:translate-x-full" x-transition:enter-end="translate-y-0 sm:translate-x-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-y-0 sm:translate-x-0" x-transition:leave-end="translate-y-full sm:translate-y-0 sm:translate-x-full">
            <div class="flex items-center justify-between px-5 pt-4 pb-3 border-b border-neutral-200 dark:border-neutral-800">
                <div class="sm:hidden absolute left-1/2 -translate-x-1/2 top-2 w-10 h-1 rounded-full bg-neutral-300 dark:bg-neutral-700"></div>
                <h3 class="text-sm font-bold text-neutral-900 dark:text-white">Filtreler @if($activeFilterCount > 0)<span class="text-neutral-400 font-normal">· {{ $activeFilterCount }} etkin</span>@endif</h3>
                <button type="button" @click="sheet = false" class="w-9 h-9 rounded-full hover:bg-neutral-100 dark:hover:bg-neutral-800 text-neutral-500 inline-flex items-center justify-center" aria-label="Kapat">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="flex-1 overflow-y-auto px-5 py-4 space-y-6">
                {{-- Araç --}}
                <div class="{{ $section }}">
                    <div class="{{ $heading }}">Araç</div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="$set('filters.vehicle_mode', 'mine')" class="{{ $pill }} {{ $f['vehicle_mode'] === 'mine' ? $pillOn : $pillOff }}">Aracıma uygun</button>
                        <button type="button" wire:click="$set('filters.vehicle_mode', 'any')" class="{{ $pill }} {{ $f['vehicle_mode'] === 'any' ? $pillOn : $pillOff }}">Tüm tipler</button>
                        <button type="button" wire:click="$set('filters.vehicle_mode', 'custom')" class="{{ $pill }} {{ $f['vehicle_mode'] === 'custom' ? $pillOn : $pillOff }}">Seçtiklerim</button>
                    </div>
                    @if($f['vehicle_mode'] === 'mine' && $myVehicleType)
                        <p class="text-2xs text-neutral-500">{{ implode(' · ', array_filter([\App\Support\VehicleTypes::label($myVehicleType).' ve altı', $myVehicleLength ? \App\Support\BodyTypes::TRAILER_LENGTHS[$myVehicleLength] ?? null : null, $myVehicleBody ? \App\Support\BodyTypes::label($myVehicleBody) : null])) }}@if(! $myVehicleBody) · <a href="{{ route('driver.vehicles.index') }}" class="underline text-amber-600" wire:navigate>kasa tipinizi ekleyin</a>@endif</p>
                    @endif
                    @if($f['vehicle_mode'] === 'custom')
                        <div class="flex flex-wrap gap-2">
                            @foreach(\App\Support\VehicleTypes::FORM_ORDER as $type)
                                <button type="button" wire:click="toggleVehicleType('{{ $type }}')" class="{{ $pill }} inline-flex items-center gap-1.5 {{ in_array($type, $f['vehicle_types'], true) ? $pillOn : $pillOff }}">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">{!! \App\Support\VehicleTypes::iconPath($type) !!}</svg>{{ \App\Support\VehicleTypes::label($type) }}
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Kasa ve dorse --}}
                <div class="{{ $section }}">
                    <div class="{{ $heading }}">Kasa <span class="normal-case font-normal tracking-normal">· kasa yazmayan ilan gizlenmez</span></div>
                    <div class="flex flex-wrap gap-2">
                        @foreach(\App\Support\BodyTypes::TYPES as $bk => $bmeta)
                            <button type="button" wire:click="toggleBodyType('{{ $bk }}')" class="{{ $pill }} {{ in_array($bk, $f['body_types'], true) ? $pillOn : $pillOff }}">{{ $bmeta['label'] }}</button>
                        @endforeach
                    </div>
                    <div class="{{ $heading }} pt-1">Dorse boyu</div>
                    <div class="flex flex-wrap gap-2">
                        @foreach($trailerLengths as $lk => $ll)
                            <button type="button" wire:click="$set('filters.trailer_length', '{{ $lk }}')" class="{{ $pill }} {{ $f['trailer_length'] === $lk ? $pillOn : $pillOff }}">{{ $ll }}</button>
                        @endforeach
                    </div>
                </div>

                {{-- Yük --}}
                <div class="{{ $section }}">
                    <div class="{{ $heading }}">Yük</div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="$set('filters.load_kind', '')" class="{{ $pill }} {{ $f['load_kind'] === '' ? $pillOn : $pillOff }}">Komple / parça fark etmez</button>
                        @foreach(\App\Support\BodyTypes::LOAD_KINDS as $lk => $ll)
                            <button type="button" wire:click="$set('filters.load_kind', '{{ $lk }}')" class="{{ $pill }} {{ $f['load_kind'] === $lk ? $pillOn : $pillOff }}">{{ $ll }}</button>
                        @endforeach
                    </div>
                    <div class="flex flex-wrap gap-1.5 pt-1">
                        @foreach($goodsLabels as $gl)
                            <button type="button" wire:click="toggleGoodsCategory(@js($gl))" class="px-2.5 py-1 rounded-full border text-2xs transition-colors {{ in_array($gl, $f['goods_categories'], true) ? $pillOn.' font-semibold' : $pillOff }}">{{ $gl }}</button>
                        @endforeach
                    </div>
                    <input type="text" wire:model.live.debounce.500ms="filters.goods_keywords" placeholder="Başka bir yük sözcüğü: palet, koli, çimento…" class="form-input">
                </div>

                {{-- Tonaj --}}
                <div class="{{ $section }}">
                    <div class="{{ $heading }}">Tonaj <span class="normal-case font-normal tracking-normal">· tonaj yazmayan ilan gizlenmez</span></div>
                    <div class="flex flex-wrap gap-2">
                        @foreach($weightPresets as $wk => $wl)
                            @php [$wmin, $wmax] = explode('-', $wk); $wOn = (int) $wmin === (int) ($f['min_weight'] ?? -1) && ($wmax === '' ? $f['max_weight'] === null : (int) $wmax === (int) ($f['max_weight'] ?? -1)); @endphp
                            <button type="button" wire:click="setWeightPreset('{{ $wk }}')" class="{{ $pill }} {{ $wOn ? $pillOn : $pillOff }}">{{ $wl }}</button>
                        @endforeach
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="number" inputmode="numeric" min="0" step="100" wire:model.live.debounce.500ms="filters.min_weight" class="form-input" placeholder="En az kg">
                        <input type="number" inputmode="numeric" min="0" step="100" wire:model.live.debounce.500ms="filters.max_weight" class="form-input" placeholder="En çok kg">
                    </div>
                </div>

                {{-- Fiyat --}}
                <div class="{{ $section }}">
                    <div class="{{ $heading }}">Fiyat</div>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="number" inputmode="numeric" min="0" step="500" wire:model.live.debounce.500ms="filters.min_price" class="form-input" placeholder="En az ₺">
                        <input type="number" inputmode="numeric" min="0" step="500" wire:model.live.debounce.500ms="filters.max_price" class="form-input" placeholder="En çok ₺">
                    </div>
                    <label class="inline-flex items-center gap-2 text-2xs text-neutral-600 dark:text-neutral-300"><input type="checkbox" wire:model.live="filters.only_priced" class="accent-brand-500 w-4 h-4"> Yalnız fiyat yazan ilanlar</label>
                    @if($isExternal)<p class="text-2xs text-neutral-500">Ton başı yazılan fiyat, tonajla çarpılarak araç başı karşılığıyla süzülür.</p>@endif
                </div>

                {{-- Zaman --}}
                <div class="{{ $section }}">
                    <div class="{{ $heading }}">{{ $isExternal ? 'Paylaşım zamanı' : 'Yayın zamanı' }}</div>
                    <div class="flex flex-wrap gap-2">
                        @foreach($seenWithin as $sk => $sl)
                            <button type="button" wire:click="$set('filters.seen_within_hours', '{{ $sk }}')" class="{{ $pill }} {{ $f['seen_within_hours'] === $sk ? $pillOn : $pillOff }}">{{ $sl }}</button>
                        @endforeach
                    </div>
                    @if($tab === 'pool')
                        <div class="{{ $heading }} pt-1">Yükleme tarihi</div>
                        <div class="flex flex-wrap gap-2">
                            @foreach($withinDays as $wk => $wl)
                                <button type="button" wire:click="$set('filters.pickup_within_days', '{{ $wk }}')" class="{{ $pill }} {{ $f['pickup_within_days'] === $wk ? $pillOn : $pillOff }}">{{ $wl }}</button>
                            @endforeach
                        </div>
                    @else
                        <label class="inline-flex items-center gap-2 text-2xs text-neutral-600 dark:text-neutral-300"><input type="checkbox" wire:model.live="filters.urgent" class="accent-brand-500 w-4 h-4"> Yalnız "acil" yazan ilanlar</label>
                    @endif
                </div>

                {{-- Yakınımda --}}
                <div class="{{ $section }}">
                    <div class="{{ $heading }}">Çıkış noktası çevresi</div>
                    <div class="flex flex-wrap gap-2 items-center">
                        <select wire:model.live="filters.near_radius_km" class="form-input w-auto">
                            <option value="">Kapalı</option>
                            @foreach($radii as $km)<option value="{{ $km }}">{{ $km }} km</option>@endforeach
                        </select>
                        <select class="form-input w-auto" wire:change="useProvinceCenter($event.target.value); $event.target.value = ''">
                            <option value="">İl merkezi seç…</option>
                            @foreach($provinces as $province)<option value="{{ $province['code'] }}">{{ $province['name'] }}</option>@endforeach
                        </select>
                        @if($f['near_radius_km'] !== null)
                            <span class="badge bg-sky-500/10 text-sky-700 dark:text-sky-400">{{ $f['near_label'] ?: 'Konumum' }} <button type="button" wire:click="clearNear" class="ml-1 hover:text-rose-500" aria-label="Kaldır">×</button></span>
                        @endif
                    </div>
                    <p class="text-2xs text-neutral-500">Merkez: hızlı çiplerdeki "Yakınımda" (telefon konumu) ya da bir il merkezi. Mesafeye göre sıralama bununla açılır.</p>
                </div>
            </div>

            <div class="flex items-center gap-2 px-5 py-3 border-t border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900">
                <button type="button" wire:click="clearFilters" class="btn-secondary py-2.5 text-xs">Temizle</button>
                <button type="button" @click="sheet = false" class="btn-primary flex-1 py-2.5 text-xs">{{ number_format($resultTotal, 0, ',', '.') }} ilanı göster</button>
            </div>
        </div>
    </div>

    @if($presetModalOpen)
        <div class="fixed inset-0 z-[9999] flex items-center justify-center p-4">
            <div class="fixed inset-0 bg-neutral-950/70 backdrop-blur-md" wire:click="$set('presetModalOpen', false)"></div>
            <form wire:submit.prevent="savePreset" class="relative z-10 w-full max-w-md bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl p-6 shadow-2xl space-y-4 text-xs">
                <h3 class="text-base font-bold text-neutral-900 dark:text-white">{{ $presetId ? 'Kayıtlı filtreyi güncelle' : 'Filtreyi kaydet' }}</h3>
                <div>
                    <label class="form-label">Filtre adı</label>
                    <input type="text" wire:model="presetName" placeholder="Örn. Ankara çıkışlı tır yükleri" class="form-input" autofocus>
                    @error('presetName') <span class="form-error">{{ $message }}</span> @enderror
                </div>
                <div class="flex flex-wrap gap-1.5 text-2xs text-neutral-500">
                    @foreach($filterChips as $chipLabel)<span class="px-2 py-0.5 rounded-full bg-neutral-100 dark:bg-neutral-800">{{ $chipLabel }}</span>@endforeach
                </div>
                <label class="inline-flex items-start gap-2"><input type="checkbox" wire:model="presetDefault" class="accent-brand-500 w-4 h-4 mt-0.5"> <span>Varsayılan filtrem olsun: havuz her açılışta bununla gelir, yeni ilan bildirimleri buna göre seçilir.</span></label>
                <div class="flex gap-3 pt-2">
                    @if($presetId)<button type="button" wire:click="deletePreset" wire:confirm="Bu kayıtlı filtre silinecek. Devam edilsin mi?" class="btn-danger py-2 text-xs">Sil</button>@endif
                    <button type="button" wire:click="$set('presetModalOpen', false)" class="btn-secondary flex-1">Vazgeç</button>
                    <button type="submit" class="btn-primary flex-1">Kaydet</button>
                </div>
            </form>
        </div>
    @endif
</div>
