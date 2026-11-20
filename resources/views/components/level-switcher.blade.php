{{-- Topbar dropdown for staff to switch the active level (jenjang). --}}
@php
    $activeLevel = \App\Models\Level::active();
    $levels = \App\Models\Level::query()->ordered()->get();
@endphp

<details
    x-data
    x-on:click.outside="$el.removeAttribute('open')"
    x-on:keydown.escape="$el.removeAttribute('open')"
    {{ $attributes->class('dropdown dropdown-end') }}
>
    <summary class="btn btn-sm btn-outline btn-primary" data-test="level-switcher">
        <x-icon name="building-office" class="size-4" />
        <span class="whitespace-nowrap"><span class="max-sm:hidden">Jenjang: </span><strong>{{ $activeLevel?->name ?? 'Semua' }}</strong></span>
        <x-icon name="chevron-down" class="size-4 opacity-60" />
    </summary>

    @php
        $options = [
            ['value' => \App\Models\Level::ALL, 'name' => 'Semua jenjang', 'active' => $activeLevel === null],
            ...$levels->map(fn ($level) => ['value' => $level->id, 'name' => $level->name, 'active' => (bool) $activeLevel?->is($level)]),
        ];
    @endphp

    {{-- Each option is a menu item button submitting its own hidden form, so the whole row is the hit target. --}}
    <ul class="menu dropdown-content z-30 mt-1 w-52 rounded-box bg-base-100 p-2 text-base-content shadow-lg ring-1 ring-base-300">
        @foreach ($options as $option)
            <li>
                <button
                    type="submit"
                    form="level-switch-{{ $option['value'] }}"
                    @class(['justify-between', 'menu-active' => $option['active']])
                    @if ($option['active']) aria-current="true" @endif
                >
                    {{ $option['name'] }}
                    @if ($option['active'])
                        <x-icon name="check" class="size-4" />
                    @endif
                </button>
            </li>
        @endforeach
    </ul>

    @foreach ($options as $option)
        <form id="level-switch-{{ $option['value'] }}" method="POST" action="{{ route('levels.switch') }}" class="hidden">
            @csrf
            <input type="hidden" name="level" value="{{ $option['value'] }}" />
            <input type="hidden" name="stay" value="1" />
        </form>
    @endforeach
</details>
