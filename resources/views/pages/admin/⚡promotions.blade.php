<?php

use App\Enums\StudentStatus;
use App\Models\AcademicYear;
use App\Models\ActivityLog;
use App\Models\Classroom;
use App\Models\Level;
use App\Models\Student;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Kenaikan Kelas')] class extends Component {
    public ?int $sourceYearId = null;

    public ?int $sourceClassroomId = null;

    /**
     * @var array<int, int>
     */
    public array $selectedStudentIds = [];

    public string $mode = 'promote';

    public ?int $targetClassroomId = null;

    public string $graduatedOn = '';

    public function mount(): void
    {
        $activeYear = AcademicYear::current();

        $this->sourceYearId = AcademicYear::query()
            ->when($activeYear, fn ($query) => $query->where('starts_on', '<', $activeYear->starts_on))
            ->orderByDesc('starts_on')
            ->value('id') ?? $activeYear?->id;

        $this->graduatedOn = now()->toDateString();
    }

    #[Computed]
    public function activeYear(): ?AcademicYear
    {
        return AcademicYear::current();
    }

    #[Computed]
    public function activeLevel(): ?Level
    {
        return Level::active();
    }

    /**
     * @return Collection<int, AcademicYear>
     */
    #[Computed]
    public function academicYears(): Collection
    {
        return AcademicYear::query()->orderByDesc('starts_on')->get();
    }

    /**
     * @return Collection<int, Classroom>
     */
    #[Computed]
    public function sourceClassrooms(): Collection
    {
        return $this->classroomsIn($this->sourceYearId);
    }

    /**
     * @return Collection<int, Classroom>
     */
    #[Computed]
    public function targetClassrooms(): Collection
    {
        return Classroom::query()
            ->with('level')
            ->where('academic_year_id', $this->activeYear?->id)
            ->orderBy(Level::query()->select('sort_order')->whereColumn('levels.id', 'classrooms.level_id'))
            ->orderBy('grade')
            ->orderBy('name')
            ->get();
    }

    /**
     * Active students placed in the source classroom.
     *
     * @return Collection<int, Student>
     */
    #[Computed]
    public function students(): Collection
    {
        if ($this->sourceClassroomId === null) {
            return new Collection;
        }

        return Student::query()
            ->active()
            ->with('currentEnrollment.classroom')
            ->whereHas('enrollments', fn ($query) => $query->where('classroom_id', $this->sourceClassroomId))
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Classroom>
     */
    private function classroomsIn(?int $academicYearId): Collection
    {
        return Classroom::query()
            ->with('level')
            ->where('academic_year_id', $academicYearId)
            ->when($this->activeLevel, fn ($query, Level $level) => $query->whereBelongsTo($level))
            ->orderBy('grade')
            ->orderBy('name')
            ->get();
    }

    public function updatedSourceYearId(): void
    {
        $this->sourceClassroomId = null;
        $this->selectedStudentIds = [];
    }

    public function updatedSourceClassroomId(): void
    {
        $this->selectedStudentIds = $this->students->modelKeys();
    }

    public function process(): void
    {
        $this->authorize('manage-master-data');

        $validated = $this->validate([
            'sourceClassroomId' => ['required', 'integer', Rule::exists(Classroom::class, 'id')],
            'selectedStudentIds' => ['required', 'array', 'min:1'],
            'selectedStudentIds.*' => ['integer'],
            'mode' => ['required', Rule::in(['promote', 'graduate'])],
            'targetClassroomId' => [
                Rule::requiredIf($this->mode === 'promote'), 'nullable', 'integer',
                Rule::exists(Classroom::class, 'id')->where('academic_year_id', $this->activeYear?->id),
            ],
            'graduatedOn' => [Rule::requiredIf($this->mode === 'graduate'), 'nullable', 'date'],
        ], [
            'selectedStudentIds.required' => 'Pilih minimal satu siswa.',
            'targetClassroomId.exists' => 'Kelas tujuan harus berada di tahun ajaran aktif.',
        ], [
            'sourceClassroomId' => 'kelas asal',
            'targetClassroomId' => 'kelas tujuan',
            'graduatedOn' => 'tanggal lulus',
        ]);

        $students = $this->students->whereIn('id', $validated['selectedStudentIds']);
        $sourceClassroom = Classroom::query()->findOrFail($validated['sourceClassroomId']);

        if ($validated['mode'] === 'graduate') {
            $students->each->ensureSavingsAreSettled('selectedStudentIds');
        }

        DB::transaction(function () use ($students, $validated, $sourceClassroom): void {
            if ($validated['mode'] === 'promote') {
                $target = Classroom::query()->findOrFail($validated['targetClassroomId']);

                foreach ($students as $student) {
                    $student->update(['level_id' => $target->level_id]);
                    $student->enrollIn($target);
                }

                ActivityLog::record('student.promoted', 'Memindahkan '.$students->count().' siswa dari kelas '.$sourceClassroom->name.' ke kelas '.$target->name, $target, [
                    'source_classroom_id' => $sourceClassroom->id,
                    'student_ids' => $students->modelKeys(),
                ]);

                return;
            }

            foreach ($students as $student) {
                $student->update(['status' => StudentStatus::Graduated, 'status_changed_on' => $validated['graduatedOn']]);
            }

            ActivityLog::record('student.graduated', 'Meluluskan '.$students->count().' siswa dari kelas '.$sourceClassroom->name, $sourceClassroom, [
                'student_ids' => $students->modelKeys(),
            ]);
        });

        $this->dispatch('toast', variant: 'success', text: $students->count().' siswa berhasil diproses.');
        unset($this->students);
        $this->selectedStudentIds = [];
    }
}; ?>

<div>
    <x-page-header title="Kenaikan Kelas" description="Pindahkan siswa dari kelas tahun lalu ke kelas tahun ajaran aktif, atau tandai siswa sebagai lulus." />

    @if ($this->activeYear === null)
        <div role="alert" class="alert alert-warning">
            <span>Belum ada tahun ajaran aktif. Aktifkan tahun ajaran baru terlebih dahulu.</span>
        </div>
    @else
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="card border border-base-300 lg:col-span-2">
                <div class="card-body">
                    <h2 class="card-title text-base">1. Pilih kelas asal</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-select wire:model.live="sourceYearId" label="Tahun ajaran asal">
                            @foreach ($this->academicYears as $academicYear)
                                <option value="{{ $academicYear->id }}" wire:key="source-year-{{ $academicYear->id }}">{{ $academicYear->name }}</option>
                            @endforeach
                        </x-select>
                        <x-select wire:model.live="sourceClassroomId" label="Kelas asal" placeholder="Pilih kelas">
                            @foreach ($this->sourceClassrooms as $classroom)
                                <option value="{{ $classroom->id }}" wire:key="source-classroom-{{ $classroom->id }}">{{ $classroom->level->code }} · {{ $classroom->name }}</option>
                            @endforeach
                        </x-select>
                    </div>

                    @error('selectedStudentIds') <p class="text-sm text-error">{{ $message }}</p> @enderror

                    @if ($sourceClassroomId)
                        <div class="mt-2 overflow-x-auto rounded-box border border-base-300">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th class="w-10"></th>
                                        <th>Nama</th>
                                        <th>NIS</th>
                                        <th>Kelas tahun aktif</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($this->students as $student)
                                        <tr wire:key="promotion-student-{{ $student->id }}">
                                            <td><input type="checkbox" class="checkbox checkbox-sm" value="{{ $student->id }}" wire:model="selectedStudentIds" aria-label="Pilih {{ $student->name }}" /></td>
                                            <td>{{ $student->name }}</td>
                                            <td>{{ $student->nis ?? '-' }}</td>
                                            <td>{{ $student->currentEnrollment?->classroom->name ?? '-' }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4" class="text-center text-base-content/60">Tidak ada siswa aktif di kelas ini.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>

            <div class="card border border-base-300">
                <div class="card-body">
                    <h2 class="card-title text-base">2. Tindakan</h2>
                    <form wire:submit="process" class="space-y-4">
                        <div class="flex flex-col gap-2">
                            <label class="label cursor-pointer gap-2 text-base-content">
                                <input type="radio" class="radio radio-primary radio-sm" value="promote" wire:model.live="mode" />
                                <span>Naik / pindah ke kelas {{ $this->activeYear->name }}</span>
                            </label>
                            <label class="label cursor-pointer gap-2 text-base-content">
                                <input type="radio" class="radio radio-primary radio-sm" value="graduate" wire:model.live="mode" />
                                <span>Tandai lulus</span>
                            </label>
                        </div>

                        @if ($mode === 'promote')
                            <x-select wire:model="targetClassroomId" label="Kelas tujuan" placeholder="Pilih kelas tujuan">
                                @foreach ($this->targetClassrooms as $classroom)
                                    <option value="{{ $classroom->id }}" wire:key="target-classroom-{{ $classroom->id }}">{{ $classroom->level->code }} · {{ $classroom->name }}</option>
                                @endforeach
                            </x-select>
                        @else
                            <x-input wire:model="graduatedOn" label="Tanggal lulus" type="date" />
                        @endif

                        <button
                            type="submit"
                            class="btn btn-primary w-full"
                            wire:confirm="Proses {{ count($selectedStudentIds) }} siswa terpilih?"
                            @disabled($selectedStudentIds === [])
                        >
                            Proses {{ count($selectedStudentIds) }} siswa
                        </button>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>
