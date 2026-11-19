<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-base-100">
        <div class="drawer lg:drawer-open">
            <input id="app-sidebar" type="checkbox" class="drawer-toggle" />

            <div class="drawer-content flex min-h-screen flex-col">
                <div class="navbar sticky top-0 z-20 gap-2 border-b border-base-300 bg-base-100/95 px-4 backdrop-blur">
                    <div class="navbar-start gap-2">
                        <label for="app-sidebar" class="btn btn-ghost btn-square drawer-button lg:hidden" aria-label="Buka menu">
                            <x-icon name="bars-2" />
                        </label>
                    </div>

                    <div class="navbar-end gap-2">
                        @if (auth()->user()->isStaff())
                            <x-level-switcher />
                        @endif

                        <x-desktop-user-menu :compact="true" class="lg:hidden" />
                    </div>
                </div>

                {{ $slot }}
            </div>

            <div class="drawer-side z-40">
                <label for="app-sidebar" aria-label="Tutup menu" class="drawer-overlay"></label>

                <aside class="flex min-h-full w-64 flex-col gap-2 border-e border-base-300 bg-base-200 p-3">
                    <div class="flex items-center justify-between">
                        <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                        <label for="app-sidebar" class="btn btn-ghost btn-sm btn-square lg:hidden" aria-label="Tutup menu">
                            <x-icon name="x-mark" />
                        </label>
                    </div>

                    <x-app-menu />

                    <div class="flex-1"></div>

                    <x-desktop-user-menu class="hidden lg:block" />
                </aside>
            </div>
        </div>

        @persist('toast')
            <x-toast />
        @endpersist
    </body>
</html>
