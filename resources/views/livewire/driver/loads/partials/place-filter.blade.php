{{-- Çıkış / Varış seçim kutusu: seçili iller kutuda etiket, açılınca il etiketleri + arama + seçili illerin ilçeleri.
     Değişkenler: $side (pickup|delivery), $sideLabel; dış bileşenden $normalizedFilters, $provinces, $openDistricts gelir. --}}
@php $selCodes = array_map('intval', $normalizedFilters[$side.'_provinces']); $selDistricts = $normalizedFilters[$side.'_districts']; @endphp
<div x-data="{ open: false, q: '' }" @click.outside="open = false" class="relative min-w-0">
    {{-- Telefonda rozetler tek satırda yatay kayar (bölünmez, üst üste binmez; kutu 44 px kalır); geniş ekranda sarar --}}
    <button type="button" @click="open = !open" class="w-full min-w-0 overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-950 px-3 py-2 text-left min-h-[44px] flex items-center gap-2" :aria-expanded="open" aria-label="{{ $sideLabel }} yeri seç">
        <span class="text-3xs font-bold uppercase tracking-wider {{ $side === 'pickup' ? 'text-brand-500' : 'text-emerald-600 dark:text-emerald-400' }} shrink-0">{{ $sideLabel }}</span>
        {{-- Telefonda tek satır özet (kırpılmaz, taşmaz): "Çorum, Karaman ·1 ilçe"; rozetler geniş ekranda --}}
        @if($selCodes !== [])
            <span class="sm:hidden min-w-0 flex-1 truncate text-xs font-semibold {{ $side === 'pickup' ? 'text-brand-600 dark:text-brand-400' : 'text-emerald-700 dark:text-emerald-400' }}">{{ implode(', ', array_map(fn ($code) => (\App\Support\TurkishLocations::province($code)['name'] ?? $code).((($selDistricts[$code] ?? []) !== []) ? ' ('.implode(', ', $selDistricts[$code]).')' : ''), $selCodes)) }}</span>
        @endif
        <span class="{{ $selCodes !== [] ? 'hidden sm:flex' : 'flex' }} flex-wrap items-center gap-1 min-w-0 flex-1">
            @forelse($selCodes as $code)
                <span class="inline-flex shrink-0 items-center gap-1 px-2 py-0.5 rounded-lg text-2xs font-semibold whitespace-nowrap {{ $side === 'pickup' ? 'bg-brand-500/10 text-brand-600 dark:text-brand-400' : 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400' }}">
                    {{ \App\Support\TurkishLocations::province($code)['name'] ?? $code }}@if(($selDistricts[$code] ?? []) !== []) <span class="font-normal opacity-80">·{{ count($selDistricts[$code]) }} ilçe</span>@endif
                    <span role="button" wire:click.stop="removeProvince('{{ $side }}', {{ $code }})" class="ml-0.5 hover:text-rose-500" aria-label="Kaldır">×</span>
                </span>
            @empty
                <span class="text-xs font-semibold text-neutral-700 dark:text-neutral-200 whitespace-nowrap">Her yer</span>
            @endforelse
        </span>
        <svg class="w-4 h-4 text-neutral-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
    </button>
    <div x-show="open" x-cloak x-transition.opacity class="absolute z-40 mt-1 {{ $side === 'delivery' ? 'right-0' : 'left-0' }} w-[calc(100vw-2rem)] sm:w-[28rem] max-w-[calc(100vw-2rem)] rounded-2xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 shadow-apple-lg p-3 space-y-2">
        <div class="flex items-center gap-2">
            <input type="text" x-model="q" placeholder="İl ara" class="form-input py-1.5 text-xs flex-1" autocomplete="off">
            <button type="button" wire:click="clearSide('{{ $side }}')" class="tab-pill py-1.5 {{ $selCodes === [] ? 'tab-pill-active' : '' }}">Her yer</button>
        </div>
        <div class="max-h-56 overflow-y-auto flex flex-wrap gap-1.5 pr-1">
            @foreach($provinces as $province)
                <button type="button" wire:click="toggleProvince('{{ $side }}', {{ $province['code'] }})" x-show="!q || {{ json_encode(\App\Support\TurkishText::lower($province['name'])) }}.includes(q.toLocaleLowerCase('tr'))"
                    class="px-2.5 py-1 rounded-lg border text-2xs font-semibold transition-colors {{ in_array((int) $province['code'], $selCodes, true) ? 'border-brand-500 bg-brand-500/10 text-brand-600 dark:text-brand-400' : 'border-neutral-200 dark:border-neutral-700 text-neutral-600 dark:text-neutral-300 hover:border-brand-500/50' }}">{{ $province['name'] }}</button>
            @endforeach
        </div>
        {{-- Seçili illerin ilçeleri: etiket olarak, birden çok seçilir; hiçbiri seçili değilse ilin tamamı --}}
        @foreach($selCodes as $code)
            @php $districtNames = \App\Support\TurkishLocations::districtsOf($code); $picked = $selDistricts[$code] ?? []; @endphp
            @if($districtNames !== [])
                @php $boxOpen = $picked !== [] || in_array($side.':'.$code, $openDistricts, true); @endphp
                <div class="pt-2 border-t border-neutral-100 dark:border-neutral-800" wire:key="districts-{{ $side }}-{{ $code }}">
                    <button type="button" wire:click="toggleDistrictBox('{{ $side }}', {{ $code }})" class="cursor-pointer text-2xs font-semibold text-neutral-600 dark:text-neutral-300 flex items-center gap-1 text-left" aria-expanded="{{ $boxOpen ? 'true' : 'false' }}">
                        <svg class="w-3 h-3 transition-transform {{ $boxOpen ? 'rotate-90' : '' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                        {{ \App\Support\TurkishLocations::province($code)['name'] ?? $code }} ilçeleri{{ $picked !== [] ? ': '.implode(', ', $picked) : ' (tümü)' }}
                    </button>
                    <div class="mt-1.5 flex flex-wrap gap-1.5 {{ $boxOpen ? '' : 'hidden' }}">
                        @foreach($districtNames as $dn)
                            <button type="button" wire:click="toggleDistrict('{{ $side }}', {{ $code }}, @js($dn))" class="px-2 py-0.5 rounded-lg border text-2xs transition-colors {{ in_array($dn, $picked, true) ? 'border-brand-500 bg-brand-500/10 text-brand-600 dark:text-brand-400 font-semibold' : 'border-neutral-200 dark:border-neutral-700 text-neutral-600 dark:text-neutral-300 hover:border-brand-500/50' }}">{{ $dn }}</button>
                        @endforeach
                    </div>
                </div>
            @endif
        @endforeach
        <div class="flex justify-end pt-1">
            <button type="button" @click="open = false" class="btn-primary py-1.5 px-4 text-xs">Tamam</button>
        </div>
    </div>
</div>
