@props([
    'sidebar' => false,
])

<a {{ $attributes->class(['flex items-center gap-2 rounded-field', 'px-1' => $sidebar]) }}>
    <img src="{{ asset('images/logo.png') }}" alt="{{ config('app.name') }}" class="h-10 w-auto rounded-field" />
</a>
