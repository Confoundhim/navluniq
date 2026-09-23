{{--
    Sayfa numaraları (tüm Livewire listeleri): "‹ Önceki  1 … 4 [5] 6 … 12  Sonraki ›" ve "Sayfa 5 / 12".
    Büyük dokunma alanları, telefonda tek satır; sayfa değişince yalnız liste yenilenir (sayfa yenilenmez).
--}}
@php
    $name = $paginator->getPageName();
    $current = $paginator->currentPage();
    $last = $paginator->lastPage();
    $pages = [];
    if ($last <= 7) {
        $pages = range(1, $last);
    } else {
        $pages = array_unique(array_filter([1, $current - 1, $current, $current + 1, $last], fn ($p) => $p >= 1 && $p <= $last));
        sort($pages);
    }
    $btn = 'inline-flex h-10 min-w-[2.5rem] items-center justify-center rounded-xl border px-2 text-sm font-bold tabular-nums transition-colors';
    $idle = 'border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 text-neutral-700 dark:text-neutral-200 hover:border-brand-500 hover:text-brand-500';
    $active = 'border-brand-500 bg-brand-500 text-white';
    $off = 'border-neutral-200 dark:border-neutral-800 bg-neutral-50 dark:bg-neutral-950 text-neutral-300 dark:text-neutral-600 cursor-not-allowed';
@endphp
<div>
    @if ($paginator->hasPages())
        <nav role="navigation" aria-label="Sayfalar" class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div class="flex flex-wrap items-center gap-1.5">
                @if ($paginator->onFirstPage())
                    <span class="{{ $btn }} {{ $off }} px-3" aria-disabled="true">‹ Önceki</span>
                @else
                    <button type="button" wire:click="previousPage('{{ $name }}')" wire:loading.attr="disabled" class="{{ $btn }} {{ $idle }} px-3" aria-label="Önceki sayfa">‹ Önceki</button>
                @endif

                @php $prev = 0; @endphp
                @foreach ($pages as $page)
                    @if ($page - $prev > 1)
                        <span class="inline-flex h-10 w-6 items-center justify-center text-neutral-400" aria-hidden="true">…</span>
                    @endif
                    <span wire:key="paginator-{{ $name }}-page{{ $page }}">
                        @if ($page == $current)
                            <span class="{{ $btn }} {{ $active }}" aria-current="page">{{ $page }}</span>
                        @else
                            <button type="button" wire:click="gotoPage({{ $page }}, '{{ $name }}')" wire:loading.attr="disabled" class="{{ $btn }} {{ $idle }}" aria-label="{{ $page }}. sayfa">{{ $page }}</button>
                        @endif
                    </span>
                    @php $prev = $page; @endphp
                @endforeach

                @if ($paginator->hasMorePages())
                    <button type="button" wire:click="nextPage('{{ $name }}')" wire:loading.attr="disabled" class="{{ $btn }} {{ $idle }} px-3" aria-label="Sonraki sayfa">Sonraki ›</button>
                @else
                    <span class="{{ $btn }} {{ $off }} px-3" aria-disabled="true">Sonraki ›</span>
                @endif
            </div>
            <div class="text-[11px] text-neutral-500 tabular-nums">Sayfa {{ $current }} / {{ $last }} · toplam {{ number_format($paginator->total(), 0, ',', '.') }} kayıt</div>
        </nav>
    @elseif ($paginator->total() > 0)
        {{-- Tek sayfa: yine de kaç kayıt olduğu (filtreye uyan sayı) görünsün --}}
        <div class="text-[11px] text-neutral-500 tabular-nums">Toplam {{ number_format($paginator->total(), 0, ',', '.') }} kayıt</div>
    @endif
</div>
