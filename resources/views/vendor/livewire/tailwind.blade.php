{{--
    Livewire pagination in the Lentera style: a light, bordered page row with the current
    page in green, and a plain previous/next pair on phones. Overrides livewire::tailwind.
--}}
@php
    if (! isset($scrollTo)) {
        $scrollTo = 'body';
    }

    $scrollIntoViewJsSnippet = ($scrollTo !== false)
        ? <<<JS
           (\$el.closest('{$scrollTo}') || document.querySelector('{$scrollTo}')).scrollIntoView()
        JS
        : '';

    $pageName = $paginator->getPageName();
    $pageButton = 'inline-flex h-9 min-w-9 items-center justify-center rounded-field px-3 text-sm font-medium transition-colors';
@endphp

<div>
    @if ($paginator->hasPages())
        <nav role="navigation" aria-label="Navigasi halaman" class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-base-content/65">
                Menampilkan {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} dari {{ $paginator->total() }}
            </p>

            <div class="flex items-center gap-1 rounded-box border border-base-300 bg-base-100 p-1">
                @if ($paginator->onFirstPage())
                    <span class="{{ $pageButton }} text-base-content/30" aria-disabled="true" aria-label="Sebelumnya">
                        <x-icon name="chevron-down" class="size-4 rotate-90" />
                    </span>
                @else
                    <button type="button" wire:click="previousPage('{{ $pageName }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled" class="{{ $pageButton }} text-base-content/70 hover:bg-base-200 hover:text-base-content" aria-label="Sebelumnya">
                        <x-icon name="chevron-down" class="size-4 rotate-90" />
                    </button>
                @endif

                <span class="px-2 text-sm text-base-content/70 sm:hidden">{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>

                @foreach ($elements as $element)
                    @if (is_string($element))
                        <span class="{{ $pageButton }} text-base-content/40 max-sm:hidden" aria-disabled="true">{{ $element }}</span>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            <span wire:key="paginator-{{ $pageName }}-page{{ $page }}" class="max-sm:hidden">
                                @if ($page == $paginator->currentPage())
                                    <span class="{{ $pageButton }} bg-primary font-semibold text-primary-content" aria-current="page">{{ $page }}</span>
                                @else
                                    <button type="button" wire:click="gotoPage({{ $page }}, '{{ $pageName }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" class="{{ $pageButton }} text-base-content/70 hover:bg-base-200 hover:text-base-content" aria-label="Ke halaman {{ $page }}">{{ $page }}</button>
                                @endif
                            </span>
                        @endforeach
                    @endif
                @endforeach

                @if ($paginator->hasMorePages())
                    <button type="button" wire:click="nextPage('{{ $pageName }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled" class="{{ $pageButton }} text-base-content/70 hover:bg-base-200 hover:text-base-content" aria-label="Berikutnya">
                        <x-icon name="chevron-down" class="size-4 -rotate-90" />
                    </button>
                @else
                    <span class="{{ $pageButton }} text-base-content/30" aria-disabled="true" aria-label="Berikutnya">
                        <x-icon name="chevron-down" class="size-4 -rotate-90" />
                    </span>
                @endif
            </div>
        </nav>
    @endif
</div>
