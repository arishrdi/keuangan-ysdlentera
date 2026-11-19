<?php

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Student;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Wali Murid')] class extends Component {
    use WithPagination;

    /**
     * @var array<int, string>
     */
    public const array RELATIONSHIPS = ['Ayah', 'Ibu', 'Wali'];

    #[Url(as: 'cari')]
    public string $search = '';

    public ?int $editingId = null;

    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $address = '';

    public string $password = '';

    public bool $is_active = true;

    public ?int $linkingGuardianId = null;

    public string $studentSearch = '';

    public string $relationship = 'Ayah';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    #[Computed]
    public function guardians(): LengthAwarePaginator
    {
        return User::query()
            ->where('role', Role::Guardian)
            ->with(['students' => fn ($query) => $query->with('level')->orderBy('name')])
            ->when($this->search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', '%'.$this->search.'%')
                ->orWhere('phone', 'like', '%'.$this->search.'%')
                ->orWhereHas('students', fn ($query) => $query->where('name', 'like', '%'.$this->search.'%'))))
            ->orderBy('name')
            ->paginate(20);
    }

    #[Computed]
    public function linkingGuardian(): ?User
    {
        return $this->linkingGuardianId
            ? User::query()->where('role', Role::Guardian)->with(['students.level'])->find($this->linkingGuardianId)
            : null;
    }

    /**
     * @return Collection<int, Student>
     */
    #[Computed]
    public function studentCandidates(): Collection
    {
        if (Str::length(trim($this->studentSearch)) < 2 || $this->linkingGuardian === null) {
            return new Collection;
        }

        return Student::query()
            ->with('level')
            ->whereNotIn('id', $this->linkingGuardian->students->modelKeys())
            ->where(fn ($query) => $query
                ->where('name', 'like', '%'.$this->studentSearch.'%')
                ->orWhere('nis', 'like', '%'.$this->studentSearch.'%'))
            ->orderBy('name')
            ->limit(10)
            ->get();
    }

    public function create(): void
    {
        $this->authorize('manage-master-data');

        $this->resetValidation();
        $this->reset('editingId', 'name', 'phone', 'email', 'address', 'password');
        $this->is_active = true;

        $this->dispatch('open-modal', name: 'guardian-form');
    }

    public function edit(User $guardian): void
    {
        $this->authorize('manage-master-data');
        abort_unless($guardian->hasRole(Role::Guardian), 404);

        $this->resetValidation();
        $this->editingId = $guardian->id;
        $this->name = $guardian->name;
        $this->phone = (string) $guardian->phone;
        $this->email = (string) $guardian->email;
        $this->address = (string) $guardian->address;
        $this->is_active = $guardian->is_active;
        $this->password = '';

        $this->dispatch('open-modal', name: 'guardian-form');
    }

    public function save(): void
    {
        $this->authorize('manage-master-data');

        $this->phone = (string) User::normalizePhone($this->phone);
        $this->email = Str::lower(trim($this->email));

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'min:9', 'max:20', Rule::unique(User::class)->ignore($this->editingId)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique(User::class)->ignore($this->editingId)],
            'address' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
            'password' => [$this->editingId ? 'nullable' : 'required', 'string', Password::default()],
        ], [], [
            'name' => 'nama',
            'phone' => 'nomor HP',
            'address' => 'alamat',
        ]);

        $attributes = collect($validated)
            ->except('password')
            ->map(fn ($value) => $value === '' ? null : $value)
            ->put('role', Role::Guardian)
            ->all();

        if (filled($validated['password'])) {
            $attributes['password'] = $validated['password'];
        }

        $guardian = User::query()->updateOrCreate(['id' => $this->editingId], $attributes);

        ActivityLog::record(
            $this->editingId ? 'guardian.updated' : 'guardian.created',
            ($this->editingId ? 'Mengubah' : 'Menambah').' akun wali murid '.$guardian->name,
            $guardian,
            ['is_active' => $guardian->is_active, 'password_changed' => filled($validated['password'])],
        );

        $this->dispatch('close-modal', name: 'guardian-form');
        $this->dispatch('toast', variant: 'success', text: 'Data wali murid disimpan.');
        unset($this->guardians);

        if ($this->editingId === null) {
            $this->manageStudents($guardian);
        }
    }

    public function manageStudents(User $guardian): void
    {
        $this->authorize('manage-master-data');
        abort_unless($guardian->hasRole(Role::Guardian), 404);

        $this->linkingGuardianId = $guardian->id;
        $this->reset('studentSearch');
        $this->relationship = 'Ayah';
        unset($this->linkingGuardian);

        $this->dispatch('open-modal', name: 'guardian-students');
    }

    public function linkStudent(Student $student): void
    {
        $this->authorize('manage-master-data');

        $guardian = $this->linkingGuardian;
        abort_if($guardian === null, 404);

        $this->validate(['relationship' => ['required', Rule::in(self::RELATIONSHIPS)]], [], ['relationship' => 'hubungan']);

        $guardian->students()->syncWithoutDetaching([$student->id => ['relationship' => $this->relationship]]);

        ActivityLog::record('guardian.student_linked', 'Menghubungkan wali '.$guardian->name.' dengan siswa '.$student->name, $guardian, [
            'student_id' => $student->id,
            'relationship' => $this->relationship,
        ]);

        $this->reset('studentSearch');
        unset($this->linkingGuardian, $this->guardians);
    }

    public function unlinkStudent(Student $student): void
    {
        $this->authorize('manage-master-data');

        $guardian = $this->linkingGuardian;
        abort_if($guardian === null, 404);

        $guardian->students()->detach($student->id);

        ActivityLog::record('guardian.student_unlinked', 'Melepas hubungan wali '.$guardian->name.' dengan siswa '.$student->name, $guardian, [
            'student_id' => $student->id,
        ]);

        unset($this->linkingGuardian, $this->guardians);
    }
}; ?>

<div>
    <x-page-header title="Wali Murid" description="Akun wali murid login dengan nomor HP. Satu wali dapat terhubung ke beberapa siswa.">
        <x-slot:actions>
            <label class="input input-sm w-64">
                <x-icon name="magnifying-glass" class="size-4 opacity-60" />
                <input type="search" wire:model.live.debounce.400ms="search" placeholder="Cari nama / no. HP / nama anak" />
            </label>
            <button type="button" class="btn btn-primary" wire:click="create">
                <x-icon name="plus" class="size-4" /> Tambah wali
            </button>
        </x-slot:actions>
    </x-page-header>

    <div class="overflow-x-auto rounded-box border border-base-300">
        <table class="table">
            <thead>
                <tr>
                    <th>Nama wali</th>
                    <th>Kontak</th>
                    <th>Anak terhubung</th>
                    <th>Status</th>
                    <th class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->guardians as $guardian)
                    <tr wire:key="guardian-{{ $guardian->id }}">
                        <td class="font-medium">{{ $guardian->name }}</td>
                        <td class="text-sm">{{ $guardian->phone }}<br><span class="text-base-content/60">{{ $guardian->email }}</span></td>
                        <td>
                            <div class="flex flex-wrap gap-1">
                                @forelse ($guardian->students as $student)
                                    <span class="badge badge-outline" wire:key="guardian-{{ $guardian->id }}-student-{{ $student->id }}">
                                        {{ $student->name }} · {{ $student->level->code }}
                                    </span>
                                @empty
                                    <span class="text-sm text-base-content/60">Belum ada</span>
                                @endforelse
                            </div>
                        </td>
                        <td>
                            @if ($guardian->is_active)
                                <span class="badge badge-success">Aktif</span>
                            @else
                                <span class="badge badge-ghost">Nonaktif</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <div class="flex justify-end gap-1">
                                <button type="button" class="btn btn-sm btn-ghost" wire:click="manageStudents({{ $guardian->id }})">
                                    <x-icon name="link" class="size-4" /> Anak
                                </button>
                                <button type="button" class="btn btn-sm btn-ghost btn-square" wire:click="edit({{ $guardian->id }})" aria-label="Ubah">
                                    <x-icon name="pencil-square" class="size-4" />
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="py-8 text-center text-base-content/60">Tidak ada wali murid.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->guardians->links() }}</div>

    <x-modal name="guardian-form" :title="$editingId ? 'Ubah wali murid' : 'Tambah wali murid'">
        <form wire:submit="save" class="space-y-4">
            <x-input wire:model="name" label="Nama lengkap" required />
            <div class="grid gap-4 sm:grid-cols-2">
                <x-input wire:model="phone" label="No. HP (untuk login)" inputmode="tel" placeholder="08xxxxxxxxxx" required />
                <x-input wire:model="email" label="Email (opsional)" type="email" />
            </div>
            <x-textarea wire:model="address" label="Alamat (opsional)" />
            <x-input
                wire:model="password"
                :label="$editingId ? 'Password baru (kosongkan jika tidak diubah)' : 'Password'"
                type="password"
                autocomplete="new-password"
                viewable
                :required="$editingId === null"
            />
            <label class="label cursor-pointer gap-2 text-base-content">
                <input type="checkbox" class="toggle toggle-primary toggle-sm" wire:model="is_active" />
                <span class="text-sm">Akun aktif (bisa login)</span>
            </label>

            <div class="modal-action">
                <button type="button" class="btn" x-on:click="$el.closest('dialog').close()">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </x-modal>

    <x-modal name="guardian-students" :title="'Anak terhubung'.($this->linkingGuardian ? ' — '.$this->linkingGuardian->name : '')" max-width="max-w-2xl">
        @if ($this->linkingGuardian)
            <ul class="mb-6 divide-y divide-base-300 rounded-box border border-base-300">
                @forelse ($this->linkingGuardian->students as $student)
                    <li class="flex items-center justify-between gap-2 p-3" wire:key="linked-{{ $student->id }}">
                        <div>
                            <div class="font-medium">{{ $student->name }}</div>
                            <div class="text-sm text-base-content/60">{{ $student->level->name }} · NIS {{ $student->nis ?? '-' }} · {{ $student->pivot->relationship }}</div>
                        </div>
                        <button
                            type="button"
                            class="btn btn-sm btn-ghost text-error"
                            wire:click="unlinkStudent({{ $student->id }})"
                            wire:confirm="Lepas hubungan dengan {{ $student->name }}?"
                        >
                            Lepas
                        </button>
                    </li>
                @empty
                    <li class="p-3 text-sm text-base-content/60">Belum ada siswa terhubung.</li>
                @endforelse
            </ul>

            <div class="grid gap-3 sm:grid-cols-[1fr_auto]">
                <label class="input w-full">
                    <x-icon name="magnifying-glass" class="size-4 opacity-60" />
                    <input type="search" wire:model.live.debounce.300ms="studentSearch" placeholder="Cari siswa (nama atau NIS)" />
                </label>
                <select class="select" wire:model="relationship" aria-label="Hubungan">
                    @foreach ($this::RELATIONSHIPS as $option)
                        <option value="{{ $option }}" wire:key="relationship-{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
            </div>

            @if ($this->studentCandidates->isNotEmpty())
                <ul class="menu mt-2 w-full rounded-box border border-base-300">
                    @foreach ($this->studentCandidates as $candidate)
                        <li wire:key="candidate-{{ $candidate->id }}">
                            <button type="button" wire:click="linkStudent({{ $candidate->id }})" class="justify-between">
                                <span>{{ $candidate->name }} <span class="text-base-content/60">· NIS {{ $candidate->nis ?? '-' }}</span></span>
                                <span class="badge badge-outline">{{ $candidate->level->code }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @elseif (\Illuminate\Support\Str::length(trim($studentSearch)) >= 2)
                <p class="mt-2 text-sm text-base-content/60">Siswa tidak ditemukan.</p>
            @endif
        @endif

        <div class="modal-action">
            <button type="button" class="btn" x-on:click="$el.closest('dialog').close()">Selesai</button>
        </div>
    </x-modal>
</div>
