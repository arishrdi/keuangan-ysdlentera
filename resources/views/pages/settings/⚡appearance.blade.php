<?php

use Livewire\Component;
use Livewire\Attributes\Title;

new #[Title('Appearance settings')] class extends Component {
    //
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <h2 class="sr-only">{{ __('Appearance settings') }}</h2>

    <x-pages::settings.layout :heading="__('Appearance')" :subheading="__('Update the appearance settings for your account')">
        <div
            x-data="{
                appearance: localStorage.getItem('appearance') || 'system',
                set(value) {
                    this.appearance = value;
                    localStorage.setItem('appearance', value);
                    window.applyAppearance(value);
                },
            }"
            class="join"
            role="radiogroup"
            aria-label="{{ __('Appearance') }}"
        >
            @foreach (['light' => ['sun', __('Light')], 'dark' => ['moon', __('Dark')], 'system' => ['computer-desktop', __('System')]] as $value => [$icon, $label])
                <button
                    type="button"
                    role="radio"
                    class="btn join-item"
                    :class="{ 'btn-active': appearance === '{{ $value }}' }"
                    :aria-checked="appearance === '{{ $value }}'"
                    x-on:click="set('{{ $value }}')"
                >
                    <x-icon :name="$icon" class="size-4" />
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </x-pages::settings.layout>
</section>
