<?php

use App\Enums\Gender;
use App\Enums\StudentStatus;
use App\Models\AcademicYear;
use App\Models\ActivityLog;
use App\Models\Classroom;
use App\Models\Level;
use App\Models\Student;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Data Siswa')] class extends Component {
    use WithPagination;

    #[Url(as: 'cari')]
    public string $search = '';

    #[Url]
    public string $status = 'aktif';

    #[Url(as: 'kelas')]
    public ?int $classroomFilter = null;

    public ?int $editingId = null;

    public ?int $level_id = null;

    public string $nis = '';

    public string $nisn = '';

    public string $name = '';

    public string $gender = '';

    public string $birth_place = '';

    public string $birth_date = '';

    public string $address = '';

    public ?int $classroom_id = null;

    public string $student_status = 'aktif';

    public string $status_changed_on = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'classroomFilter'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('manage-master-data');
    }

    #[Computed]
    public function activeLevel(): ?Level
    {
        return Level::active();
    }

    #[Computed]
    public function academicYear(): ?AcademicYear
    {
        return AcademicYear::current();
    }

    /**
     * @return Collection<int, Level>
     */
    #[Computed]
    public function levels(): Collection
    {
        return Level::query()->ordered()->get();
    }

    /**
     * Classrooms of the active academic year, optionally limited to one level.
     *
     * @return Collection<int, Classroom>
     */
    public function classroomsFor(?int $levelId): Collection
    {
        if ($this->academicYear === null) {
            return new Collection;
        }

        return Classroom::query()
            ->with('level')
            ->whereBelongsTo($this->academicYear)
            ->when($levelId, fn ($query) => $query->where('level_id', $levelId))
            ->orderBy('grade')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return LengthAwarePaginator<int, Student>
     */
    #[Computed]
    public function students(): LengthAwarePaginator
    {
        return Student::query()
            ->with(['level', 'currentEnrollment.classroom'])
            ->withCount('guardians')
            ->when($this->activeLevel, fn ($query, Level $level) => $query->whereBelongsTo($level))
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->when($this->classroomFilter, fn ($query, int $classroomId) => $query->whereHas(
                'enrollments',
                fn ($query) => $query->where('classroom_id', $classroomId),
            ))
            ->when($this->search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', '%'.$this->search.'%')
                ->orWhere('nis', 'like', '%'.$this->search.'%')
                ->orWhere('nisn', 'like', '%'.$this->search.'%')))
            ->orderBy('name')
            ->paginate(25);
    }

    public function create(): void
    {
        $this->authorize('manage-master-data');

        $this->resetValidation();
        $this->reset('editingId', 'nis', 'nisn', 'name', 'gender', 'birth_place', 'birth_date', 'address', 'status_changed_on');
        $this->level_id = $this->activeLevel?->id;
        $this->classroom_id = $this->classroomFilter;
        $this->student_status = StudentStatus::Active->value;

        $this->dispatch('open-modal', name: 'student-form');
    }

    public function edit(Student $student): void
    {
        $this->authorize('manage-master-data');

        $this->resetValidation();
        $this->editingId = $student->id;
        $this->level_id = $student->level_id;
        $this->nis = (string) $student->nis;
        $this->nisn = (string) $student->nisn;
        $this->name = $student->name;
        $this->gender = $student->gender->value;
        $this->birth_place = (string) $student->birth_place;
        $this->birth_date = (string) $student->birth_date?->toDateString();
        $this->address = (string) $student->address;
        $this->classroom_id = $student->currentEnrollment?->classroom_id;
        $this->student_status = $student->status->value;
        $this->status_changed_on = (string) $student->status_changed_on?->toDateString();

        $this->dispatch('open-modal', name: 'student-form');
    }

    public function updatedLevelId(): void
    {
        $this->classroom_id = null;
    }

    public function save(): void
    {
        $this->authorize('manage-master-data');

        $validated = $this->validate([
            'level_id' => ['required', 'integer', Rule::exists(Level::class, 'id')],
            'nis' => ['nullable', 'string', 'max:30', Rule::unique(Student::class)->ignore($this->editingId)],
            'nisn' => ['nullable', 'digits_between:5,20', Rule::unique(Student::class)->ignore($this->editingId)],
            'name' => ['required', 'string', 'max:255'],
            'gender' => ['required', Rule::enum(Gender::class)],
            'birth_place' => ['nullable', 'string', 'max:100'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'address' => ['nullable', 'string', 'max:1000'],
            'classroom_id' => [
                'nullable', 'integer',
                Rule::exists(Classroom::class, 'id')
                    ->where('level_id', $this->level_id)
                    ->where('academic_year_id', $this->academicYear?->id),
            ],
            'student_status' => ['required', Rule::enum(StudentStatus::class)],
            'status_changed_on' => ['nullable', 'date'],
        ], [
            'classroom_id.exists' => 'Kelas harus sesuai jenjang siswa dan tahun ajaran aktif.',
        ], [
            'level_id' => 'jenjang',
            'name' => 'nama',
            'gender' => 'jenis kelamin',
            'birth_place' => 'tempat lahir',
            'birth_date' => 'tanggal lahir',
            'address' => 'alamat',
            'classroom_id' => 'kelas',
            'student_status' => 'status',
            'status_changed_on' => 'tanggal perubahan status',
        ]);

        $status = StudentStatus::from($validated['student_status']);
        $original = $this->editingId ? Student::query()->findOrFail($this->editingId) : null;
        $statusChanged = $original === null || $original->status !== $status;

        $student = DB::transaction(function () use ($validated, $status, $statusChanged, $original): Student {
            $student = Student::query()->updateOrCreate(['id' => $this->editingId], [
                'level_id' => $validated['level_id'],
                'nis' => $validated['nis'] ?: null,
                'nisn' => $validated['nisn'] ?: null,
                'name' => $validated['name'],
                'gender' => $validated['gender'],
                'birth_place' => $validated['birth_place'] ?: null,
                'birth_date' => $validated['birth_date'] ?: null,
                'address' => $validated['address'] ?: null,
                'status' => $status,
                'status_changed_on' => match (true) {
                    $status === StudentStatus::Active => null,
                    filled($validated['status_changed_on']) => $validated['status_changed_on'],
                    $statusChanged => now()->toDateString(),
                    default => $original?->status_changed_on,
                },
            ]);

            if ($validated['classroom_id'] !== null) {
                $student->enrollIn(Classroom::query()->findOrFail($validated['classroom_id']));
            } elseif ($this->academicYear !== null) {
                $student->enrollments()->whereBelongsTo($this->academicYear)->delete();
            }

            return $student;
        });

        ActivityLog::record(
            $original ? 'student.updated' : 'student.created',
            ($original ? 'Mengubah' : 'Menambah').' data siswa '.$student->name,
            $student,
            array_filter([
                'status' => $statusChanged && $original ? ['from' => $original->status->value, 'to' => $status->value] : null,
                'classroom_id' => $validated['classroom_id'],
            ]),
        );

        $this->dispatch('close-modal', name: 'student-form');
        $this->dispatch('toast', variant: 'success', text: 'Data siswa disimpan.');
        unset($this->students);
    }
}; ?>

<div>
    <x-page-header
        :title="$this->canManage ? 'Data Siswa' : 'Data Sekolah'"
        :description="($this->activeLevel ? 'Jenjang '.$this->activeLevel->name : 'Semua jenjang').' · Tahun ajaran '.($this->academicYear?->name ?? '-')"
    >
        <x-slot:actions>
            @if ($this->canManage)
                <button type="button" class="btn btn-primary" wire:click="create">
                    <x-icon name="plus" class="size-4" /> Tambah siswa
                </button>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 flex flex-wrap gap-2">
        <label class="input input-sm w-64">
            <x-icon name="magnifying-glass" class="size-4 opacity-60" />
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Cari nama / NIS / NISN" />
        </label>
        <select class="select select-sm w-40" wire:model.live="status" aria-label="Status">
            <option value="">Semua status</option>
            @foreach (\App\Enums\StudentStatus::cases() as $statusOption)
                <option value="{{ $statusOption->value }}" wire:key="status-{{ $statusOption->value }}">{{ $statusOption->label() }}</option>
            @endforeach
        </select>
        <select class="select select-sm w-48" wire:model.live="classroomFilter" aria-label="Kelas">
            <option value="">Semua kelas</option>
            @foreach ($this->classroomsFor($this->activeLevel?->id) as $classroom)
                <option value="{{ $classroom->id }}" wire:key="filter-classroom-{{ $classroom->id }}">{{ $classroom->level->code }} · {{ $classroom->name }}</option>
            @endforeach
        </select>
    </div>

    <div class="overflow-x-auto rounded-box border border-base-300">
        <table class="table">
            <thead>
                <tr>
                    <th>NIS</th>
                    <th>Nama</th>
                    <th>L/P</th>
                    <th>Jenjang</th>
                    <th>Kelas</th>
                    <th>Status</th>
                    <th class="text-end">Wali</th>
                    <th class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->students as $student)
                    <tr wire:key="student-{{ $student->id }}">
                        <td class="text-sm">{{ $student->nis ?? '-' }}</td>
                        <td class="font-medium">
                            <a href="{{ route('students.show', $student) }}" class="link link-hover" wire:navigate>{{ $student->name }}</a>
                        </td>
                        <td>{{ $student->gender->value }}</td>
                        <td><span class="badge badge-outline">{{ $student->level->code }}</span></td>
                        <td>{{ $student->currentEnrollment?->classroom->name ?? '-' }}</td>
                        <td><span class="badge {{ $student->status->badgeClass() }}">{{ $student->status->label() }}</span></td>
                        <td class="text-end">{{ $student->guardians_count }}</td>
                        <td class="text-end">
                            @if ($this->canManage)
                                <button type="button" class="btn btn-sm btn-ghost btn-square" wire:click="edit({{ $student->id }})" aria-label="Ubah">
                                    <x-icon name="pencil-square" class="size-4" />
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="py-8 text-center text-base-content/60">Tidak ada siswa.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->students->links() }}</div>

    @if ($this->canManage)
        <x-modal name="student-form" :title="$editingId ? 'Ubah data siswa' : 'Tambah siswa'" max-width="max-w-2xl">
            <form wire:submit="save" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-input wire:model="name" label="Nama lengkap" required class="sm:col-span-2" />
                    <x-select wire:model.live="level_id" label="Jenjang" placeholder="Pilih jenjang" required>
                        @foreach ($this->levels as $level)
                            <option value="{{ $level->id }}" wire:key="form-level-{{ $level->id }}">{{ $level->name }}</option>
                        @endforeach
                    </x-select>
                    <x-select wire:model="classroom_id" :label="'Kelas '.($this->academicYear?->name ?? '')" placeholder="Belum ditempatkan">
                        @foreach ($this->classroomsFor($level_id) as $classroom)
                            <option value="{{ $classroom->id }}" wire:key="form-classroom-{{ $classroom->id }}">{{ $classroom->name }}</option>
                        @endforeach
                    </x-select>
                    <x-input wire:model="nis" label="NIS" />
                    <x-input wire:model="nisn" label="NISN" inputmode="numeric" />
                    <x-select wire:model="gender" label="Jenis kelamin" placeholder="Pilih" required>
                        @foreach (\App\Enums\Gender::cases() as $genderOption)
                            <option value="{{ $genderOption->value }}" wire:key="gender-{{ $genderOption->value }}">{{ $genderOption->label() }}</option>
                        @endforeach
                    </x-select>
                    <div class="grid grid-cols-2 gap-2">
                        <x-input wire:model="birth_place" label="Tempat lahir" />
                        <x-input wire:model="birth_date" label="Tanggal lahir" type="date" />
                    </div>
                </div>
                <x-textarea wire:model="address" label="Alamat" />

                @if ($editingId)
                    <div class="grid gap-4 rounded-box bg-base-200 p-4 sm:grid-cols-2">
                        <x-select wire:model.live="student_status" label="Status siswa" required>
                            @foreach (\App\Enums\StudentStatus::cases() as $statusOption)
                                <option value="{{ $statusOption->value }}" wire:key="form-status-{{ $statusOption->value }}">{{ $statusOption->label() }}</option>
                            @endforeach
                        </x-select>
                        @if ($student_status !== 'aktif')
                            <x-input wire:model="status_changed_on" label="Tanggal perubahan status" type="date" />
                        @endif
                    </div>
                @endif

                <div class="modal-action">
                    <button type="button" class="btn" x-on:click="$el.closest('dialog').close()">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
