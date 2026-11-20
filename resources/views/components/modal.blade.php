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
    <div class="modal-box {{ $maxWidth }} p-0">
        @if ($title)
            <div class="flex items-center justify-between gap-4 border-b border-base-300 px-6 py-4">
                <h3 class="text-lg font-bold tracking-tight">{{ $title }}</h3>
                <form method="dialog">
                    <button class="btn btn-ghost btn-sm btn-square" aria-label="Tutup">
                        <x-icon name="x-mark" class="size-5" />
                    </button>
                </form>
            </div>
        @endif

        <div class="px-6 pb-6 pt-5">
            {{ $slot }}
        </div>
    </div>

    <form method="dialog" class="modal-backdrop">
        <button>Tutup</button>
    </form>
</dialog>
