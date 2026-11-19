@props([
    'compact' => false,
])

@php
    $user = auth()->user();
    $logoutFormId = 'logout-form-'.uniqid();
@endphp

<details
    x-data
    x-on:click.outside="$el.removeAttribute('open')"
    x-on:keydown.escape="$el.removeAttribute('open')"
    {{ $attributes->class(['dropdown', 'dropdown-end' => $compact, 'dropdown-top w-full' => ! $compact]) }}
>
    <summary
        @class([
            'btn btn-ghost',
            'px-2' => $compact,
            'w-full justify-start px-2' => ! $compact,
        ])
        data-test="sidebar-menu-button"
    >
        <div class="avatar avatar-placeholder">
            <div class="w-8 rounded-field bg-neutral text-neutral-content">
                <span class="text-xs">{{ $user->initials() }}</span>
            </div>
        </div>

        @if ($compact)
            <x-icon name="chevrons-up-down" class="size-4 opacity-60" />
        @else
            <span class="flex-1 truncate text-start text-sm">{{ $user->name }}</span>
            <x-icon name="chevrons-up-down" class="size-4 opacity-60" />
        @endif
    </summary>

    <div class="dropdown-content z-30 mb-1 mt-1 w-64 rounded-box bg-base-100 p-2 shadow-lg ring-1 ring-base-300">
        <div class="flex items-center gap-2 px-2 py-1.5">
            <div class="avatar avatar-placeholder">
                <div class="w-8 rounded-field bg-neutral text-neutral-content">
                    <span class="text-xs">{{ $user->initials() }}</span>
                </div>
            </div>
            <div class="grid flex-1 text-sm leading-tight">
                <span class="truncate font-medium">{{ $user->name }}</span>
                <span class="truncate text-base-content/60">{{ $user->role->label() }} · {{ $user->email ?? $user->phone ?? $user->username }}</span>
            </div>
        </div>

        <div class="divider my-1"></div>

        <ul class="menu w-full p-0">
            <li>
                <a href="{{ route('profile.edit') }}" wire:navigate>
                    <x-icon name="cog" class="size-4" />
                    Pengaturan Akun
                </a>
            </li>
            <li>
                <button type="submit" form="{{ $logoutFormId }}" data-test="logout-button">
                    <x-icon name="arrow-right-start-on-rectangle" class="size-4" />
                    Keluar
                </button>
            </li>
        </ul>

        <form id="{{ $logoutFormId }}" method="POST" action="{{ route('logout') }}" class="hidden">
            @csrf
        </form>
    </div>
</details>
