<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen">
        <div class="drawer lg:drawer-open">
            <input id="app-sidebar" type="checkbox" class="drawer-toggle" />

            <div class="drawer-content flex min-h-screen flex-col">
                <!-- Mobile User Menu -->
                <div class="navbar border-b border-base-300 bg-base-200 lg:hidden">
                    <div class="navbar-start">
                        <label for="app-sidebar" class="btn btn-ghost btn-square drawer-button" aria-label="{{ __('Open sidebar') }}">
                            <x-icon name="bars-2" />
                        </label>
                    </div>
                    <div class="navbar-end">
                        <x-desktop-user-menu :compact="true" />
                    </div>
                </div>

                {{ $slot }}
            </div>

            <div class="drawer-side z-40">
                <label for="app-sidebar" aria-label="{{ __('Close sidebar') }}" class="drawer-overlay"></label>

                <aside class="flex min-h-full w-64 flex-col gap-2 border-e border-base-300 bg-base-200 p-3">
                    <div class="flex items-center justify-between">
                        <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                        <label for="app-sidebar" class="btn btn-ghost btn-sm btn-square lg:hidden" aria-label="{{ __('Close sidebar') }}">
                            <x-icon name="x-mark" />
                        </label>
                    </div>

                    <ul class="menu w-full px-0">
                        <li class="menu-title">{{ __('Platform') }}</li>
                        <li>
                            <a href="{{ route('dashboard') }}" @class(['menu-active' => request()->routeIs('dashboard')]) wire:navigate>
                                <x-icon name="home" />
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

                    <x-desktop-user-menu class="hidden lg:block" />
                </aside>
            </div>
        </div>

        @persist('toast')
            <x-toast />
        @endpersist
    </body>
</html>
