{{-- Sidebar navigation, filtered by the authenticated user's role. --}}
@php
    use App\Enums\Role;

    $user = auth()->user();

    /** @var array<int, array{title: string, items: array<int, array{label: string, route: string, icon: string, active: string, roles: array<int, Role>}>}> $groups */
    $groups = [
        [
            'title' => 'Utama',
            'items' => [
                ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'home', 'active' => 'dashboard', 'roles' => Role::cases()],
                ['label' => 'Data Sekolah', 'route' => 'students.index', 'icon' => 'academic-cap', 'active' => 'students.*', 'roles' => [Role::Foundation]],
            ],
        ],
        [
            'title' => 'Data Master',
            'items' => [
                ['label' => 'Data Siswa', 'route' => 'students.index', 'icon' => 'academic-cap', 'active' => 'students.*', 'roles' => [Role::Admin]],
                ['label' => 'Wali Murid', 'route' => 'admin.guardians', 'icon' => 'user-group', 'active' => 'admin.guardians', 'roles' => [Role::Admin]],
                ['label' => 'Kelas', 'route' => 'admin.classrooms', 'icon' => 'rectangle-group', 'active' => 'admin.classrooms', 'roles' => [Role::Admin]],
                ['label' => 'Kenaikan Kelas', 'route' => 'admin.promotions', 'icon' => 'arrow-trending-up', 'active' => 'admin.promotions', 'roles' => [Role::Admin]],
                ['label' => 'Tahun Ajaran', 'route' => 'admin.academic-years', 'icon' => 'calendar', 'active' => 'admin.academic-years', 'roles' => [Role::Admin]],
            ],
        ],
        [
            'title' => 'Sistem',
            'items' => [
                ['label' => 'Data Pengguna', 'route' => 'admin.users', 'icon' => 'identification', 'active' => 'admin.users', 'roles' => [Role::Admin]],
                ['label' => 'Riwayat Aktivitas', 'route' => 'activity-logs.index', 'icon' => 'clock', 'active' => 'activity-logs.*', 'roles' => [Role::Admin, Role::Foundation]],
            ],
        ],
    ];
@endphp

@foreach ($groups as $group)
    @php
        $items = array_filter($group['items'], fn (array $item): bool => $user->hasRole(...$item['roles']));
    @endphp

    @if ($items !== [])
        <ul class="menu w-full px-0">
            <li class="menu-title">{{ $group['title'] }}</li>
            @foreach ($items as $item)
                <li>
                    <a href="{{ route($item['route']) }}" @class(['menu-active' => request()->routeIs($item['active'])]) wire:navigate>
                        <x-icon :name="$item['icon']" />
                        {{ $item['label'] }}
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
@endforeach
