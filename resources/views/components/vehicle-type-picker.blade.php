@props(['model', 'selected' => null, 'columns' => 'grid-cols-2 sm:grid-cols-5'])
{{-- Görsel araç tipi seçici: ana sayfadaki araç kartlarıyla aynı ikon dili. wire:model ile bağlanır. --}}
<div class="grid {{ $columns }} gap-2" role="radiogroup">
    @foreach(\App\Support\VehicleTypes::FORM_ORDER as $key)
        @php $meta = \App\Support\VehicleTypes::TYPES[$key]; @endphp
        <label class="cursor-pointer">
            <input type="radio" wire:model="{{ $model }}" value="{{ $key }}" class="peer sr-only">
            <span class="flex flex-col items-center justify-center gap-1.5 rounded-2xl border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 p-3 text-center transition-all hover:border-brand-500/50 peer-checked:border-brand-500 peer-checked:bg-brand-500/10 peer-checked:shadow-md peer-checked:shadow-brand-500/10 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500/40">
                <svg class="w-8 h-8 text-neutral-600 dark:text-neutral-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">{!! \App\Support\VehicleTypes::iconPath($key) !!}</svg>
                <span class="text-[11px] font-semibold text-neutral-800 dark:text-neutral-100 leading-tight">{{ $meta['label'] }}</span>
                <span class="text-[10px] text-neutral-400 tabular-nums">{{ number_format($meta['capacity_kg'] / 1000, $meta['capacity_kg'] % 1000 ? 1 : 0, ',', '.') }} ton</span>
            </span>
        </label>
    @endforeach
</div>
