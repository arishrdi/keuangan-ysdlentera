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
    @if ($user->isStaff())
        @php
            $isAdmin = $user->hasRole(Role::Admin);
            $dashboard = new \App\Actions\Reports\StaffDashboard($user, $activeLevel, $academicYear);
            $availableMonths = $dashboard->availableMonths();
            $requestedMonth = \App\Actions\Reports\StaffDashboard::parseMonth(request()->query('bulan'));

            if ($requestedMonth !== null && collect($availableMonths)->contains(fn ($month) => $month->equalTo($requestedMonth))) {
                $dashboard = new \App\Actions\Reports\StaffDashboard($user, $activeLevel, $academicYear, $requestedMonth);
            }

            $selectedMonth = $dashboard->month();
            $isCurrentMonth = $selectedMonth->isSameMonth(now());
            $rupiah = fn (int $amount): string => \Illuminate\Support\Number::rupiah($amount);
            $short = fn (int $amount): string => \App\Actions\Reports\StaffDashboard::shortRupiah($amount);
            $monthName = $selectedMonth->translatedFormat($selectedMonth->isSameYear(now()) ? 'F' : 'F Y');
            $monthNote = $isCurrentMonth ? 'Bulan berjalan' : 'Bulan '.$selectedMonth->translatedFormat('F Y');

            if (! $isAdmin) {
                $bills = $dashboard->bills();
                $cashBalance = $dashboard->cashBalance();
                $monthIncome = $dashboard->monthIncome();
                $monthExpense = $dashboard->monthExpense();
                $pendingPayments = $dashboard->pendingPayments();
                $awaitingStatus = $dashboard->awaitingStatus();
                $queue = $dashboard->approvalQueue();
                $budgets = $dashboard->budgets();

                $summary = 'Saldo kas '.$short($cashBalance).'. Tagihan tahun ajaran ini terbayar '.str_replace('.', ',', (string) $bills['percent']).'%';
                $summary .= $awaitingStatus
                    ? ($queue->isNotEmpty() ? ' dan '.$queue->count().' pengajuan anggaran menunggu persetujuan Anda.' : ', tidak ada pengajuan yang menunggu Anda.')
                    : ($pendingPayments > 0 ? ' dan '.$pendingPayments.' pembayaran menunggu verifikasi.' : '.');
            }
        @endphp

        {{-- Greeting band: the day's context in one sentence, and the one action that matters. --}}
        <section class="relative mb-4 overflow-hidden rounded-box bg-neutral p-6 text-neutral-content lg:p-8" aria-labelledby="dash-greeting">
            <x-app-logo-icon class="pointer-events-none absolute -end-16 -top-24 size-72 text-accent opacity-10" aria-hidden="true" />

            <div class="relative flex flex-wrap items-end justify-between gap-6">
                <div class="min-w-0 max-w-3xl">
                    <h1 id="dash-greeting" class="text-2xl font-bold tracking-tight lg:text-3xl">Assalamu'alaikum, {{ $user->name }}</h1>
                    <p class="mt-2 text-neutral-content/80">{{ $isAdmin ? 'Ringkasan data sekolah dan pengguna.' : $summary }}</p>
                    <ul class="mt-4 flex flex-wrap gap-1.5" aria-label="Konteks">
                        @foreach ([now()->translatedFormat('l, j F Y'), $user->role->label(), 'Jenjang '.($activeLevel?->name ?? 'Semua'), 'Tahun ajaran '.($academicYear?->name ?? 'belum diatur')] as $chip)
                            <li class="rounded-full bg-neutral-content/12 px-2.5 py-0.5 text-xs font-medium text-neutral-content/85">{{ $chip }}</li>
                        @endforeach
                    </ul>
                </div>

                @unless ($isAdmin)
                    <div class="flex flex-wrap items-center gap-2">
                        <form method="GET" action="{{ route('dashboard') }}">
                            <label for="dash-month" class="sr-only">Bulan</label>
                            <select id="dash-month" name="bulan" class="select select-sm w-44 border-0 bg-base-100 font-semibold text-primary" x-data x-on:change="$el.form.requestSubmit()">
                                @foreach ($availableMonths as $month)
                                    <option value="{{ $month->format('Y-m') }}" @selected($month->equalTo($selectedMonth))>{{ $month->translatedFormat('F Y') }}</option>
                                @endforeach
                            </select>
                            <noscript><button type="submit" class="btn btn-sm">Tampilkan</button></noscript>
                        </form>

                        @if ($awaitingStatus && $queue->isNotEmpty())
                            <a href="{{ route('budgets.index') }}" class="btn btn-sm border-0 bg-base-100 text-primary hover:bg-base-200" wire:navigate>Tinjau pengajuan</a>
                        @elseif ($pendingPayments > 0)
                            <a href="{{ route('payments.index', ['status' => 'menunggu']) }}" class="btn btn-sm border-0 bg-base-100 text-primary hover:bg-base-200" wire:navigate>Verifikasi pembayaran</a>
                        @endif
                    </div>
                @endunless
            </div>
        </section>

        @if ($academicYear === null && $isAdmin)
            <div role="alert" class="alert alert-warning mb-4">
                <span>Belum ada tahun ajaran aktif. Atur di menu <a href="{{ route('admin.academic-years') }}" class="link font-medium" wire:navigate>Tahun Ajaran</a>.</span>
            </div>
        @endif

        @if ($isAdmin)
            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Ringkasan data sekolah">
                @foreach ([
                    ['Siswa aktif', number_format($dashboard->studentCount(), 0, ',', '.'), $activeLevel?->name ?? 'Semua jenjang'],
                    ['Kelas', number_format($dashboard->classroomCount(), 0, ',', '.'), 'Tahun ajaran '.($academicYear?->name ?? '-')],
                    ['Wali murid', number_format(User::query()->where('role', Role::Guardian)->count(), 0, ',', '.'), 'Akun terdaftar'],
                    ['Pengguna staf', number_format(User::query()->where('role', '!=', Role::Guardian)->count(), 0, ',', '.'), 'Yayasan, Kepsek, Bendahara, Admin'],
                ] as [$label, $value, $note])
                    <div class="panel p-5">
                        <p class="kpi-label">{{ $label }}</p>
                        <p class="kpi-value mt-1">{{ $value }}</p>
                        <p class="kpi-note mt-1">{{ $note }}</p>
                    </div>
                @endforeach
            </section>
        @else
            {{-- Headline figures. --}}
            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5" aria-label="Angka utama">
                <div class="panel p-5">
                    <p class="kpi-label">Saldo kas</p>
                    <p class="kpi-value mt-1 text-primary" title="{{ $rupiah($cashBalance) }}">{{ $short($cashBalance) }}</p>
                    <a href="{{ route('finance.cash') }}" class="link link-hover kpi-note mt-1 inline-block" wire:navigate>Lihat mutasi</a>
                </div>
                <div class="panel p-5">
                    <p class="kpi-label">Pemasukan {{ $monthName }}</p>
                    <p class="kpi-value mt-1" title="{{ $rupiah($monthIncome) }}">{{ $short($monthIncome) }}</p>
                    <p class="kpi-note mt-1">{{ $monthNote }}</p>
                </div>
                <div class="panel p-5">
                    <p class="kpi-label">Pengeluaran {{ $monthName }}</p>
                    <p class="kpi-value mt-1" title="{{ $rupiah($monthExpense) }}">{{ $short($monthExpense) }}</p>
                    <p class="kpi-note mt-1">{{ $monthNote }}</p>
                </div>
                <div class="panel p-5">
                    <p class="kpi-label">Tunggakan</p>
                    <p @class(['kpi-value mt-1', 'text-error' => $bills['outstanding'] > 0]) title="{{ $rupiah($bills['outstanding']) }}">{{ $short($bills['outstanding']) }}</p>
                    <p class="kpi-note mt-1">{{ $bills['outstanding_students'] }} siswa belum lunas</p>
                </div>
                @if ($awaitingStatus)
                    <div class="panel p-5">
                        <p class="kpi-label">Menunggu persetujuan Anda</p>
                        <p @class(['kpi-value mt-1', 'text-warning-content' => $queue->isNotEmpty()])>{{ $queue->count() }} pengajuan</p>
                        <a href="{{ route('budgets.index') }}" class="link link-hover kpi-note mt-1 inline-block" wire:navigate>Lihat anggaran</a>
                    </div>
                @else
                    <div class="panel p-5">
                        <p class="kpi-label">Menunggu verifikasi</p>
                        <p @class(['kpi-value mt-1', 'text-warning-content' => $pendingPayments > 0])>{{ $pendingPayments }} pembayaran</p>
                        <a href="{{ route('payments.index', ['status' => 'menunggu']) }}" class="link link-hover kpi-note mt-1 inline-block" wire:navigate>Lihat pembayaran</a>
                    </div>
                @endif
            </section>

            <div class="mt-4 grid gap-4 xl:grid-cols-12">
                {{-- Income against expense per month: two series, one axis. --}}
                @php
                    $flow = $dashboard->monthlyFlow();
                    $flowMax = max(1, collect($flow)->flatMap(fn ($month) => [$month['income'], $month['expense']])->max());
                    $flowScale = (int) (ceil($flowMax / (10 ** max(0, strlen((string) $flowMax) - 1))) * (10 ** max(0, strlen((string) $flowMax) - 1)));
                @endphp
                <section class="panel p-5 xl:col-span-8" aria-labelledby="dash-flow">
                    <div class="flex flex-wrap items-baseline justify-between gap-3">
                        <div>
                            <h2 id="dash-flow" class="font-semibold">Pemasukan dan pengeluaran per bulan</h2>
                            <p class="text-xs text-base-content/60">Klik kolom bulan untuk melihat rinciannya.</p>
                        </div>
                        <ul class="flex gap-4 text-xs text-base-content/70" aria-label="Keterangan">
                            <li class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-(--chart-in)"></span>Pemasukan</li>
                            <li class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-(--chart-out)"></span>Pengeluaran</li>
                        </ul>
                    </div>

                    <div class="relative mt-5 h-64 ps-14">
                        <div class="absolute inset-x-0 bottom-7 top-5" aria-hidden="true">
                            @foreach ([1, 0.75, 0.5, 0.25, 0] as $fraction)
                                <div class="absolute inset-x-0 border-t border-dashed border-base-300" style="bottom: {{ $fraction * 100 }}%">
                                    <span class="absolute -top-2 start-0 w-12 text-end text-[0.6875rem] text-base-content/55">{{ $fraction > 0 ? str_replace('Rp ', '', $short((int) ($flowScale * $fraction))) : '0' }}</span>
                                </div>
                            @endforeach
                        </div>

                        <div class="relative flex h-full">
                            @foreach ($flow as $month)
                                @php
                                    $isCurrent = $month['month']->equalTo($selectedMonth);
                                    $incomeHeight = $month['income'] / $flowScale * 100;
                                    $expenseHeight = $month['expense'] / $flowScale * 100;
                                @endphp
                                <a
                                    href="{{ route('dashboard', ['bulan' => $month['month']->format('Y-m')]) }}"
                                    @class(['flex min-w-0 flex-1 flex-col rounded-field transition-colors', 'bg-primary/8' => $isCurrent, 'hover:bg-base-200' => ! $isCurrent])
                                    @if ($isCurrent) aria-current="true" @endif
                                    aria-label="Lihat {{ $month['month']->translatedFormat('F Y') }}"
                                >
                                    <div class="flex flex-1 items-end justify-center gap-0.5 px-1 pt-5">
                                        <div class="tooltip flex h-full w-full max-w-7 items-end" data-tip="Pemasukan {{ $month['month']->translatedFormat('F') }}: {{ $rupiah($month['income']) }}">
                                            <div class="relative w-full rounded-t bg-(--chart-in)" style="height: {{ $incomeHeight }}%">
                                                @if ($isCurrent && $month['income'] > 0)
                                                    <span class="absolute -top-5 left-1/2 -translate-x-1/2 text-[0.6875rem] font-semibold whitespace-nowrap">{{ str_replace('Rp ', '', $short($month['income'])) }}</span>
                                                @endif
                                            </div>
                                        </div>
                                        <div class="tooltip flex h-full w-full max-w-7 items-end" data-tip="Pengeluaran {{ $month['month']->translatedFormat('F') }}: {{ $rupiah($month['expense']) }}">
                                            <div class="relative w-full rounded-t bg-(--chart-out)" style="height: {{ $expenseHeight }}%">
                                                @if ($isCurrent && $month['expense'] > 0)
                                                    <span class="absolute -top-5 left-1/2 -translate-x-1/2 text-[0.6875rem] font-semibold whitespace-nowrap">{{ str_replace('Rp ', '', $short($month['expense'])) }}</span>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                    <span @class(['h-7 pt-1.5 text-center text-xs', 'font-semibold text-primary' => $isCurrent, 'text-base-content/60' => ! $isCurrent])>{{ $month['month']->translatedFormat('M') }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>

                    <table class="sr-only">
                        <caption>Pemasukan dan pengeluaran per bulan</caption>
                        <thead><tr><th>Bulan</th><th>Pemasukan</th><th>Pengeluaran</th></tr></thead>
                        <tbody>
                            @foreach ($flow as $month)
                                <tr><td>{{ $month['month']->translatedFormat('F Y') }}</td><td>{{ $rupiah($month['income']) }}</td><td>{{ $rupiah($month['expense']) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </section>

                {{-- Where this month's money went: one hue, largest first. --}}
                @php $composition = $dashboard->expenseByCategory(); @endphp
                <section class="panel p-5 xl:col-span-4" aria-labelledby="dash-composition">
                    <div class="flex items-baseline justify-between gap-3">
                        <h2 id="dash-composition" class="font-semibold">Pengeluaran {{ $monthName }}</h2>
                        <span class="text-sm font-semibold">{{ $short($monthExpense) }}</span>
                    </div>

                    @if ($composition === [])
                        <p class="mt-6 text-sm text-base-content/60">Tidak ada pengeluaran pada {{ $selectedMonth->translatedFormat('F Y') }}.</p>
                    @else
                        <ul class="mt-5 space-y-4">
                            @foreach ($composition as $row)
                                <li>
                                    <div class="flex items-baseline justify-between gap-3 text-sm">
                                        <span class="min-w-0 truncate">{{ $row['name'] }}</span>
                                        <span class="shrink-0 font-semibold" title="{{ $rupiah($row['amount']) }}">{{ str_replace('.', ',', (string) $row['percent']) }}%</span>
                                    </div>
                                    <div class="mt-1.5 h-2 rounded-full bg-base-200">
                                        <div class="h-2 rounded-full bg-(--chart-out)" style="width: {{ $row['percent'] }}%"></div>
                                    </div>
                                    <p class="mt-1 text-xs text-base-content/60">{{ $rupiah($row['amount']) }}</p>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                {{-- Billing progress per classroom. --}}
                @php $classrooms = $dashboard->billsByClassroom(); @endphp
                <section class="panel overflow-hidden xl:col-span-8" aria-labelledby="dash-classrooms">
                    <div class="flex flex-wrap items-baseline justify-between gap-3 px-5 py-4">
                        <h2 id="dash-classrooms" class="font-semibold">Tagihan per kelas</h2>
                        <a href="{{ route('billing.index') }}" class="btn btn-sm btn-outline btn-primary" wire:navigate>Lihat per siswa</a>
                    </div>
                    <div class="overflow-x-auto border-t border-base-300">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Kelas</th>
                                    <th class="text-end">Siswa</th>
                                    <th class="text-end">Tagihan</th>
                                    <th class="text-end">Terbayar</th>
                                    <th class="text-end">Tunggakan</th>
                                    <th class="w-40">Progres</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($classrooms as $row)
                                    @php $percent = $row->billed > 0 ? (int) floor($row->paid / $row->billed * 100) : 0; @endphp
                                    <tr>
                                        <td class="font-medium">
                                            {{ $row->classroom }}
                                            @if ($activeLevel === null)
                                                <span class="badge badge-outline badge-xs ms-1">{{ $row->level_code }}</span>
                                            @endif
                                        </td>
                                        <td class="text-end">{{ $row->students }}</td>
                                        <td class="text-end">{{ $rupiah($row->billed) }}</td>
                                        <td class="text-end">{{ $rupiah($row->paid) }}</td>
                                        <td @class(['text-end', 'text-error' => $row->billed > $row->paid])>{{ $rupiah($row->billed - $row->paid) }}</td>
                                        <td>
                                            <div class="flex items-center gap-2">
                                                <progress @class(['progress h-2 flex-1', 'progress-primary' => $percent >= 90, 'progress-warning' => $percent >= 75 && $percent < 90, 'progress-error' => $percent < 75]) value="{{ $percent }}" max="100" aria-label="Terbayar {{ $percent }} persen"></progress>
                                                <span class="w-10 text-end text-sm">{{ $percent }}%</span>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="py-8 text-center text-base-content/60">Belum ada tagihan untuk tahun ajaran ini.</td></tr>
                                @endforelse
                            </tbody>
                            @if ($classrooms->isNotEmpty())
                                <tfoot>
                                    <tr class="font-semibold text-base-content">
                                        <td>Total</td>
                                        <td class="text-end">{{ $classrooms->sum('students') }}</td>
                                        <td class="text-end">{{ $rupiah($classrooms->sum('billed')) }}</td>
                                        <td class="text-end">{{ $rupiah($classrooms->sum('paid')) }}</td>
                                        <td class="text-end">{{ $rupiah($classrooms->sum('billed') - $classrooms->sum('paid')) }}</td>
                                        <td class="text-end">{{ str_replace('.', ',', (string) $bills['percent']) }}%</td>
                                    </tr>
                                </tfoot>
                            @endif
                        </table>
                    </div>
                </section>

                <div class="space-y-4 xl:col-span-4">
                    <section class="panel flex items-center gap-5 p-5" aria-labelledby="dash-collection">
                        <div @class(['radial-progress shrink-0 bg-base-200 text-primary', 'after:hidden' => $bills['percent'] <= 0]) style="--value: {{ $bills['percent'] }}; --size: 5.5rem; --thickness: 0.6rem" role="progressbar" aria-valuenow="{{ $bills['percent'] }}" aria-valuemin="0" aria-valuemax="100">
                            <span class="text-sm font-bold text-base-content">{{ str_replace('.', ',', (string) $bills['percent']) }}%</span>
                        </div>
                        <div class="min-w-0">
                            <h2 id="dash-collection" class="font-semibold">Tagihan terbayar</h2>
                            <p class="mt-1 text-sm text-base-content/70">{{ $short($bills['received']) }} dari {{ $short($bills['billed']) }}</p>
                            @if ($bills['outstanding_students'] > 0)
                                <a href="{{ route('billing.index') }}" class="link link-hover text-sm text-error" wire:navigate>{{ $bills['outstanding_students'] }} siswa belum lunas</a>
                            @endif
                        </div>
                    </section>

                    <section class="panel p-5" aria-labelledby="dash-queue">
                        <div class="flex items-baseline justify-between gap-3">
                            <h2 id="dash-queue" class="font-semibold">Antrean persetujuan</h2>
                            <a href="{{ route('budgets.index') }}" class="link link-hover text-sm text-primary" wire:navigate>Semua</a>
                        </div>
                        <ul class="mt-2 divide-y divide-base-300">
                            @forelse ($queue->take(4) as $proposal)
                                <li wire:key="queue-{{ $proposal->id }}">
                                    <a href="{{ route('budgets.show', $proposal) }}" class="-mx-2 flex items-center gap-3 rounded-field px-2 py-3 transition-colors hover:bg-base-200" wire:navigate>
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate text-sm font-semibold">{{ $proposal->title }}</span>
                                            <span class="block truncate text-xs text-base-content/60">{{ $proposal->category->name }}, oleh {{ $proposal->creator?->name ?? '-' }}</span>
                                        </span>
                                        <span class="shrink-0 text-sm font-semibold" title="{{ $rupiah($proposal->total_amount) }}">{{ $short($proposal->total_amount) }}</span>
                                    </a>
                                </li>
                            @empty
                                <li class="py-4 text-sm text-base-content/60">Tidak ada pengajuan yang menunggu.</li>
                            @endforelse
                        </ul>
                    </section>

                    <section class="panel p-5" aria-labelledby="dash-school">
                        <h2 id="dash-school" class="font-semibold">Data sekolah</h2>
                        <dl class="mt-3 grid grid-cols-2 gap-4">
                            <div>
                                <dt class="kpi-label">Siswa aktif</dt>
                                <dd class="mt-0.5 text-lg font-bold">{{ number_format($dashboard->studentCount(), 0, ',', '.') }}</dd>
                                <dd class="kpi-note">{{ $activeLevel?->name ?? 'Semua jenjang' }}</dd>
                            </div>
                            <div>
                                <dt class="kpi-label">Kelas</dt>
                                <dd class="mt-0.5 text-lg font-bold">{{ number_format($dashboard->classroomCount(), 0, ',', '.') }}</dd>
                            </div>
                            <div>
                                <dt class="kpi-label">Tabungan siswa</dt>
                                <dd class="mt-0.5 text-lg font-bold" title="{{ $rupiah($dashboard->savingsBalance()) }}">{{ $short($dashboard->savingsBalance()) }}</dd>
                                <dd class="kpi-note">Dana titipan, di luar kas</dd>
                            </div>
                            <div>
                                <dt class="kpi-label">Anggaran terpakai</dt>
                                <dd class="mt-0.5 text-lg font-bold">{{ $budgets['percent'] }}%</dd>
                                <dd class="kpi-note">{{ $short($budgets['realized']) }} dari {{ $short($budgets['approved']) }}</dd>
                            </div>
                        </dl>
                    </section>
                </div>
            </div>

            @if ($activeLevel === null && $user->hasRole(Role::Foundation, Role::Principal))
                @php
                    $consolidatedFilters = \App\Actions\Reports\ReportFilters::fromArray([], null);
                    $consolidated = app(\App\Actions\Reports\ConsolidatedReport::class)->build($consolidatedFilters);
                @endphp

                <section class="panel mt-4 overflow-hidden" aria-labelledby="dash-levels">
                    <div class="flex flex-wrap items-baseline justify-between gap-2 px-5 py-4">
                        <h2 id="dash-levels" class="font-semibold">Perbandingan per jenjang, {{ now()->translatedFormat('F Y') }}</h2>
                        @if ($user->hasRole(Role::Foundation))
                            <a href="{{ route('reports.show', 'konsolidasi') }}" class="link link-hover text-sm text-primary" wire:navigate>Laporan konsolidasi</a>
                        @endif
                    </div>
                    <div class="overflow-x-auto border-t border-base-300">
                        <table class="table">
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
                </section>
            @endif
        @endif
    @else
        @php
            $children = $user->students()
                ->with(['level', 'currentEnrollment.classroom', 'bills' => fn ($query) => $query->outstanding()->orderByRaw('due_date is null')->orderBy('due_date')])
                ->withSavingsBalance()
                ->orderBy('name')
                ->get();
        @endphp

        <div x-data="{ active: {{ $children->first()?->id ?? 'null' }} }">
            <x-guardian.band :title="'Assalamu\'alaikum, '.$user->name" description="Ringkasan tagihan dan tabungan anak Anda.">
                @if ($children->count() > 1)
                    <div class="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1" role="tablist" aria-label="Pilih anak">
                        @foreach ($children as $child)
                            <button
                                type="button"
                                role="tab"
                                wire:key="home-pill-{{ $child->id }}"
                                x-on:click="active = {{ $child->id }}"
                                x-bind:aria-selected="active === {{ $child->id }} ? 'true' : 'false'"
                                x-bind:class="active === {{ $child->id }} ? 'bg-base-100 text-primary' : 'bg-neutral-content/15 text-neutral-content hover:bg-neutral-content/25'"
                                class="shrink-0 rounded-full px-4 py-1.5 text-sm font-semibold transition-colors focus-visible:outline-accent"
                            >
                                {{ str($child->name)->before(' ')->toString() ?: $child->name }}
                                <span class="font-medium opacity-70">{{ $child->currentEnrollment?->classroom->name }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
            </x-guardian.band>

            <x-guardian.overlap>
                @forelse ($children as $child)
                    @php
                        $outstanding = $child->bills->sum(fn ($bill) => $bill->remainingAmount());
                        $nextBill = $child->bills->first();
                        $laterBills = $child->bills->slice(1, 4);
                        $daysLeft = $nextBill?->due_date ? (int) now()->startOfDay()->diffInDays($nextBill->due_date, false) : null;
                        $dueNote = match (true) {
                            $daysLeft === null => '',
                            $daysLeft > 0 => ', jatuh tempo '.$nextBill->due_date->translatedFormat('j M Y').' ('.$daysLeft.' hari lagi)',
                            $daysLeft === 0 => ', jatuh tempo hari ini',
                            default => ', lewat jatuh tempo '.abs($daysLeft).' hari ('.$nextBill->due_date->translatedFormat('j M Y').')',
                        };
                    @endphp

                    <div class="space-y-4" wire:key="child-{{ $child->id }}" @if ($children->count() > 1) x-show="active === {{ $child->id }}" @endif>
                        @if ($outstanding > 0)
                            <section class="panel p-5 shadow-sm" aria-label="Tagihan {{ $child->name }}">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <h2 class="text-sm text-base-content/70">Sisa tagihan {{ $child->name }}</h2>
                                        <p class="text-xs text-base-content/55">{{ $child->level->name }}, kelas {{ $child->currentEnrollment?->classroom->name ?? '-' }}</p>
                                    </div>
                                    <span class="badge badge-warning shrink-0 font-semibold">Belum lunas</span>
                                </div>

                                <p class="mt-3 text-4xl font-bold tracking-tight text-primary">{{ \Illuminate\Support\Number::rupiah($outstanding) }}</p>

                                @if ($nextBill)
                                    <p @class(['mt-2 text-sm', 'font-medium text-error' => $nextBill->isOverdue(), 'text-base-content/70' => ! $nextBill->isOverdue()])>
                                        Terdekat: {{ $nextBill->description }}{{ $dueNote }}
                                    </p>
                                @endif

                                <a href="{{ route('guardian.bills', ['anak' => $child->id]) }}" class="btn btn-primary btn-lg btn-block mt-5" wire:navigate>Lihat & bayar</a>
                            </section>
                        @else
                            <section class="panel flex items-center gap-4 p-5 shadow-sm" aria-label="Tagihan {{ $child->name }}">
                                <span class="flex size-12 shrink-0 items-center justify-center rounded-full bg-primary text-primary-content">
                                    <x-icon name="check" class="size-6" />
                                </span>
                                <div class="min-w-0">
                                    <h2 class="font-semibold">Tidak ada tunggakan</h2>
                                    <p class="text-sm text-base-content/70">Semua tagihan {{ $child->name }} sudah lunas.</p>
                                </div>
                            </section>
                        @endif

                        @if ($laterBills->isNotEmpty())
                            <section class="panel p-5" aria-labelledby="later-{{ $child->id }}">
                                <div class="flex items-baseline justify-between gap-2">
                                    <h2 id="later-{{ $child->id }}" class="text-sm font-semibold">Tagihan lainnya</h2>
                                    <a href="{{ route('guardian.bills', ['anak' => $child->id]) }}" class="link link-hover text-sm text-primary" wire:navigate>Lihat semua</a>
                                </div>
                                <ul class="mt-2 divide-y divide-base-300">
                                    @foreach ($laterBills as $bill)
                                        <li class="flex items-center gap-3 py-3" wire:key="later-bill-{{ $bill->id }}">
                                            <x-guardian.date-tile :date="$bill->due_date" />
                                            <div class="min-w-0 flex-1">
                                                <p class="truncate font-semibold">{{ $bill->description }}</p>
                                                <p class="text-xs text-base-content/60">{{ $bill->due_date ? 'Jatuh tempo '.$bill->due_date->translatedFormat('j M') : 'Tanpa jatuh tempo' }}</p>
                                            </div>
                                            <p class="shrink-0 font-semibold">{{ \Illuminate\Support\Number::rupiah($bill->remainingAmount()) }}</p>
                                        </li>
                                    @endforeach
                                </ul>
                            </section>
                        @endif

                        <a href="{{ route('guardian.savings', ['anak' => $child->id]) }}" class="panel flex items-center gap-4 p-5 transition-colors hover:border-primary/40" wire:navigate>
                            <span class="flex size-12 shrink-0 items-center justify-center rounded-field bg-primary/10 text-primary">
                                <x-icon name="wallet" class="size-6" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm text-base-content/70">Saldo tabungan</span>
                                <span class="block text-lg font-bold">{{ \Illuminate\Support\Number::rupiah((int) $child->savings_balance) }}</span>
                            </span>
                            <x-icon name="chevron-down" class="size-5 -rotate-90 text-base-content/40" />
                        </a>
                    </div>
                @empty
                    <div class="panel p-6 text-sm text-base-content/70 shadow-sm">
                        Belum ada siswa yang terhubung dengan akun Anda. Hubungi admin sekolah.
                    </div>
                @endforelse
            </x-guardian.overlap>
        </div>
    @endif
</x-layouts::app>
