@props([
    'sidebar' => false,
])

<a {{ $attributes->class(['flex items-center gap-2', 'px-2' => $sidebar]) }}>
    <img src="{{ asset('images/logo.png') }}" alt="{{ config('app.name') }}" class="h-9 w-auto rounded-field" />
</a>
