<?php

use App\Actions\DeleteUnusedRecord;
use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Data Pengguna')] class extends Component {
    use WithPagination;

    #[Url(as: 'cari')]
    public string $search = '';

    public ?int $editingId = null;

    public string $name = '';

    public string $username = '';

    public string $email = '';

    public string $phone = '';

    public string $role = '';

    public bool $is_active = true;

    public string $password = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    #[Computed]
    public function users(): LengthAwarePaginator
    {
        return User::query()
            ->whereIn('role', Role::staff())
            ->when($this->search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', '%'.$this->search.'%')
                ->orWhere('username', 'like', '%'.$this->search.'%')
                ->orWhere('email', 'like', '%'.$this->search.'%')))
            ->orderBy('name')
            ->paginate(20);
    }

    public function create(): void
    {
        $this->authorize('manage-master-data');

        $this->resetValidation();
        $this->reset('editingId', 'name', 'username', 'email', 'phone', 'role', 'password');
        $this->is_active = true;

        $this->dispatch('open-modal', name: 'user-form');
    }

    public function edit(User $user): void
    {
        $this->authorize('manage-master-data');
        abort_unless($user->isStaff(), 404);

        $this->resetValidation();
        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->username = (string) $user->username;
        $this->email = (string) $user->email;
        $this->phone = (string) $user->phone;
        $this->role = $user->role->value;
        $this->is_active = $user->is_active;
        $this->password = '';

        $this->dispatch('open-modal', name: 'user-form');
    }

    public function save(): void
    {
        $this->authorize('manage-master-data');

        $this->username = Str::lower(trim($this->username));
        $this->email = Str::lower(trim($this->email));
        $this->phone = (string) User::normalizePhone($this->phone);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[a-z0-9._-]+$/', Rule::unique(User::class)->ignore($this->editingId)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique(User::class)->ignore($this->editingId)],
            'phone' => ['nullable', 'string', 'min:9', 'max:20', Rule::unique(User::class)->ignore($this->editingId)],
            'role' => ['required', Rule::in(array_map(fn (Role $role) => $role->value, Role::staff()))],
            'is_active' => ['boolean'],
            'password' => [$this->editingId ? 'nullable' : 'required', 'string', Password::default()],
        ], [
            'username.regex' => 'Username hanya boleh berisi huruf kecil, angka, titik, tanda hubung, dan garis bawah.',
        ], [
            'name' => 'nama',
            'phone' => 'nomor HP',
        ]);

        if ($this->editingId === auth()->id() && ($validated['role'] !== Role::Admin->value || ! $validated['is_active'])) {
            $this->addError('role', 'Anda tidak bisa mengubah role atau menonaktifkan akun Anda sendiri.');

            return;
        }

        $attributes = collect($validated)
            ->except('password')
            ->map(fn ($value) => $value === '' ? null : $value)
            ->all();

        if (filled($validated['password'])) {
            $attributes['password'] = $validated['password'];
        }

        $user = User::query()->updateOrCreate(['id' => $this->editingId], $attributes);

        ActivityLog::record(
            $this->editingId ? 'user.updated' : 'user.created',
            ($this->editingId ? 'Mengubah' : 'Menambah').' akun '.$user->role->label().' '.$user->name,
            $user,
            ['role' => $user->role->value, 'is_active' => $user->is_active, 'password_changed' => filled($validated['password'])],
        );

        $this->dispatch('close-modal', name: 'user-form');
        $this->dispatch('toast', variant: 'success', text: 'Akun pengguna disimpan.');
        unset($this->users);
    }
    public function delete(User $user, DeleteUnusedRecord $deleteUnusedRecord): void
    {
        $this->authorize('manage-master-data');

        try {
            $deleteUnusedRecord->handle($user);
        } catch (ValidationException $exception) {
            $this->dispatch('toast', variant: 'error', text: $exception->getMessage());

            return;
        }

        ActivityLog::record('user.deleted', 'Menghapus akun pengguna '.$user->name, properties: ['user_id' => $user->id]);

        $this->dispatch('toast', variant: 'success', text: 'Akun pengguna dihapus.');
        unset($this->users);
    }
}; ?>

<div>
    <x-page-header title="Data Pengguna" description="Akun staf: Yayasan, Kepala Sekolah, Bendahara, dan Admin. Akun wali murid dikelola di menu Wali Murid.">
        <x-slot:actions>
            <label class="input input-sm w-56">
                <x-icon name="magnifying-glass" class="size-4 opacity-60" />
                <input type="search" wire:model.live.debounce.400ms="search" placeholder="Cari nama / username" />
            </label>
            <button type="button" class="btn btn-primary" wire:click="create">
                <x-icon name="plus" class="size-4" /> Tambah pengguna
            </button>
        </x-slot:actions>
    </x-page-header>

    <div class="overflow-x-auto rounded-box border border-base-300">
        <table class="table">
            <thead>
                <tr>
                    <th>Nama</th>
                    <th>Username</th>
                    <th>Kontak</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->users as $user)
                    <tr wire:key="user-{{ $user->id }}">
                        <td class="font-medium">{{ $user->name }}</td>
                        <td>{{ $user->username }}</td>
                        <td class="text-sm">{{ $user->email ?? '-' }}<br><span class="text-base-content/60">{{ $user->phone }}</span></td>
                        <td><span class="badge badge-outline">{{ $user->role->label() }}</span></td>
                        <td>
                            @if ($user->is_active)
                                <span class="badge badge-success">Aktif</span>
                            @else
                                <span class="badge badge-ghost">Nonaktif</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-ghost btn-square" wire:click="edit({{ $user->id }})" aria-label="Ubah">
                                <x-icon name="pencil-square" class="size-4" />
                            </button>
                            <x-confirm-button
                                :action="'delete('.$user->id.')'"
                                title="Hapus akun pengguna"
                                :message="'Hapus '.$user->name.'? Data yang sudah dipakai di transaksi tidak bisa dihapus, nonaktifkan saja.'"
                                confirm-label="Hapus"
                                danger
                                class="btn btn-sm btn-ghost btn-square text-error"
                                aria-label="Hapus {{ $user->name }}"
                            >
                                <x-icon name="trash" class="size-4" />
                            </x-confirm-button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="py-8 text-center text-base-content/60">Tidak ada pengguna.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->users->links() }}</div>

    <x-modal name="user-form" :title="$editingId ? 'Ubah pengguna' : 'Tambah pengguna'">
        <form wire:submit="save" class="space-y-4">
            <x-input wire:model="name" label="Nama lengkap" required />
            <div class="grid gap-4 sm:grid-cols-2">
                <x-input wire:model="username" label="Username" autocomplete="off" required />
                <x-select wire:model="role" label="Role" placeholder="Pilih role" required>
                    @foreach (\App\Enums\Role::staff() as $staffRole)
                        <option value="{{ $staffRole->value }}" wire:key="role-{{ $staffRole->value }}">{{ $staffRole->label() }}</option>
                    @endforeach
                </x-select>
                <x-input wire:model="email" label="Email (opsional)" type="email" />
                <x-input wire:model="phone" label="No. HP (opsional)" inputmode="tel" />
            </div>
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
</div>
