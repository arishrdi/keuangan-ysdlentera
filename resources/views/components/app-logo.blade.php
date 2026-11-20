@props([
    'sidebar' => false,
])

<a {{ $attributes->class(['flex items-center gap-2 rounded-field', 'px-1' => $sidebar]) }}>
    {{-- On the green sidebar the reversed logo (white wordmark, yellow crescent) sits on the chrome directly. --}}
    <img src="{{ asset($sidebar ? 'images/logo-reverse.png' : 'images/logo.png') }}" alt="{{ config('app.name') }}" @class(['w-auto', 'h-10' => $sidebar, 'h-10 rounded-field' => ! $sidebar]) />
</a>
