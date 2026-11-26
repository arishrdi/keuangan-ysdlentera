{{--
    Green header band for guardian screens. It continues the green topbar on phones; the next
    block overlaps it by wrapping itself in `<x-guardian.overlap>`.
--}}
@props([
    'title',
    'description' => null,
])

<header {{ $attributes->class('-mx-4 -mt-6 bg-neutral px-4 pb-16 pt-2 text-neutral-content sm:-mx-6 sm:px-6 lg:mx-0 lg:mt-0 lg:rounded-box lg:px-8 lg:pt-8') }}>
    <h1 class="text-2xl font-bold tracking-tight">{{ $title }}</h1>

    @if ($description)
        <p class="mt-1 max-w-xl text-sm text-neutral-content/75">{{ $description }}</p>
    @endif

    @if ($slot->isNotEmpty())
        <div class="mt-4">{{ $slot }}</div>
    @endif
</header>
