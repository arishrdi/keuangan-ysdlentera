{{--
    Child switcher on the green band. Each pill sets the page's `studentId`.

    @var \Illuminate\Support\Collection<int, \App\Models\Student> $children
--}}
@props([
    'children',
    'active' => null,
])

<div class="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1" role="tablist" aria-label="Pilih anak">
    @foreach ($children as $child)
        @php
            $isActive = $child->id === $active;
            $classroom = $child->currentEnrollment?->classroom?->name;
        @endphp
        <button
            type="button"
            role="tab"
            aria-selected="{{ $isActive ? 'true' : 'false' }}"
            wire:key="child-pill-{{ $child->id }}"
            wire:click="$set('studentId', {{ $child->id }})"
            @class([
                'shrink-0 rounded-full px-4 py-1.5 text-sm font-semibold transition-colors focus-visible:outline-accent',
                'bg-base-100 text-primary' => $isActive,
                'bg-neutral-content/15 text-neutral-content hover:bg-neutral-content/25' => ! $isActive,
            ])
        >
            {{ str($child->name)->before(' ')->toString() ?: $child->name }}@if ($classroom) <span @class(['font-medium', 'text-base-content/60' => $isActive, 'text-neutral-content/70' => ! $isActive])>{{ $classroom }}</span>@endif
        </button>
    @endforeach
</div>
