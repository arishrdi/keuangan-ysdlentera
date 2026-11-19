@props([
    'label' => null,
])

@php
    $field = $attributes->get('name') ?? $attributes->wire('model')->value();
    $id = $attributes->get('id') ?? ($field ? 'textarea-'.str_replace('.', '-', $field) : 'textarea-'.uniqid());
    $hasError = $field && $errors->has($field);
@endphp

<fieldset class="fieldset p-0">
    @if ($label)
        <label for="{{ $id }}" class="fieldset-legend pt-0">{{ $label }}</label>
    @endif

    <textarea
        {{ $attributes->merge(['id' => $id, 'rows' => 3])->class(['textarea w-full', 'textarea-error' => $hasError]) }}
        @if ($hasError) aria-invalid="true" @endif
    >{{ $slot }}</textarea>

    @if ($hasError)
        <p class="label text-error">{{ $errors->first($field) }}</p>
    @endif
</fieldset>
