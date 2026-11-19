{{--
    Dialog opened with $dispatch('open-modal', 'name') from Alpine or $this->dispatch('open-modal', name: 'name')
    from Livewire, and closed with the matching 'close-modal' event.
--}}
@props([
    'name',
    'title' => null,
    'maxWidth' => 'max-w-lg',
])

<dialog
    id="modal-{{ $name }}"
    class="modal"
    wire:ignore.self
    x-data="{ targets: (detail) => (typeof detail === 'string' ? detail : detail?.name) === @js($name) }"
    x-on:open-modal.window="if (targets($event.detail)) { $el.showModal(); $nextTick(() => $el.querySelector('input:not([type=hidden]), select, textarea')?.focus()) }"
    x-on:close-modal.window="if (targets($event.detail)) { $el.close() }"
    {{ $attributes }}
>
    <div class="modal-box {{ $maxWidth }}">
        @if ($title)
            <h3 class="mb-4 text-lg font-semibold">{{ $title }}</h3>
        @endif

        {{ $slot }}
    </div>

    <form method="dialog" class="modal-backdrop">
        <button>Tutup</button>
    </form>
</dialog>
