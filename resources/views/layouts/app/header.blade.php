<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen">
        <div class="drawer">
            <input id="app-header-drawer" type="checkbox" class="drawer-toggle" />

            <div class="drawer-content flex min-h-screen flex-col">
                <div class="navbar gap-2 border-b border-base-300 bg-base-200 px-4">
                    <div class="navbar-start gap-2">
                        <label for="app-header-drawer" class="btn btn-ghost btn-square drawer-button lg:hidden" aria-label="{{ __('Open menu') }}">
                            <x-icon name="bars-2" />
                        </label>

                        <x-app-logo href="{{ route('dashboard') }}" wire:navigate />

                        <ul class="menu menu-horizontal ms-4 max-lg:hidden">
                            <li>
                                <a href="{{ route('dashboard') }}" @class(['menu-active' => request()->routeIs('dashboard')]) wire:navigate>
                                    <x-icon name="layout-grid" />
                                    {{ __('Dashboard') }}
                                </a>
                            </li>
                        </ul>
                    </div>

                    <div class="navbar-end gap-1">
                        <div class="tooltip tooltip-bottom" data-tip="{{ __('Search') }}">
                            <a href="#" class="btn btn-ghost btn-square" aria-label="{{ __('Search') }}">
                                <x-icon name="magnifying-glass" />
                            </a>
                        </div>
                        <div class="tooltip tooltip-bottom max-lg:hidden" data-tip="{{ __('Repository') }}">
                            <a href="https://github.com/laravel/livewire-starter-kit" target="_blank" class="btn btn-ghost btn-square" aria-label="{{ __('Repository') }}">
                                <x-icon name="folder-git-2" />
                            </a>
                        </div>
                        <div class="tooltip tooltip-bottom max-lg:hidden" data-tip="{{ __('Documentation') }}">
                            <a href="https://laravel.com/docs/starter-kits#livewire" target="_blank" class="btn btn-ghost btn-square" aria-label="{{ __('Documentation') }}">
                                <x-icon name="book-open-text" />
                            </a>
                        </div>

                        <x-desktop-user-menu :compact="true" />
                    </div>
                </div>

                {{ $slot }}
            </div>

            <!-- Mobile Menu -->
            <div class="drawer-side z-40 lg:hidden">
                <label for="app-header-drawer" aria-label="{{ __('Close menu') }}" class="drawer-overlay"></label>

                <aside class="flex min-h-full w-64 flex-col gap-2 border-e border-base-300 bg-base-200 p-3">
                    <div class="flex items-center justify-between">
                        <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                        <label for="app-header-drawer" class="btn btn-ghost btn-sm btn-square" aria-label="{{ __('Close menu') }}">
                            <x-icon name="x-mark" />
                        </label>
                    </div>

                    <ul class="menu w-full px-0">
                        <li class="menu-title">{{ __('Platform') }}</li>
                        <li>
                            <a href="{{ route('dashboard') }}" @class(['menu-active' => request()->routeIs('dashboard')]) wire:navigate>
                                <x-icon name="layout-grid" />
                                {{ __('Dashboard') }}
                            </a>
                        </li>
                    </ul>

                    <div class="flex-1"></div>

                    <ul class="menu w-full px-0">
                        <li>
                            <a href="https://github.com/laravel/livewire-starter-kit" target="_blank">
                                <x-icon name="folder-git-2" />
                                {{ __('Repository') }}
                            </a>
                        </li>
                        <li>
                            <a href="https://laravel.com/docs/starter-kits#livewire" target="_blank">
                                <x-icon name="book-open-text" />
                                {{ __('Documentation') }}
                            </a>
                        </li>
                    </ul>
                </aside>
            </div>
        </div>

        @persist('toast')
            <x-toast />
        @endpersist
    </body>
</html>
