@props([
    'label' => null,
    'placeholder' => null,
])

@php
    $field = $attributes->get('name') ?? $attributes->wire('model')->value();
    $id = $attributes->get('id') ?? ($field ? 'select-'.str_replace('.', '-', $field) : 'select-'.uniqid());
    $hasError = $field && $errors->has($field);
@endphp

<fieldset class="fieldset p-0">
    @if ($label)
        <label for="{{ $id }}" class="fieldset-legend pt-0">{{ $label }}</label>
    @endif

    <select
        {{ $attributes->merge(['id' => $id])->class(['select w-full', 'select-error' => $hasError]) }}
        @if ($hasError) aria-invalid="true" @endif
    >
        @if ($placeholder !== null)
            <option value="">{{ $placeholder }}</option>
        @endif

        {{ $slot }}
    </select>

    @if ($hasError)
        <p class="label text-error">{{ $errors->first($field) }}</p>
    @endif
</fieldset>
