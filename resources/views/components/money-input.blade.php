{{--
    Rupiah amount field. The user sees "Rp" and thousands separators (200.000) while the
    bound Livewire property receives a plain integer (or null when empty).

    Usage: <x-money-input wire:model="amount" label="Nominal" />
           <x-money-input wire:model.live.debounce.500ms="allocations.5" size="sm" aria-label="..." />
--}}
@props([
    'label' => null,
    'size' => null,
])

@php
    $model = $attributes->wire('model');
    $field = $model->value();
    $isLive = $model->hasModifier('live');
    $id = $attributes->get('id') ?? 'money-'.str_replace('.', '-', $field);
    $hasError = $field && $errors->has($field);
@endphp

<fieldset {{ $attributes->only('class')->class('fieldset p-0') }}>
    @if ($label)
        <label for="{{ $id }}" class="fieldset-legend pt-0">{{ $label }}</label>
    @endif

    <label
        @class([
            'input w-full',
            'input-sm' => $size === 'sm',
            'input-error' => $hasError,
        ])
        x-data="{
            field: @js($field),
            live: @js($isLive),
            display: '',
            timer: null,
            format(value) {
                const digits = String(value ?? '').replace(/\D/g, '');

                return digits === '' ? '' : Number(digits).toLocaleString('id-ID');
            },
            init() {
                this.display = this.format($wire.get(this.field));
                $wire.$watch(this.field, (value) => {
                    const formatted = this.format(value);

                    if (formatted !== this.display) {
                        this.display = formatted;
                    }
                });
            },
            update(event) {
                const digits = event.target.value.replace(/\D/g, '').replace(/^0+(?=\d)/, '');
                const value = digits === '' ? null : Number(digits);

                this.display = this.format(digits);
                event.target.value = this.display;

                clearTimeout(this.timer);
                if (this.live) {
                    this.timer = setTimeout(() => $wire.set(this.field, value), 400);
                } else {
                    $wire.set(this.field, value, false);
                }
            },
        }"
    >
        <span class="text-base-content/60">Rp</span>
        <input
            {{ $attributes->whereDoesntStartWith('wire:model')->except(['id', 'type', 'min', 'max', 'step', 'class'])->class('tabular-nums') }}
            id="{{ $id }}"
            type="text"
            inputmode="numeric"
            autocomplete="off"
            wire:ignore
            x-bind:value="display"
            x-on:input="update($event)"
            @if ($hasError) aria-invalid="true" @endif
        />
    </label>

    @if ($hasError)
        <p class="label text-error">{{ $errors->first($field) }}</p>
    @endif
</fieldset>
