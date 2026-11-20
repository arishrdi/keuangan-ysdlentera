{{--
    A button that asks first in the app's confirmation dialog, then calls the Livewire
    `action` (e.g. "delete(5)") on the surrounding component.
--}}
@props([
    'action',
    'message',
    'title' => 'Konfirmasi',
    'confirmLabel' => 'Lanjutkan',
    'danger' => false,
])

<button
    type="button"
    x-data
    x-on:click="$dispatch('confirm-action', {
        title: @js($title),
        message: @js($message),
        confirmLabel: @js($confirmLabel),
        danger: @js((bool) $danger),
        run: () => $wire.{{ $action }},
    })"
    {{ $attributes }}
>
    {{ $slot }}
</button>
