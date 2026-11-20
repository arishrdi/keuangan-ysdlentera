@php
    use App\Enums\Role;
    use App\Models\AcademicYear;
    use App\Models\Classroom;
    use App\Models\Level;
    use App\Models\Student;
    use App\Models\User;

    $user = auth()->user();
    $activeLevel = $user->isStaff() ? Level::active() : null;
    $academicYear = AcademicYear::current();
@endphp

<x-layouts::app title="Dashboard">
    <x-page-header
        :title="'Selamat datang, '.$user->name"
        :description="$user->role->label().($user->isStaff() ? ' · Jenjang '.($activeLevel?->name ?? 'Semua').' · Tahun ajaran '.($academicYear?->name ?? 'belum diatur') : '')"
    />

    @if ($user->isStaff())
        @if ($academicYear === null && $user->hasRole(Role::Admin))
            <div role="alert" class="alert alert-warning mb-6">
                <span>Belum ada tahun ajaran aktif. Atur di menu <a href="{{ route('admin.academic-years') }}" class="link font-medium" wire:navigate>Tahun Ajaran</a>.</span>
            </div>
        @endif

        @php
            $studentQuery = Student::query()->active()->when($activeLevel, fn ($query) => $query->whereBelongsTo($activeLevel));
            $classroomQuery = Classroom::query()
                ->when($academicYear, fn ($query) => $query->whereBelongsTo($academicYear))
                ->when($activeLevel, fn ($query) => $query->whereBelongsTo($activeLevel));
        @endphp

        <div class="stats stats-vertical w-full border border-base-300 shadow-sm md:stats-horizontal">
            <div class="stat">
                <div class="stat-title">Siswa aktif</div>
                <div class="stat-value text-primary">{{ number_format($studentQuery->count(), 0, ',', '.') }}</div>
                <div class="stat-desc">{{ $activeLevel?->name ?? 'Semua jenjang' }}</div>
            </div>
            <div class="stat">
                <div class="stat-title">Kelas</div>
                <div class="stat-value">{{ number_format($classroomQuery->count(), 0, ',', '.') }}</div>
                <div class="stat-desc">Tahun ajaran {{ $academicYear?->name ?? '-' }}</div>
            </div>
            @if ($user->hasRole(Role::Admin))
                <div class="stat">
                    <div class="stat-title">Wali murid</div>
                    <div class="stat-value">{{ number_format(User::query()->where('role', Role::Guardian)->count(), 0, ',', '.') }}</div>
                    <div class="stat-desc">Akun terdaftar</div>
                </div>
                <div class="stat">
                    <div class="stat-title">Pengguna staf</div>
                    <div class="stat-value">{{ number_format(User::query()->where('role', '!=', Role::Guardian)->count(), 0, ',', '.') }}</div>
                    <div class="stat-desc">Yayasan, Kepsek, Bendahara, Admin</div>
                </div>
            @endif
        </div>

        @unless ($user->hasRole(Role::Admin))
            @php
                $billQuery = \App\Models\Bill::query()
                    ->where('status', '!=', \App\Enums\BillStatus::Cancelled)
                    ->when($academicYear, fn ($query) => $query->whereBelongsTo($academicYear))
                    ->when($activeLevel, fn ($query) => $query->whereBelongsTo($activeLevel));
                $billed = (int) (clone $billQuery)->sum(\Illuminate\Support\Facades\DB::raw('amount - discount_amount'));
                $received = (int) (clone $billQuery)->sum('paid_amount');
                $pendingPayments = \App\Models\Payment::query()
                    ->where('status', \App\Enums\PaymentStatus::Pending)
                    ->when($activeLevel, fn ($query) => $query->whereBelongsTo($activeLevel))
                    ->count();
            @endphp

            <div class="stats stats-vertical mt-6 w-full border border-base-300 shadow-sm md:stats-horizontal">
                <div class="stat">
                    <div class="stat-title">Total tagihan siswa</div>
                    <div class="stat-value text-2xl">{{ \Illuminate\Support\Number::rupiah($billed) }}</div>
                    <div class="stat-desc">Tahun ajaran {{ $academicYear?->name ?? '-' }}</div>
                </div>
                <div class="stat">
                    <div class="stat-title">Pembayaran diterima</div>
                    <div class="stat-value text-2xl text-success">{{ \Illuminate\Support\Number::rupiah($received) }}</div>
                </div>
                <div class="stat">
                    <div class="stat-title">Belum dibayar</div>
                    <div class="stat-value text-2xl text-error">{{ \Illuminate\Support\Number::rupiah($billed - $received) }}</div>
                </div>
                <div class="stat">
                    <div class="stat-title">Menunggu verifikasi</div>
                    <div class="stat-value text-2xl">{{ $pendingPayments }}</div>
                    <div class="stat-desc"><a href="{{ route('payments.index', ['status' => 'menunggu']) }}" class="link" wire:navigate>Lihat pembayaran</a></div>
                </div>
            </div>

            @php
                $cashBalance = (int) \App\Models\CashAccount::query()
                    ->when($activeLevel, fn ($query) => $query->whereBelongsTo($activeLevel))
                    ->withBalance()
                    ->get()
                    ->sum('balance');
                $monthEntries = \App\Models\CashTransaction::query()
                    ->effective()
                    ->when($activeLevel, fn ($query) => $query->whereBelongsTo($activeLevel))
                    ->whereBetween('transacted_on', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]);
                $monthIncome = (int) (clone $monthEntries)->whereIn('type', [\App\Enums\CashTransactionType::StudentPayment, \App\Enums\CashTransactionType::Income])->sum('amount');
                $monthExpense = (int) (clone $monthEntries)->where('type', \App\Enums\CashTransactionType::Expense)->sum('amount');
            @endphp

            @php
                $savingsBalance = (int) \App\Models\Student::query()
                    ->when($activeLevel, fn ($query) => $query->whereBelongsTo($activeLevel))
                    ->withSavingsBalance()
                    ->get(['students.id'])
                    ->sum('savings_balance');
            @endphp

            <div class="stats stats-vertical mt-6 w-full border border-base-300 shadow-sm md:stats-horizontal">
                <div class="stat">
                    <div class="stat-title">Total tabungan siswa</div>
                    <div class="stat-value text-2xl">{{ \Illuminate\Support\Number::rupiah($savingsBalance) }}</div>
                    <div class="stat-desc">Dana titipan, di luar kas</div>
                </div>
                <div class="stat">
                    <div class="stat-title">Saldo kas</div>
                    <div class="stat-value text-2xl text-primary">{{ \Illuminate\Support\Number::rupiah($cashBalance) }}</div>
                    <div class="stat-desc"><a href="{{ route('finance.cash') }}" class="link" wire:navigate>Lihat mutasi</a></div>
                </div>
                <div class="stat">
                    <div class="stat-title">Pemasukan {{ now()->translatedFormat('F') }}</div>
                    <div class="stat-value text-2xl text-success">{{ \Illuminate\Support\Number::rupiah($monthIncome) }}</div>
                </div>
                <div class="stat">
                    <div class="stat-title">Pengeluaran {{ now()->translatedFormat('F') }}</div>
                    <div class="stat-value text-2xl text-error">{{ \Illuminate\Support\Number::rupiah($monthExpense) }}</div>
                </div>
            </div>

            @if ($activeLevel === null && $user->hasRole(Role::Foundation, Role::Principal))
                @php
                    $consolidatedFilters = \App\Actions\Reports\ReportFilters::fromArray([], null);
                    $consolidated = app(\App\Actions\Reports\ConsolidatedReport::class)->build($consolidatedFilters);
                @endphp

                <div class="mt-6 overflow-x-auto rounded-box border border-base-300">
                    <div class="flex items-center justify-between px-4 pt-4">
                        <h2 class="font-semibold">Perbandingan per jenjang · {{ now()->translatedFormat('F Y') }}</h2>
                        @if ($user->hasRole(Role::Foundation))
                            <a href="{{ route('reports.show', 'konsolidasi') }}" class="link text-sm" wire:navigate>Laporan konsolidasi</a>
                        @endif
                    </div>
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                @foreach ($consolidated->columns as $column)
                                    <th @class(['text-end' => $column['type'] !== 'text'])>{{ $column['label'] }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ([...$consolidated->rows, $consolidated->totals] as $row)
                                <tr @class(['bg-base-200 font-semibold' => $loop->last])>
                                    @foreach ($consolidated->columns as $column)
                                        <td @class(['text-end font-mono' => $column['type'] !== 'text'])>{{ \App\Actions\Reports\ReportExporter::display($row[$column['key']] ?? null, $column['type']) }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @php
                $budgets = \App\Models\BudgetProposal::query()
                    ->where('status', '!=', \App\Enums\BudgetStatus::Draft)
                    ->when($activeLevel, fn ($query) => $query->whereBelongsTo($activeLevel))
                    ->withRealizedAmount()
                    ->get();
                $approvedBudgets = $budgets->filter(fn ($budget) => $budget->status->acceptsExpenses() || $budget->status === \App\Enums\BudgetStatus::Completed);
                $awaitingStatus = collect(\App\Enums\BudgetStatus::cases())->first(fn ($status) => $status->awaitingRole() === $user->role);
                $awaitingCount = $awaitingStatus
                    ? $budgets->where('status', $awaitingStatus)->count()
                    : $budgets->whereIn('status', [\App\Enums\BudgetStatus::AwaitingPrincipal, \App\Enums\BudgetStatus::AwaitingFoundation])->count();
            @endphp

            <div class="stats stats-vertical mt-6 w-full border border-base-300 shadow-sm md:stats-horizontal">
                <div class="stat">
                    <div class="stat-title">Anggaran diajukan</div>
                    <div class="stat-value text-2xl">{{ \Illuminate\Support\Number::rupiah($budgets->sum('total_amount')) }}</div>
                </div>
                <div class="stat">
                    <div class="stat-title">Disetujui</div>
                    <div class="stat-value text-2xl text-success">{{ \Illuminate\Support\Number::rupiah($approvedBudgets->sum('total_amount')) }}</div>
                </div>
                <div class="stat">
                    <div class="stat-title">Digunakan / sisa</div>
                    <div class="stat-value text-2xl">{{ \Illuminate\Support\Number::rupiah($approvedBudgets->sum('realized_amount')) }}</div>
                    <div class="stat-desc">Sisa {{ \Illuminate\Support\Number::rupiah($approvedBudgets->sum('total_amount') - $approvedBudgets->sum('realized_amount')) }}</div>
                </div>
                <div class="stat">
                    <div class="stat-title">{{ $awaitingStatus ? 'Menunggu persetujuan Anda' : 'Menunggu persetujuan' }}</div>
                    <div @class(['stat-value text-2xl', 'text-warning' => $awaitingCount > 0])>{{ $awaitingCount }}</div>
                    <div class="stat-desc"><a href="{{ route('budgets.index') }}" class="link" wire:navigate>Lihat anggaran</a></div>
                </div>
            </div>
        @endunless
    @else
        @php
            $children = $user->students()
                ->with(['level', 'currentEnrollment.classroom', 'bills' => fn ($query) => $query->outstanding()->orderByRaw('due_date is null')->orderBy('due_date')])
                ->withSavingsBalance()
                ->orderBy('name')
                ->get();
        @endphp

        <h2 class="mb-3 font-semibold">Anak terhubung</h2>

        <div class="grid gap-4 md:grid-cols-2">
            @forelse ($children as $child)
                <div class="card border border-base-300 bg-base-100 shadow-sm" wire:key="child-{{ $child->id }}">
                    <div class="card-body">
                        <h3 class="card-title">{{ $child->name }}</h3>
                        <p class="text-sm text-base-content/70">
                            {{ $child->level->name }} · Kelas {{ $child->currentEnrollment?->classroom->name ?? '-' }}
                        </p>
                        <div><span class="badge {{ $child->status->badgeClass() }}">{{ $child->status->label() }}</span></div>
                        @php
                            $outstanding = $child->bills->sum(fn ($bill) => $bill->remainingAmount());
                            $nextBill = $child->bills->first();
                        @endphp
                        <div class="mt-2 rounded-box bg-base-200 p-3">
                            <div class="text-xs text-base-content/60">Sisa tagihan</div>
                            <div @class(['text-xl font-semibold', 'text-error' => $outstanding > 0, 'text-success' => $outstanding === 0])>{{ \Illuminate\Support\Number::rupiah($outstanding) }}</div>
                            @if ($nextBill)
                                <div @class(['text-xs', 'text-error' => $nextBill->isOverdue(), 'text-base-content/70' => ! $nextBill->isOverdue()])>
                                    Terdekat: {{ $nextBill->description }}{{ $nextBill->due_date ? ' · jatuh tempo '.$nextBill->due_date->translatedFormat('j M Y') : '' }}
                                </div>
                            @endif
                        </div>
                        <div class="flex items-center justify-between rounded-box border border-base-300 p-3">
                            <span class="text-xs text-base-content/60">Saldo tabungan</span>
                            <a href="{{ route('guardian.savings', ['anak' => $child->id]) }}" class="link font-semibold" wire:navigate>{{ \Illuminate\Support\Number::rupiah((int) $child->savings_balance) }}</a>
                        </div>
                        @if ($outstanding > 0)
                            <div class="card-actions justify-end">
                                <a href="{{ route('guardian.bills', ['anak' => $child->id]) }}" class="btn btn-primary btn-sm" wire:navigate>Lihat & bayar</a>
                            </div>
                        @endif
                    </div>
                </div>
            @empty
                <div class="rounded-box border border-dashed border-base-300 p-6 text-sm text-base-content/70">
                    Belum ada siswa yang terhubung dengan akun Anda. Hubungi admin sekolah.
                </div>
            @endforelse
        </div>


    @endif
</x-layouts::app>
