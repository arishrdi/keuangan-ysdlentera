<?php

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Optional two-factor authentication for staff accounts.
 */
new class extends Component {
    public string $code = '';

    public bool $showRecoveryCodes = false;

    public function mount(): void
    {
        abort_unless(Auth::user()->isStaff(), 403);
    }

    #[Computed]
    public function isEnabled(): bool
    {
        return Auth::user()->hasEnabledTwoFactorAuthentication();
    }

    #[Computed]
    public function isConfirming(): bool
    {
        return Auth::user()->two_factor_secret !== null && ! $this->isEnabled;
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function recoveryCodes(): array
    {
        return Auth::user()->two_factor_recovery_codes !== null ? Auth::user()->recoveryCodes() : [];
    }

    public function enable(EnableTwoFactorAuthentication $enable): void
    {
        abort_unless(Auth::user()->isStaff(), 403);

        $enable(Auth::user());

        $this->reset('code');
        $this->refreshState();
    }

    public function confirm(ConfirmTwoFactorAuthentication $confirm): void
    {
        abort_unless(Auth::user()->isStaff(), 403);

        $this->validate(['code' => ['required', 'string', 'size:6']], [], ['code' => 'kode autentikasi']);

        $confirm(Auth::user(), $this->code);

        ActivityLog::record('user.two_factor_enabled', 'Mengaktifkan verifikasi dua langkah', Auth::user());

        $this->reset('code');
        $this->showRecoveryCodes = true;
        $this->refreshState();
        $this->dispatch('toast', variant: 'success', text: 'Verifikasi dua langkah aktif.');
    }

    public function regenerateRecoveryCodes(GenerateNewRecoveryCodes $generate): void
    {
        $generate(Auth::user());

        $this->showRecoveryCodes = true;
        $this->refreshState();
    }

    public function disable(DisableTwoFactorAuthentication $disable): void
    {
        $wasEnabled = $this->isEnabled;

        $disable(Auth::user());

        if ($wasEnabled) {
            ActivityLog::record('user.two_factor_disabled', 'Menonaktifkan verifikasi dua langkah', Auth::user());
        }

        $this->reset('code', 'showRecoveryCodes');
        $this->refreshState();
    }

    private function refreshState(): void
    {
        Auth::user()->refresh();
        unset($this->isEnabled, $this->isConfirming, $this->recoveryCodes);
    }
}; ?>

<section class="mt-10 space-y-4">
    <div>
        <h2 class="font-semibold">Verifikasi dua langkah</h2>
        <p class="text-sm text-base-content/70">Tambahan keamanan untuk akun staf: selain password, login meminta kode dari aplikasi autentikator (Google Authenticator, Microsoft Authenticator, dan sejenisnya).</p>
    </div>

    @if ($this->isEnabled)
        <div class="flex items-center gap-2">
            <span class="badge badge-success">Aktif</span>
        </div>

        @if ($showRecoveryCodes)
            <div class="rounded-box border border-warning p-4">
                <p class="mb-2 text-sm font-medium">Simpan kode pemulihan ini di tempat aman. Setiap kode hanya bisa dipakai sekali bila ponsel Anda hilang.</p>
                <div class="grid grid-cols-2 gap-1 font-mono text-sm">
                    @foreach ($this->recoveryCodes as $recoveryCode)
                        <span wire:key="recovery-{{ $loop->index }}">{{ $recoveryCode }}</span>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="flex flex-wrap gap-2">
            @if ($showRecoveryCodes)
                <button type="button" class="btn btn-sm" wire:click="regenerateRecoveryCodes">Buat ulang kode pemulihan</button>
            @else
                <button type="button" class="btn btn-sm" wire:click="$set('showRecoveryCodes', true)">Lihat kode pemulihan</button>
            @endif
            <x-confirm-button action="disable()" title="Nonaktifkan verifikasi dua langkah" message="Akun Anda hanya akan dilindungi password." confirm-label="Nonaktifkan" danger class="btn btn-sm btn-error btn-outline">Nonaktifkan</x-confirm-button>
        </div>
    @elseif ($this->isConfirming)
        <div class="space-y-4 rounded-box border border-base-300 bg-base-100 p-4">
            <p class="text-sm">1. Pindai kode QR ini dengan aplikasi autentikator, atau masukkan kunci pengaturan secara manual.</p>
            <div class="w-fit rounded-box bg-white p-3">{!! auth()->user()->twoFactorQrCodeSvg() !!}</div>
            <p class="text-sm">Kunci pengaturan: <span class="font-mono">{{ decrypt(auth()->user()->two_factor_secret) }}</span></p>
            <form wire:submit="confirm" class="space-y-3">
                <x-input wire:model="code" label="2. Masukkan kode 6 digit dari aplikasi" inputmode="numeric" autocomplete="one-time-code" maxlength="6" />
                <div class="flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">Aktifkan</button>
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="disable">Batal</button>
                </div>
            </form>
        </div>
    @else
        <button type="button" class="btn btn-primary" wire:click="enable">Aktifkan verifikasi dua langkah</button>
    @endif
</section>
