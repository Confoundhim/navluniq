{{-- Uzun metin: birkaç satıra kısaltılır; sığmıyorsa "Devamı" ile yerinde açılır, tekrar dokununca kapanır.
     Sayfayı kapatan pencere açılmaz, liste yerinden oynamaz. lines: 2 | 3 | 4
     Sınıf bağlama nesne biçimindedir ({ 'line-clamp-3': !open }): dize biçimi baştan var olan sınıfı kaldırmaz. --}}
@props(['text' => '', 'lines' => 3])
@php
    $clamp = ['2' => 'line-clamp-2', '3' => 'line-clamp-3', '4' => 'line-clamp-4'][(string) $lines] ?? 'line-clamp-3';
    // Boş satırlar tek satıra iner: mesajlardaki art arda satır sonları kısaltma satırlarını yemesin.
    $text = trim((string) preg_replace('/[ \t]*\R(?:[ \t]*\R)+/u', "\n", (string) $text));
@endphp
<div wire:ignore x-data="{ open: false, clipped: false, measure: null }" x-init="measure = () => clipped = open || $refs.t.scrollHeight > $refs.t.clientHeight + 1; $nextTick(measure); document.fonts && document.fonts.ready.then(measure)" {{ $attributes->merge(['class' => 'min-w-0']) }}>
    <p x-ref="t" class="whitespace-pre-line break-words {{ $clamp }}" :class="{ '{{ $clamp }}': !open }">{{ $text }}</p>
    <button type="button" x-show="clipped" x-cloak @click="open = !open" class="mt-0.5 text-[11px] font-semibold text-brand-600 dark:text-brand-400" x-text="open ? 'Daha az' : 'Devamı'"></button>
</div>
