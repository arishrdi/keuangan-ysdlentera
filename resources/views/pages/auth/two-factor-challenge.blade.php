<x-layouts::auth title="Verifikasi dua langkah">
    <div class="flex flex-col gap-6" x-data="{ useRecovery: {{ $errors->has('recovery_code') ? 'true' : 'false' }} }">
        <x-auth-header title="Verifikasi dua langkah" description="Masukkan kode 6 digit dari aplikasi autentikator Anda" />

        <form method="POST" action="{{ route('two-factor.login.store') }}" class="flex flex-col gap-6">
            @csrf

            <div x-show="! useRecovery">
                <x-input name="code" label="Kode autentikasi" inputmode="numeric" autocomplete="one-time-code" autofocus x-bind:disabled="useRecovery" />
            </div>

            <div x-show="useRecovery" x-cloak>
                <x-input name="recovery_code" label="Kode pemulihan" autocomplete="off" x-bind:disabled="! useRecovery" />
            </div>

            <button type="submit" class="btn btn-primary w-full">Masuk</button>

            <button type="button" class="link text-center text-sm" x-on:click="useRecovery = ! useRecovery">
                <span x-show="! useRecovery">Gunakan kode pemulihan</span>
                <span x-show="useRecovery" x-cloak>Gunakan kode dari aplikasi</span>
            </button>
        </form>
    </div>
</x-layouts::auth>
