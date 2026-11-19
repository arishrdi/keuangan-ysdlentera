<x-layouts::auth title="Masuk">
    <div class="flex flex-col gap-6">
        <x-auth-header title="Masuk ke akun Anda" description="Gunakan username, email, atau nomor HP beserta password" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6">
            @csrf

            <x-input
                name="login"
                label="Username / Email / No. HP"
                :value="old('login')"
                type="text"
                required
                autofocus
                autocomplete="username"
                placeholder="contoh: 081234567890"
            />

            <div class="relative">
                <x-input
                    name="password"
                    label="Password"
                    type="password"
                    required
                    autocomplete="current-password"
                    placeholder="Password"
                    viewable
                />

                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}" class="link absolute end-0 top-0 text-sm" wire:navigate>
                        Lupa password?
                    </a>
                @endif
            </div>

            <label class="label cursor-pointer gap-2 text-base-content">
                <input type="checkbox" name="remember" class="checkbox checkbox-sm" @checked(old('remember')) />
                <span class="text-sm">Ingat saya</span>
            </label>

            <button type="submit" class="btn btn-primary w-full" data-test="login-button">
                Masuk
            </button>
        </form>
    </div>
</x-layouts::auth>
