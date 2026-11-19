<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-base-200 antialiased">
        <div class="flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="flex w-full max-w-sm flex-col gap-6">
                <a href="{{ route('home') }}" class="flex flex-col items-center gap-2" wire:navigate>
                    <img src="{{ asset('images/logo.png') }}" alt="{{ config('app.name') }}" class="w-64 rounded-box" />
                    <span class="text-sm font-medium text-base-content/70">Sistem Keuangan Sekolah</span>
                </a>
                <div class="card bg-base-100 shadow-sm">
                    <div class="card-body gap-6">
                        {{ $slot }}
                    </div>
                </div>
            </div>
        </div>

        @persist('toast')
            <x-toast />
        @endpersist
    </body>
</html>
