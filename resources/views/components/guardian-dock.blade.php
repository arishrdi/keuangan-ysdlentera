{{-- Bottom navigation for guardians on small screens. --}}
@php
    $items = [
        ['label' => 'Beranda', 'route' => 'dashboard', 'icon' => 'home', 'active' => 'dashboard'],
        ['label' => 'Tagihan', 'route' => 'guardian.bills', 'icon' => 'document-text', 'active' => 'guardian.bills'],
        ['label' => 'Tabungan', 'route' => 'guardian.savings', 'icon' => 'wallet', 'active' => 'guardian.savings'],
        ['label' => 'Riwayat', 'route' => 'guardian.payments', 'icon' => 'clock', 'active' => 'guardian.payments'],
        ['label' => 'Profil', 'route' => 'profile.edit', 'icon' => 'user-circle', 'active' => 'profile.edit'],
    ];
@endphp

<nav class="dock dock-sm z-40 border-t border-base-300 bg-base-100 lg:hidden" aria-label="Menu wali murid">
    @foreach ($items as $item)
        <a href="{{ route($item['route']) }}" @class(['dock-active text-primary' => request()->routeIs($item['active'])]) wire:navigate>
            <x-icon :name="$item['icon']" class="size-5" />
            <span class="dock-label">{{ $item['label'] }}</span>
        </a>
    @endforeach
</nav>
