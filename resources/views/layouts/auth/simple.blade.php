<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-base-200 text-base-content antialiased">
        <div class="grid min-h-svh lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
            <aside class="relative hidden overflow-hidden bg-neutral p-10 text-neutral-content lg:flex lg:flex-col xl:p-14">
                <x-app-logo-icon class="pointer-events-none absolute -bottom-32 -end-40 size-[28rem] text-accent opacity-10" aria-hidden="true" />

                <a href="{{ route('home') }}" class="relative w-fit rounded-field" wire:navigate>
                    <img src="{{ asset('images/logo-reverse.png') }}" alt="{{ config('app.name') }}" class="h-16 w-auto" />
                </a>

                <div class="relative mt-auto max-w-sm">
                    <p class="text-3xl font-bold leading-tight tracking-tight">Sistem Keuangan Sekolah</p>
                    <p class="mt-3 text-neutral-content/75">Tagihan, pembayaran, kas, tabungan, dan anggaran PAUD, SD, dan SMP Lentera.</p>
                </div>
            </aside>

            <main class="flex flex-col items-center justify-center gap-8 p-6 md:p-10">
                <a href="{{ route('home') }}" class="flex flex-col items-center gap-2 rounded-field lg:hidden" wire:navigate>
                    <img src="{{ asset('images/logo.png') }}" alt="{{ config('app.name') }}" class="w-56 rounded-box" />
                    <span class="text-sm font-medium text-base-content/70">Sistem Keuangan Sekolah</span>
                </a>

                <div class="panel w-full max-w-sm p-6 sm:p-8">
                    <div class="flex flex-col gap-6">
                        {{ $slot }}
                    </div>
                </div>
            </main>
        </div>

        @persist('toast')
            <x-toast />
        @endpersist
    </body>
</html>
