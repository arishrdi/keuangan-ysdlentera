<?php

use App\Models\ActivityLog;
use App\Models\Level;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Profil Jenjang')] class extends Component {
    public ?int $editingId = null;

    public string $name = '';

    public string $address = '';

    public string $phone = '';

    /**
     * @return Collection<int, Level>
     */
    #[Computed]
    public function levels(): Collection
    {
        return Level::query()->ordered()->withCount(['students' => fn ($query) => $query->active()])->get();
    }

    public function edit(Level $level): void
    {
        $this->authorize('manage-master-data');
        $this->resetValidation();

        $this->editingId = $level->id;
        $this->name = $level->name;
        $this->address = (string) $level->address;
        $this->phone = (string) $level->phone;

        $this->dispatch('open-modal', name: 'level-form');
    }

    public function save(): void
    {
        $this->authorize('manage-master-data');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
        ], [], ['name' => 'nama jenjang', 'address' => 'alamat', 'phone' => 'telepon']);

        $level = Level::query()->findOrFail($this->editingId);
        $level->update([
            'name' => $validated['name'],
            'address' => $validated['address'] ?: null,
            'phone' => $validated['phone'] ?: null,
        ]);

        ActivityLog::record('level.updated', 'Mengubah profil jenjang '.$level->code, $level);

        $this->dispatch('close-modal', name: 'level-form');
        $this->dispatch('toast', variant: 'success', text: 'Profil jenjang disimpan.');
        unset($this->levels);
    }
}; ?>

<div>
    <x-page-header title="Profil Jenjang" description="Nama, alamat, dan telepon setiap jenjang. Ditampilkan pada kop kuitansi." />

    <div class="grid gap-4 md:grid-cols-3">
        @foreach ($this->levels as $level)
            <div class="card border border-base-300" wire:key="level-{{ $level->id }}">
                <div class="card-body gap-2">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <span class="badge badge-outline">{{ $level->code }}</span>
                            <h2 class="mt-1 card-title text-base">{{ $level->name }}</h2>
                        </div>
                        <button type="button" class="btn btn-sm btn-ghost btn-square" wire:click="edit({{ $level->id }})" aria-label="Ubah {{ $level->name }}">
                            <x-icon name="pencil-square" class="size-4" />
                        </button>
                    </div>
                    <dl class="text-sm">
                        <dt class="text-base-content/60">Alamat</dt>
                        <dd>{{ $level->address ?? '-' }}</dd>
                        <dt class="mt-1 text-base-content/60">Telepon</dt>
                        <dd>{{ $level->phone ?? '-' }}</dd>
                        <dt class="mt-1 text-base-content/60">Siswa aktif</dt>
                        <dd>{{ $level->students_count }}</dd>
                    </dl>
                </div>
            </div>
        @endforeach
    </div>

    <x-modal name="level-form" title="Ubah profil jenjang">
        <form wire:submit="save" class="space-y-4">
            <x-input wire:model="name" label="Nama jenjang" placeholder="contoh: SD Lentera" required />
            <x-textarea wire:model="address" label="Alamat" rows="2" />
            <x-input wire:model="phone" label="Telepon" inputmode="tel" />
            <div class="modal-action">
                <button type="button" class="btn" x-on:click="$el.closest('dialog').close()">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </x-modal>
</div>
