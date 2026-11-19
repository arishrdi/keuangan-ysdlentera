<?php

use Livewire\Component;

new class extends Component {}; ?>

<section class="mt-10 space-y-6">
    <div class="relative mb-5">
        <h2 class="font-semibold">{{ __('Delete account') }}</h2>
        <p class="text-sm text-base-content/70">{{ __('Delete your account and all of its resources') }}</p>
    </div>

    <button
        type="button"
        class="btn btn-error"
        x-data
        x-on:click="$dispatch('open-modal', 'confirm-user-deletion')"
        data-test="delete-user-button"
    >
        {{ __('Delete account') }}
    </button>

    <livewire:pages::settings.delete-user-modal />
</section>
