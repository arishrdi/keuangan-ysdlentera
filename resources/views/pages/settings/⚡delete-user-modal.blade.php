<?php

use App\Concerns\PasswordValidationRules;
use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    use PasswordValidationRules;

    public string $password = '';

    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(Logout $logout): void
    {
        $this->validate([
            'password' => $this->currentPasswordRules(),
        ]);

        tap(Auth::user(), $logout(...))->delete();

        $this->redirect('/', navigate: true);
    }
}; ?>

<dialog
    id="confirm-user-deletion"
    class="modal"
    wire:ignore.self
    x-data
    x-on:open-modal.window="if ($event.detail === 'confirm-user-deletion') { $el.showModal(); $nextTick(() => $el.querySelector('input')?.focus()) }"
>
    <div class="modal-box max-w-lg">
        <form method="POST" wire:submit="deleteUser" class="space-y-6">
            <div>
                <h3 class="text-lg font-semibold">{{ __('Are you sure you want to delete your account?') }}</h3>

                <p class="mt-2 text-sm text-base-content/70">
                    {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.') }}
                </p>
            </div>

            <x-input wire:model="password" :label="__('Password')" type="password" viewable />

            <div class="modal-action">
                <button type="button" class="btn" x-on:click="$el.closest('dialog').close()">{{ __('Cancel') }}</button>

                <button type="submit" class="btn btn-error" data-test="confirm-delete-user-button">
                    {{ __('Delete account') }}
                </button>
            </div>
        </form>
    </div>

    <form method="dialog" class="modal-backdrop">
        <button>{{ __('Close') }}</button>
    </form>
</dialog>
