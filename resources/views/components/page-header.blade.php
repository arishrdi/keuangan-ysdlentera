@props([
    'title',
    'description' => null,
])

<div {{ $attributes->class('mb-6 flex flex-wrap items-end justify-between gap-4') }}>
    <div>
        <h1 class="text-2xl font-semibold">{{ $title }}</h1>
        @if ($description)
            <p class="text-sm text-base-content/70">{{ $description }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">
            {{ $actions }}
        </div>
    @endisset
</div>
