<?php

use App\Actions\DeleteUnusedRecord;
use App\Models\AcademicYear;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

new #[Title('Tahun Ajaran')] class extends Component {
    public ?int $editingId = null;

    public string $name = '';

    public string $starts_on = '';

    public string $ends_on = '';

    /**
     * @return Collection<int, AcademicYear>
     */
    #[Computed]
    public function academicYears(): Collection
    {
        return AcademicYear::query()->withCount('classrooms')->orderByDesc('starts_on')->get();
    }

    public function create(): void
    {
        $this->authorize('manage-master-data');

        $this->resetValidation();
        $this->editingId = null;

        $latestYear = AcademicYear::query()->orderByDesc('starts_on')->first();
        $startYear = $latestYear ? $latestYear->starts_on->year + 1 : now()->year;

        $this->name = $startYear.'/'.($startYear + 1);
        $this->starts_on = $startYear.'-07-01';
        $this->ends_on = ($startYear + 1).'-06-30';

        $this->dispatch('open-modal', name: 'academic-year-form');
    }

    public function edit(AcademicYear $academicYear): void
    {
        $this->authorize('manage-master-data');

        $this->resetValidation();
        $this->editingId = $academicYear->id;
        $this->name = $academicYear->name;
        $this->starts_on = $academicYear->starts_on->toDateString();
        $this->ends_on = $academicYear->ends_on->toDateString();

        $this->dispatch('open-modal', name: 'academic-year-form');
    }

    public function save(): void
    {
        $this->authorize('manage-master-data');

        $validated = $this->validate([
            'name' => ['required', 'string', 'regex:/^\d{4}\/\d{4}$/', Rule::unique(AcademicYear::class)->ignore($this->editingId)],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
        ], [
            'name.regex' => 'Format tahun ajaran harus seperti 2026/2027.',
        ], [
            'name' => 'nama tahun ajaran',
            'starts_on' => 'tanggal mulai',
            'ends_on' => 'tanggal selesai',
        ]);

        $academicYear = AcademicYear::query()->updateOrCreate(['id' => $this->editingId], $validated);

        ActivityLog::record(
            $this->editingId ? 'academic_year.updated' : 'academic_year.created',
            ($this->editingId ? 'Mengubah' : 'Menambah').' tahun ajaran '.$academicYear->name,
            $academicYear,
        );

        $this->dispatch('close-modal', name: 'academic-year-form');
        $this->dispatch('toast', variant: 'success', text: 'Tahun ajaran disimpan.');
        unset($this->academicYears);
    }

    public function activate(AcademicYear $academicYear): void
    {
        $this->authorize('manage-master-data');

        $academicYear->activate();

        ActivityLog::record('academic_year.activated', 'Mengaktifkan tahun ajaran '.$academicYear->name, $academicYear);

        $this->dispatch('toast', variant: 'success', text: 'Tahun ajaran '.$academicYear->name.' sekarang aktif.');
        unset($this->academicYears);
    }
    public function delete(AcademicYear $academicYear, DeleteUnusedRecord $deleteUnusedRecord): void
    {
        $this->authorize('manage-master-data');

        try {
            $deleteUnusedRecord->handle($academicYear);
        } catch (ValidationException $exception) {
            $this->dispatch('toast', variant: 'error', text: $exception->getMessage());

            return;
        }

        ActivityLog::record('academic_year.deleted', 'Menghapus tahun ajaran '.$academicYear->name, properties: ['academic_year_id' => $academicYear->id]);

        $this->dispatch('toast', variant: 'success', text: 'Tahun ajaran dihapus.');
        unset($this->academicYears);
    }
}; ?>

<div>
    <x-page-header title="Tahun Ajaran" description="Hanya satu tahun ajaran yang aktif. Kelas dan penempatan siswa mengikuti tahun ajaran aktif.">
        <x-slot:actions>
            <button type="button" class="btn btn-primary" wire:click="create">
                <x-icon name="plus" class="size-4" /> Tambah tahun ajaran
            </button>
        </x-slot:actions>
    </x-page-header>

    <div class="overflow-x-auto rounded-box border border-base-300">
        <table class="table">
            <thead>
                <tr>
                    <th>Tahun ajaran</th>
                    <th>Periode</th>
                    <th class="text-end">Jumlah kelas</th>
                    <th>Status</th>
                    <th class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->academicYears as $academicYear)
                    <tr wire:key="academic-year-{{ $academicYear->id }}">
                        <td class="font-medium">{{ $academicYear->name }}</td>
                        <td>{{ $academicYear->starts_on->translatedFormat('j M Y') }} – {{ $academicYear->ends_on->translatedFormat('j M Y') }}</td>
                        <td class="text-end">{{ $academicYear->classrooms_count }}</td>
                        <td>
                            @if ($academicYear->is_active)
                                <span class="badge badge-success">Aktif</span>
                            @else
                                <span class="badge badge-ghost">Tidak aktif</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <div class="flex justify-end gap-1">
                                @unless ($academicYear->is_active)
                                    <x-confirm-button
                                        :action="'activate('.$academicYear->id.')'"
                                        title="Aktifkan tahun ajaran"
                                        :message="'Aktifkan tahun ajaran '.$academicYear->name.'? Tahun ajaran lain akan dinonaktifkan.'"
                                        confirm-label="Aktifkan"
                                        class="btn btn-sm btn-ghost"
                                    >
                                        <x-icon name="check" class="size-4" /> Aktifkan
                                    </x-confirm-button>
                                @endunless
                                <button type="button" class="btn btn-sm btn-ghost btn-square" wire:click="edit({{ $academicYear->id }})" aria-label="Ubah">
                                    <x-icon name="pencil-square" class="size-4" />
                                </button>
                                <x-confirm-button
                                    :action="'delete('.$academicYear->id.')'"
                                    title="Hapus tahun ajaran"
                                    :message="'Hapus '.$academicYear->name.'? Data yang sudah dipakai di transaksi tidak bisa dihapus, nonaktifkan saja.'"
                                    confirm-label="Hapus"
                                    danger
                                    class="btn btn-sm btn-ghost btn-square text-error"
                                    aria-label="Hapus {{ $academicYear->name }}"
                                >
                                    <x-icon name="trash" class="size-4" />
                                </x-confirm-button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="py-8 text-center text-base-content/60">Belum ada tahun ajaran.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-modal name="academic-year-form" :title="$editingId ? 'Ubah tahun ajaran' : 'Tambah tahun ajaran'">
        <form wire:submit="save" class="space-y-4">
            <x-input wire:model="name" label="Nama tahun ajaran" placeholder="2026/2027" required />
            <div class="grid gap-4 sm:grid-cols-2">
                <x-input wire:model="starts_on" label="Tanggal mulai" type="date" required />
                <x-input wire:model="ends_on" label="Tanggal selesai" type="date" required />
            </div>

            <div class="modal-action">
                <button type="button" class="btn" x-on:click="$el.closest('dialog').close()">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </x-modal>
</div>
