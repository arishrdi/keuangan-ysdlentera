<?php

use App\Models\AcademicYear;
use App\Models\ActivityLog;
use App\Models\Classroom;
use App\Models\Level;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Kelas')] class extends Component {
    #[Url(as: 'tahun')]
    public ?int $academicYearId = null;

    public ?int $editingId = null;

    public ?int $level_id = null;

    public string $name = '';

    public ?int $grade = null;

    public string $homeroom_teacher = '';

    public function mount(): void
    {
        $this->academicYearId ??= AcademicYear::current()?->id;
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
     * @return Collection<int, Level>
     */
    #[Computed]
    public function levels(): Collection
    {
        return Level::query()->ordered()->get();
    }

    #[Computed]
    public function activeLevel(): ?Level
    {
        return Level::active();
    }

    /**
     * @return Collection<int, Classroom>
     */
    #[Computed]
    public function classrooms(): Collection
    {
        return Classroom::query()
            ->with('level')
            ->withCount('enrollments')
            ->where('academic_year_id', $this->academicYearId)
            ->when($this->activeLevel, fn ($query, Level $level) => $query->whereBelongsTo($level))
            ->orderBy(Level::query()->select('sort_order')->whereColumn('levels.id', 'classrooms.level_id'))
            ->orderBy('grade')
            ->orderBy('name')
            ->get();
    }

    public function create(): void
    {
        $this->authorize('manage-master-data');

        $this->resetValidation();
        $this->reset('editingId', 'name', 'grade', 'homeroom_teacher');
        $this->level_id = $this->activeLevel?->id;

        $this->dispatch('open-modal', name: 'classroom-form');
    }

    public function edit(Classroom $classroom): void
    {
        $this->authorize('manage-master-data');

        $this->resetValidation();
        $this->editingId = $classroom->id;
        $this->level_id = $classroom->level_id;
        $this->name = $classroom->name;
        $this->grade = $classroom->grade;
        $this->homeroom_teacher = (string) $classroom->homeroom_teacher;

        $this->dispatch('open-modal', name: 'classroom-form');
    }

    public function save(): void
    {
        $this->authorize('manage-master-data');

        $academicYearId = $this->editingId
            ? Classroom::query()->findOrFail($this->editingId)->academic_year_id
            : $this->academicYearId;

        if ($academicYearId === null) {
            $this->addError('name', 'Buat dan pilih tahun ajaran terlebih dahulu.');

            return;
        }

        $validated = $this->validate([
            'level_id' => ['required', 'integer', Rule::exists(Level::class, 'id')],
            'name' => [
                'required', 'string', 'max:50',
                Rule::unique(Classroom::class)
                    ->where('level_id', $this->level_id)
                    ->where('academic_year_id', $academicYearId)
                    ->ignore($this->editingId),
            ],
            'grade' => ['required', 'integer', 'min:0', 'max:12'],
            'homeroom_teacher' => ['nullable', 'string', 'max:255'],
        ], [], [
            'level_id' => 'jenjang',
            'name' => 'nama kelas',
            'grade' => 'tingkat',
            'homeroom_teacher' => 'wali kelas',
        ]);

        $classroom = Classroom::query()->updateOrCreate(
            ['id' => $this->editingId],
            [...$validated, 'academic_year_id' => $academicYearId, 'homeroom_teacher' => $validated['homeroom_teacher'] ?: null],
        );

        ActivityLog::record(
            $this->editingId ? 'classroom.updated' : 'classroom.created',
            ($this->editingId ? 'Mengubah' : 'Menambah').' kelas '.$classroom->name,
            $classroom,
        );

        $this->dispatch('close-modal', name: 'classroom-form');
        $this->dispatch('toast', variant: 'success', text: 'Kelas disimpan.');
        unset($this->classrooms);
    }

    public function delete(Classroom $classroom): void
    {
        $this->authorize('manage-master-data');

        if ($classroom->enrollments()->exists()) {
            $this->dispatch('toast', variant: 'error', text: 'Kelas yang sudah berisi siswa tidak bisa dihapus.');

            return;
        }

        $classroom->delete();

        ActivityLog::record('classroom.deleted', 'Menghapus kelas '.$classroom->name, properties: ['classroom_id' => $classroom->id]);

        $this->dispatch('toast', variant: 'success', text: 'Kelas dihapus.');
        unset($this->classrooms);
    }
}; ?>

<div>
    <x-page-header title="Kelas" :description="'Daftar kelas '.($this->activeLevel ? 'jenjang '.$this->activeLevel->name : 'semua jenjang').'.'">
        <x-slot:actions>
            <select class="select select-sm w-40" wire:model.live="academicYearId" aria-label="Tahun ajaran">
                @foreach ($this->academicYears as $academicYear)
                    <option value="{{ $academicYear->id }}" wire:key="year-option-{{ $academicYear->id }}">
                        {{ $academicYear->name }}{{ $academicYear->is_active ? ' (aktif)' : '' }}
                    </option>
                @endforeach
            </select>
            <button type="button" class="btn btn-primary" wire:click="create" @disabled($academicYearId === null)>
                <x-icon name="plus" class="size-4" /> Tambah kelas
            </button>
        </x-slot:actions>
    </x-page-header>

    @if ($academicYearId === null)
        <div role="alert" class="alert alert-warning">
            <span>Belum ada tahun ajaran. Tambahkan di menu <a href="{{ route('admin.academic-years') }}" class="link" wire:navigate>Tahun Ajaran</a>.</span>
        </div>
    @else
        <div class="overflow-x-auto rounded-box border border-base-300">
            <table class="table">
                <thead>
                    <tr>
                        <th>Jenjang</th>
                        <th>Kelas</th>
                        <th class="text-end">Tingkat</th>
                        <th>Wali kelas</th>
                        <th class="text-end">Jumlah siswa</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->classrooms as $classroom)
                        <tr wire:key="classroom-{{ $classroom->id }}">
                            <td><span class="badge badge-outline">{{ $classroom->level->code }}</span></td>
                            <td class="font-medium">{{ $classroom->name }}</td>
                            <td class="text-end">{{ $classroom->grade }}</td>
                            <td>{{ $classroom->homeroom_teacher ?? '-' }}</td>
                            <td class="text-end">{{ $classroom->enrollments_count }}</td>
                            <td class="text-end">
                                <div class="flex justify-end gap-1">
                                    <a href="{{ route('students.index', ['kelas' => $classroom->id]) }}" class="btn btn-sm btn-ghost" wire:navigate>Siswa</a>
                                    <button type="button" class="btn btn-sm btn-ghost btn-square" wire:click="edit({{ $classroom->id }})" aria-label="Ubah">
                                        <x-icon name="pencil-square" class="size-4" />
                                    </button>
                                    <x-confirm-button
                                        :action="'delete('.$classroom->id.')'"
                                        title="Hapus kelas"
                                        :message="'Hapus kelas '.$classroom->name.'? Kelas yang sudah berisi siswa tidak bisa dihapus.'"
                                        confirm-label="Hapus"
                                        danger
                                        class="btn btn-sm btn-ghost btn-square text-error"
                                        aria-label="Hapus"
                                    >
                                        <x-icon name="trash" class="size-4" />
                                    </x-confirm-button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-8 text-center text-base-content/60">Belum ada kelas pada tahun ajaran ini.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    <x-modal name="classroom-form" :title="$editingId ? 'Ubah kelas' : 'Tambah kelas'">
        <form wire:submit="save" class="space-y-4">
            <x-select wire:model="level_id" label="Jenjang" placeholder="Pilih jenjang" required>
                @foreach ($this->levels as $level)
                    <option value="{{ $level->id }}" wire:key="level-option-{{ $level->id }}">{{ $level->name }}</option>
                @endforeach
            </x-select>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-input wire:model="name" label="Nama kelas" placeholder="contoh: 1A, TK B" required />
                <x-input wire:model="grade" label="Tingkat" type="number" min="0" max="12" placeholder="1" required />
            </div>
            <x-input wire:model="homeroom_teacher" label="Wali kelas (opsional)" />

            <div class="modal-action">
                <button type="button" class="btn" x-on:click="$el.closest('dialog').close()">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </x-modal>
</div>
