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
            <div class="mt-6 rounded-box border border-dashed border-base-300 p-6 text-sm text-base-content/70">
                Ringkasan keuangan (pemasukan, pengeluaran, kas, pembayaran, dan anggaran) akan tampil di sini setelah modul keuangan selesai dibangun.
            </div>
        @endunless
    @else
        @php
            $children = $user->students()->with(['level', 'currentEnrollment.classroom'])->orderBy('name')->get();
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
                    </div>
                </div>
            @empty
                <div class="rounded-box border border-dashed border-base-300 p-6 text-sm text-base-content/70">
                    Belum ada siswa yang terhubung dengan akun Anda. Hubungi admin sekolah.
                </div>
            @endforelse
        </div>

        <div class="mt-6 rounded-box border border-dashed border-base-300 p-6 text-sm text-base-content/70">
            Tagihan, pembayaran, dan tabungan anak akan tampil di sini setelah modul pembayaran selesai dibangun.
        </div>
    @endif
</x-layouts::app>
