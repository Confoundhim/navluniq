@php
    // simplePaginate için: toplam sayfa bilinmez, yalnız Önceki / Sonraki.
    if (! isset($scrollTo)) {
        $scrollTo = 'body';
    }
    $scrollIntoViewJsSnippet = ($scrollTo !== false)
        ? "(\$el.closest('{$scrollTo}') || document.querySelector('{$scrollTo}')).scrollIntoView()"
        : '';
@endphp
<div>
    @if ($paginator->hasPages())
        <nav role="navigation" aria-label="Sayfalama" class="flex items-center justify-between gap-3 text-[11px]">
            @if ($paginator->onFirstPage())
                <span class="px-3 py-1.5 rounded-lg border border-neutral-200 dark:border-neutral-800 text-neutral-400 cursor-default">‹ Önceki</span>
            @else
                <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled" class="px-3 py-1.5 rounded-lg border border-neutral-200 dark:border-neutral-800 text-neutral-700 dark:text-neutral-200 hover:bg-neutral-100 dark:hover:bg-neutral-800">‹ Önceki</button>
            @endif
            <span class="text-neutral-500 tabular-nums">Sayfa {{ $paginator->currentPage() }}</span>
            @if ($paginator->hasMorePages())
                <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled" class="px-3 py-1.5 rounded-lg border border-neutral-200 dark:border-neutral-800 text-neutral-700 dark:text-neutral-200 hover:bg-neutral-100 dark:hover:bg-neutral-800">Sonraki ›</button>
            @else
                <span class="px-3 py-1.5 rounded-lg border border-neutral-200 dark:border-neutral-800 text-neutral-400 cursor-default">Sonraki ›</span>
            @endif
        </nav>
    @endif
</div>
