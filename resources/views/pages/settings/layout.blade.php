<div class="flex items-start max-md:flex-col">
    <nav class="me-10 w-full pb-4 md:w-[220px]" aria-label="{{ __('Settings') }}">
        <ul class="menu w-full p-0">
            <li><a href="{{ route('profile.edit') }}" @class(['menu-active' => request()->routeIs('profile.edit')]) wire:navigate>{{ __('Profile') }}</a></li>
            <li><a href="{{ route('security.edit') }}" @class(['menu-active' => request()->routeIs('security.edit')]) wire:navigate>{{ __('Security') }}</a></li>
        </ul>
    </nav>

    <div class="divider my-0 md:hidden"></div>

    <div class="flex-1 self-stretch max-md:pt-6">
        <h2 class="font-semibold">{{ $heading ?? '' }}</h2>
        <p class="text-sm text-base-content/70">{{ $subheading ?? '' }}</p>

        <div class="mt-5 w-full max-w-lg">
            {{ $slot }}
        </div>
    </div>
</div>
