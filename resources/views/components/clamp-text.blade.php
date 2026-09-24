{{-- Uzun metin: birkaç satıra kısaltılır; sığmıyorsa "Devamı" ile yerinde açılır, tekrar dokununca kapanır.
     Sayfayı kapatan pencere açılmaz, liste yerinden oynamaz. lines: 2 | 3 | 4 --}}
@props(['text' => '', 'lines' => 3])
@php $clamp = ['2' => 'line-clamp-2', '3' => 'line-clamp-3', '4' => 'line-clamp-4'][(string) $lines] ?? 'line-clamp-3'; @endphp
<div wire:ignore x-data="{ open: false, clipped: false }" x-init="$nextTick(() => clipped = $refs.t.scrollHeight > $refs.t.clientHeight + 1)" {{ $attributes->merge(['class' => 'min-w-0']) }}>
    <p x-ref="t" class="whitespace-pre-line break-words {{ $clamp }}" :class="open ? '' : '{{ $clamp }}'">{{ $text }}</p>
    <button type="button" x-show="clipped" x-cloak @click="open = !open" class="mt-0.5 text-[11px] font-semibold text-brand-600 dark:text-brand-400" x-text="open ? 'Daha az' : 'Devamı'"></button>
</div>
