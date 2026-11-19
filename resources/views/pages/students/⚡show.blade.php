<?php

use App\Models\ActivityLog;
use App\Models\Student;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Student $student;

    public function mount(Student $student): void
    {
        $this->student = $student->load([
            'level',
            'guardians' => fn ($query) => $query->orderBy('name'),
            'enrollments' => fn ($query) => $query->with(['classroom', 'academicYear'])
                ->orderByDesc(\App\Models\AcademicYear::query()->select('starts_on')->whereColumn('academic_years.id', 'enrollments.academic_year_id')),
        ]);
    }

    /**
     * @return Collection<int, ActivityLog>
     */
    #[Computed]
    public function activities(): Collection
    {
        return ActivityLog::query()
            ->with('user')
            ->whereMorphedTo('subject', $this->student)
            ->latest('id')
            ->limit(20)
            ->get();
    }

    public function render(): mixed
    {
        return $this->view()->title($this->student->name);
    }
}; ?>

<div>
    <a href="{{ route('students.index') }}" class="btn btn-ghost btn-sm mb-4" wire:navigate>
        <x-icon name="arrow-left" class="size-4" /> Kembali ke daftar siswa
    </a>

    <x-page-header :title="$student->name" :description="$student->level->name.' · NIS '.($student->nis ?? '-')">
        <x-slot:actions>
            <span class="badge badge-lg {{ $student->status->badgeClass() }}">{{ $student->status->label() }}</span>
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="card border border-base-300 lg:col-span-2">
            <div class="card-body">
                <h2 class="card-title text-base">Identitas siswa</h2>
                <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-base-content/60">NISN</dt><dd>{{ $student->nisn ?? '-' }}</dd></div>
                    <div><dt class="text-base-content/60">Jenis kelamin</dt><dd>{{ $student->gender->label() }}</dd></div>
                    <div><dt class="text-base-content/60">Tempat, tanggal lahir</dt><dd>{{ $student->birth_place ?? '-' }}, {{ $student->birth_date?->translatedFormat('j F Y') ?? '-' }}</dd></div>
                    <div><dt class="text-base-content/60">Perubahan status</dt><dd>{{ $student->status_changed_on?->translatedFormat('j F Y') ?? '-' }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-base-content/60">Alamat</dt><dd>{{ $student->address ?? '-' }}</dd></div>
                </dl>
            </div>
        </div>

        <div class="card border border-base-300">
            <div class="card-body">
                <h2 class="card-title text-base">Wali murid</h2>
                <ul class="space-y-3 text-sm">
                    @forelse ($student->guardians as $guardian)
                        <li wire:key="guardian-{{ $guardian->id }}">
                            <div class="font-medium">{{ $guardian->name }} <span class="badge badge-ghost badge-sm">{{ $guardian->pivot->relationship }}</span></div>
                            <div class="text-base-content/60">{{ $guardian->phone }} {{ $guardian->email ? '· '.$guardian->email : '' }}</div>
                        </li>
                    @empty
                        <li class="text-base-content/60">Belum ada wali terhubung.</li>
                    @endforelse
                </ul>
            </div>
        </div>

        <div class="card border border-base-300 lg:col-span-2">
            <div class="card-body">
                <h2 class="card-title text-base">Riwayat kelas</h2>
                <table class="table table-sm">
                    <thead><tr><th>Tahun ajaran</th><th>Kelas</th><th>Wali kelas</th></tr></thead>
                    <tbody>
                        @forelse ($student->enrollments as $enrollment)
                            <tr wire:key="enrollment-{{ $enrollment->id }}">
                                <td>{{ $enrollment->academicYear->name }}{!! $enrollment->academicYear->is_active ? ' <span class="badge badge-success badge-sm">aktif</span>' : '' !!}</td>
                                <td>{{ $enrollment->classroom->name }}</td>
                                <td>{{ $enrollment->classroom->homeroom_teacher ?? '-' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-base-content/60">Belum pernah ditempatkan di kelas.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card border border-base-300">
            <div class="card-body">
                <h2 class="card-title text-base">Riwayat perubahan</h2>
                <ul class="space-y-3 text-sm">
                    @forelse ($this->activities as $activity)
                        <li wire:key="activity-{{ $activity->id }}">
                            <div>{{ $activity->description }}</div>
                            <div class="text-xs text-base-content/60">{{ $activity->user?->name ?? 'Sistem' }} · {{ $activity->created_at->translatedFormat('j M Y H:i') }}</div>
                        </li>
                    @empty
                        <li class="text-base-content/60">Belum ada riwayat.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</div>
