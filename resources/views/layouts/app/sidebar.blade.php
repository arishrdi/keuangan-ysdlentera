<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-base-200 text-base-content">
        <div class="drawer lg:drawer-open">
            <input id="app-sidebar" type="checkbox" class="drawer-toggle" />

            <div class="drawer-content flex min-h-screen flex-col">
                @php
                    $isGuardian = auth()->user()->hasRole(\App\Enums\Role::Guardian);
                @endphp
                <div @class([
                    'navbar sticky top-0 z-20 min-h-16 gap-2 border-b px-4 backdrop-blur lg:px-8',
                    'border-base-300 bg-base-200/90' => ! $isGuardian,
                    'guardian-bar border-transparent bg-neutral text-neutral-content lg:border-base-300 lg:bg-base-200/90 lg:text-base-content' => $isGuardian,
                ])>
                    <div class="navbar-start min-w-0 gap-2">
                        <label for="app-sidebar" class="btn btn-ghost btn-square drawer-button lg:hidden" aria-label="Buka menu">
                            <x-icon name="bars-2" />
                        </label>

                        @if (filled($title ?? null) && ! $isGuardian)
                            <span class="truncate text-sm font-semibold lg:hidden">{{ $title }}</span>
                        @endif

                        @if ($isGuardian)
                            <img src="{{ asset('images/logo-reverse.png') }}" alt="{{ config('app.name') }}" class="h-8 w-auto lg:hidden" />
                        @endif
                    </div>

                    <div class="navbar-end gap-2">
                        @if (auth()->user()->isStaff())
                            <x-level-switcher />
                        @endif

                        <livewire:notification-bell />

                        <x-desktop-user-menu :compact="true" class="lg:hidden" />
                    </div>
                </div>

                {{ $slot }}

                @if ($isGuardian)
                    <div class="h-16 lg:hidden"></div>
                    <x-guardian-dock />
                @endif
            </div>

            <div class="drawer-side z-40">
                <label for="app-sidebar" aria-label="Tutup menu" class="drawer-overlay"></label>

                <aside class="flex min-h-full w-64 flex-col bg-neutral text-neutral-content">
                    <div class="flex items-center justify-between gap-2 px-4 pb-2 pt-4">
                        <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                        <label for="app-sidebar" class="sidebar-link size-9 justify-center px-0 lg:hidden" aria-label="Tutup menu">
                            <x-icon name="x-mark" />
                        </label>
                    </div>

                    <nav class="flex-1 px-3 pb-4" aria-label="Menu utama">
                        <x-app-menu />
                    </nav>

                    <div class="sticky bottom-0 hidden border-t border-(--sidebar-rule) bg-neutral p-3 lg:block">
                        <x-desktop-user-menu />
                    </div>
                </aside>
            </div>
        </div>

        <x-confirm-dialog />

        @persist('toast')
            <x-toast />
        @endpersist
    </body>
</html>
