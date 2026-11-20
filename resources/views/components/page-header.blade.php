{{--
    Page title with optional context labels (`meta`, e.g. the active level and academic year),
    a one-sentence description and an actions slot.
--}}
@props([
    'title',
    'description' => null,
    'meta' => [],
])

@php
    /** @var array<int, string|null> $meta */
    $metaItems = array_values(array_filter($meta, fn (?string $item): bool => filled($item)));
@endphp

<div {{ $attributes->class('mb-6 flex flex-wrap items-end justify-between gap-4 lg:mb-8') }}>
    <div class="min-w-0">
        @if ($metaItems !== [])
            <ul class="mb-2 flex flex-wrap gap-1.5" aria-label="Konteks halaman">
                @foreach ($metaItems as $item)
                    <li class="rounded-field bg-base-300/60 px-2 py-0.5 text-xs font-medium text-base-content/75">{{ $item }}</li>
                @endforeach
            </ul>
        @endif

        <h1 class="text-2xl font-bold tracking-tight lg:text-[1.75rem]">{{ $title }}</h1>

        @if ($description)
            <p class="mt-1 text-sm text-base-content/65">{{ $description }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">
            {{ $actions }}
        </div>
    @endisset
</div>
