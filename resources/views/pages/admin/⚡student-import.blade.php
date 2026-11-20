<?php

use App\Actions\Students\ImportStudents;
use App\Models\AcademicYear;
use Illuminate\Http\UploadedFile;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

new #[Title('Import Siswa')] class extends Component {
    use WithFileUploads;

    public ?UploadedFile $file = null;

    /**
     * Checked rows of the uploaded file.
     *
     * @var array<int, array{line: int, data: array<string, string|null>, status: string, messages: array<int, string>}>
     */
    public array $rows = [];

    /**
     * @var array{students: int, guardians_created: array<int, array{name: string, phone: string, password: string}>, guardians_linked: int}|null
     */
    public ?array $result = null;

    #[Computed]
    public function academicYear(): ?AcademicYear
    {
        return AcademicYear::current();
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        return collect($this->rows)->countBy('status')->all() + [
            ImportStudents::STATUS_NEW => 0,
            ImportStudents::STATUS_SKIPPED => 0,
            ImportStudents::STATUS_INVALID => 0,
        ];
    }

    public function downloadTemplate(ImportStudents $importStudents): BinaryFileResponse
    {
        $this->authorize('manage-master-data');

        return response()->download($importStudents->template(), 'template-import-siswa.xlsx')->deleteFileAfterSend();
    }

    public function preview(ImportStudents $importStudents): void
    {
        $this->authorize('manage-master-data');

        $this->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:5120']], [], ['file' => 'file Excel']);

        if ($this->academicYear === null) {
            $this->addError('file', 'Aktifkan tahun ajaran terlebih dahulu.');

            return;
        }

        $this->result = null;
        $this->rows = $importStudents->preview($this->file->getRealPath(), $this->academicYear);
        unset($this->counts);

        if ($this->rows === []) {
            $this->addError('file', 'File tidak berisi baris data. Gunakan template yang disediakan.');
        }
    }

    public function import(ImportStudents $importStudents): void
    {
        $this->authorize('manage-master-data');
        abort_if($this->academicYear === null, 422);

        $this->result = $importStudents->import($this->rows, $this->academicYear);

        $this->reset('rows', 'file');
        unset($this->counts);
        $this->dispatch('toast', variant: 'success', text: $this->result['students'].' siswa berhasil diimpor.');
    }

    public function downloadCredentials(ImportStudents $importStudents): ?BinaryFileResponse
    {
        $this->authorize('manage-master-data');

        if (($this->result['guardians_created'] ?? []) === []) {
            return null;
        }

        return response()->download($importStudents->credentials($this->result['guardians_created']), 'akun-wali-baru.xlsx')->deleteFileAfterSend();
    }

    public function startOver(): void
    {
        $this->reset('rows', 'file', 'result');
        $this->resetValidation();
    }
}; ?>

<div>
    <x-page-header title="Import Siswa & Wali" :description="'Masukkan data siswa dan wali sekaligus dari file Excel. Kelas mengikuti tahun ajaran aktif ('.($this->academicYear?->name ?? 'belum diatur').').'">
        <x-slot:actions>
            <button type="button" class="btn btn-outline" wire:click="downloadTemplate">
                <x-icon name="arrow-down-tray" class="size-4" /> Unduh template
            </button>
        </x-slot:actions>
    </x-page-header>

    @if ($result)
        <div role="alert" class="alert alert-success mb-6">
            <div>
                <div class="font-semibold">Import selesai</div>
                <div class="text-sm">
                    {{ $result['students'] }} siswa ditambahkan · {{ count($result['guardians_created']) }} akun wali baru · {{ $result['guardians_linked'] }} siswa dihubungkan ke wali yang sudah ada.
                </div>
            </div>
            <div class="flex gap-2">
                @if ($result['guardians_created'] !== [])
                    <button type="button" class="btn btn-sm" wire:click="downloadCredentials">Unduh akun wali baru</button>
                @endif
                <button type="button" class="btn btn-sm btn-ghost" wire:click="startOver">Import lagi</button>
            </div>
        </div>

        @if ($result['guardians_created'] !== [])
            <div class="mb-6 rounded-box border border-warning p-4 text-sm">
                <p class="mb-2 font-semibold">Password awal wali baru hanya ditampilkan sekarang. Unduh atau catat, lalu bagikan kepada wali murid.</p>
                <div class="overflow-x-auto">
                    <table class="table table-xs">
                        <thead><tr><th>Nama wali</th><th>Login (No. HP)</th><th>Password awal</th></tr></thead>
                        <tbody>
                            @foreach ($result['guardians_created'] as $guardian)
                                <tr wire:key="credential-{{ $guardian['phone'] }}">
                                    <td>{{ $guardian['name'] }}</td>
                                    <td class="font-mono">{{ $guardian['phone'] }}</td>
                                    <td class="font-mono">{{ $guardian['password'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @endif

    @if ($rows === [])
        <form wire:submit="preview" class="card max-w-2xl border border-base-300">
            <div class="card-body gap-4">
                <ol class="list-inside list-decimal space-y-1 text-sm text-base-content/80">
                    <li>Unduh template, isi satu baris per siswa. Kolom wali boleh dikosongkan.</li>
                    <li>Jenjang diisi kode: PAUD, SD, atau SMP. Kelas diisi nama kelas pada tahun ajaran aktif.</li>
                    <li>Wali dicocokkan berdasarkan No. HP. Wali yang belum ada akan dibuatkan akun dengan password acak.</li>
                    <li>Siswa dengan NIS yang sudah terdaftar dilewati, jadi file yang sama aman diunggah ulang.</li>
                </ol>
                <fieldset class="fieldset p-0">
                    <legend class="fieldset-legend pt-0">File Excel (.xlsx, maks. 5 MB)</legend>
                    <input type="file" accept=".xlsx" class="file-input w-full" wire:model="file" aria-label="File Excel" />
                    <div wire:loading wire:target="file" class="label">Mengunggah…</div>
                    @error('file') <p class="label text-error">{{ $message }}</p> @enderror
                </fieldset>
                <div class="card-actions justify-end">
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Periksa file</button>
                </div>
            </div>
        </form>
    @else
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <span class="badge badge-success">{{ $this->counts['baru'] }} siap diimpor</span>
            <span class="badge badge-ghost">{{ $this->counts['dilewati'] }} dilewati</span>
            <span class="badge badge-error">{{ $this->counts['error'] }} error</span>
            <div class="ms-auto flex gap-2">
                <button type="button" class="btn btn-ghost" wire:click="startOver">Ganti file</button>
                <button type="button" class="btn btn-primary" wire:click="import" wire:loading.attr="disabled" @disabled($this->counts['baru'] === 0)
                    wire:confirm="Impor {{ $this->counts['baru'] }} siswa? Baris yang error atau dilewati tidak ikut diimpor.">
                    Impor {{ $this->counts['baru'] }} siswa
                </button>
            </div>
        </div>

        <div class="overflow-x-auto rounded-box border border-base-300">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Baris</th>
                        <th>Status</th>
                        <th>NIS</th>
                        <th>Nama siswa</th>
                        <th>Jenjang · Kelas</th>
                        <th>Wali</th>
                        <th>Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr wire:key="row-{{ $row['line'] }}" @class(['bg-error/5' => $row['status'] === 'error'])>
                            <td>{{ $row['line'] }}</td>
                            <td>
                                <span @class(['badge badge-sm', 'badge-success' => $row['status'] === 'baru', 'badge-ghost' => $row['status'] === 'dilewati', 'badge-error' => $row['status'] === 'error'])>{{ ucfirst($row['status']) }}</span>
                            </td>
                            <td class="font-mono text-xs">{{ $row['data']['nis'] ?? '-' }}</td>
                            <td>{{ $row['data']['nama_siswa'] ?? '-' }}</td>
                            <td>{{ $row['data']['jenjang'] ?? '-' }}{{ $row['data']['kelas'] ? ' · '.$row['data']['kelas'] : '' }}</td>
                            <td class="text-sm">{{ $row['data']['nama_wali'] ?? '' }}{{ $row['data']['hp_wali'] ? ' ('.$row['data']['hp_wali'].')' : '' }}</td>
                            <td @class(['text-sm', 'text-error' => $row['status'] === 'error', 'text-base-content/60' => $row['status'] !== 'error'])>{{ implode(' ', $row['messages']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
