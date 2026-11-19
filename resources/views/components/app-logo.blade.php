@props([
    'sidebar' => false,
])

<a {{ $attributes->class(['flex items-center gap-2 font-semibold', 'px-2' => $sidebar]) }}>
    <span class="flex aspect-square size-8 items-center justify-center rounded-field bg-neutral text-neutral-content">
        <x-app-logo-icon class="size-5 fill-current" />
    </span>
    <span class="truncate text-sm">{{ config('app.name', 'Laravel') }}</span>
</a>
