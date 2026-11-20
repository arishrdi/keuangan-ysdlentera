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
            'title' => 'Keuangan',
            'items' => [
                ['label' => 'Tagihan', 'route' => 'billing.index', 'icon' => 'document-text', 'active' => 'billing.*', 'roles' => [Role::Treasurer, Role::Principal, Role::Foundation]],
                ['label' => 'Pembayaran', 'route' => 'payments.index', 'icon' => 'banknotes', 'active' => 'payments.*', 'roles' => [Role::Treasurer, Role::Principal, Role::Foundation]],
                ['label' => 'Pemasukan', 'route' => 'finance.incomes', 'icon' => 'arrow-down-tray', 'active' => 'finance.incomes', 'roles' => [Role::Treasurer, Role::Principal, Role::Foundation]],
                ['label' => 'Pengeluaran', 'route' => 'finance.expenses', 'icon' => 'arrow-up-tray', 'active' => 'finance.expenses', 'roles' => [Role::Treasurer, Role::Principal, Role::Foundation]],
                ['label' => 'Kas', 'route' => 'finance.cash', 'icon' => 'wallet', 'active' => 'finance.cash', 'roles' => [Role::Treasurer, Role::Principal, Role::Foundation]],
                ['label' => 'Tabungan', 'route' => 'savings.index', 'icon' => 'banknotes', 'active' => 'savings.*', 'roles' => [Role::Treasurer, Role::Principal, Role::Foundation]],
                ['label' => 'Anggaran', 'route' => 'budgets.index', 'icon' => 'clipboard-document-check', 'active' => 'budgets.*', 'roles' => [Role::Treasurer, Role::Principal, Role::Foundation]],
                ['label' => 'Laporan', 'route' => 'reports.index', 'icon' => 'chart-pie', 'active' => 'reports.*', 'roles' => [Role::Treasurer, Role::Principal, Role::Foundation]],
                ['label' => 'Tagihan', 'route' => 'guardian.bills', 'icon' => 'document-text', 'active' => 'guardian.bills', 'roles' => [Role::Guardian]],
                ['label' => 'Cicilan', 'route' => 'guardian.installments', 'icon' => 'chart-pie', 'active' => 'guardian.installments', 'roles' => [Role::Guardian]],
                ['label' => 'Tabungan', 'route' => 'guardian.savings', 'icon' => 'wallet', 'active' => 'guardian.savings', 'roles' => [Role::Guardian]],
                ['label' => 'Riwayat Pembayaran', 'route' => 'guardian.payments', 'icon' => 'clock', 'active' => 'guardian.payments', 'roles' => [Role::Guardian]],
            ],
        ],
        [
            'title' => 'Data Master',
            'items' => [
                ['label' => 'Data Siswa', 'route' => 'students.index', 'icon' => 'academic-cap', 'active' => 'students.*', 'roles' => [Role::Admin]],
                ['label' => 'Import Siswa', 'route' => 'admin.student-import', 'icon' => 'arrow-up-tray', 'active' => 'admin.student-import', 'roles' => [Role::Admin]],
                ['label' => 'Wali Murid', 'route' => 'admin.guardians', 'icon' => 'user-group', 'active' => 'admin.guardians', 'roles' => [Role::Admin]],
                ['label' => 'Kelas', 'route' => 'admin.classrooms', 'icon' => 'rectangle-group', 'active' => 'admin.classrooms', 'roles' => [Role::Admin]],
                ['label' => 'Kenaikan Kelas', 'route' => 'admin.promotions', 'icon' => 'arrow-trending-up', 'active' => 'admin.promotions', 'roles' => [Role::Admin]],
                ['label' => 'Tahun Ajaran', 'route' => 'admin.academic-years', 'icon' => 'calendar', 'active' => 'admin.academic-years', 'roles' => [Role::Admin]],
            ],
        ],
        [
            'title' => 'Pengaturan Keuangan',
            'items' => [
                ['label' => 'Kategori & Tarif', 'route' => 'admin.fee-rates', 'icon' => 'tag', 'active' => 'admin.fee-rates', 'roles' => [Role::Admin]],
                ['label' => 'Katalog Seragam', 'route' => 'admin.uniform-items', 'icon' => 'shopping-bag', 'active' => 'admin.uniform-items', 'roles' => [Role::Admin]],
                ['label' => 'Akun Kas', 'route' => 'admin.cash-accounts', 'icon' => 'wallet', 'active' => 'admin.cash-accounts', 'roles' => [Role::Admin]],
                ['label' => 'Rekening & QRIS', 'route' => 'admin.payment-channels', 'icon' => 'credit-card', 'active' => 'admin.payment-channels', 'roles' => [Role::Admin]],
                ['label' => 'Kategori Transaksi', 'route' => 'admin.transaction-categories', 'icon' => 'tag', 'active' => 'admin.transaction-categories', 'roles' => [Role::Admin]],
            ],
        ],
        [
            'title' => 'Sistem',
            'items' => [
                ['label' => 'Profil Jenjang', 'route' => 'admin.levels', 'icon' => 'building-office', 'active' => 'admin.levels', 'roles' => [Role::Admin]],
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
