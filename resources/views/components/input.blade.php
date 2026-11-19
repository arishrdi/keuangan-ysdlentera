@props([
    'label' => null,
    'type' => 'text',
    'viewable' => false,
])

@php
    $field = $attributes->get('name') ?? $attributes->wire('model')->value();
    $id = $attributes->get('id') ?? ($field ? 'input-'.$field : 'input-'.uniqid());
    $hasError = $field && $errors->has($field);
    $isViewable = $viewable && $type === 'password';
@endphp

<fieldset class="fieldset p-0">
    @if ($label)
        <label for="{{ $id }}" class="fieldset-legend pt-0">{{ $label }}</label>
    @endif

    @if ($isViewable)
        <label class="input w-full @if ($hasError) input-error @endif" x-data="{ visible: false }">
            <input
                {{ $attributes->merge(['id' => $id]) }}
                type="{{ $type }}"
                x-bind:type="visible ? 'text' : 'password'"
                @if ($hasError) aria-invalid="true" @endif
            />
            <button
                type="button"
                class="btn btn-ghost btn-xs btn-square -me-1"
                x-on:click="visible = ! visible"
                x-bind:aria-label="visible ? @js(__('Hide password')) : @js(__('Show password'))"
            >
                <x-icon name="eye" class="size-4" x-show="! visible" />
                <x-icon name="eye-slash" class="size-4" x-show="visible" x-cloak />
            </button>
        </label>
    @else
        <input
            {{ $attributes->merge(['id' => $id])->class(['input w-full', 'input-error' => $hasError]) }}
            type="{{ $type }}"
            @if ($hasError) aria-invalid="true" @endif
        />
    @endif

    @if ($hasError)
        <p class="label text-error">{{ $errors->first($field) }}</p>
    @endif
</fieldset>
