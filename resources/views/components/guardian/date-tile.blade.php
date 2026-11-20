{{-- A small calendar tile: the day over the short month, or a dash when there is no date. --}}
@props([
    'date' => null,
])

<div {{ $attributes->class('flex size-12 shrink-0 flex-col items-center justify-center rounded-field bg-primary/10 leading-none text-primary') }}>
    @if ($date)
        <span class="text-base font-bold">{{ $date->format('j') }}</span>
        <span class="mt-0.5 text-[0.6875rem] font-semibold">{{ $date->translatedFormat('M') }}</span>
    @else
        <span class="text-base font-bold">-</span>
    @endif
</div>
