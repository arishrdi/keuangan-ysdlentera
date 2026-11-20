<?php

use App\Actions\DeleteUnusedRecord;
use App\Models\ActivityLog;
use App\Models\Level;
use App\Models\UniformItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

new #[Title('Katalog Seragam')] class extends Component {
    public ?int $editingId = null;

    public ?int $level_id = null;

    public string $name = '';

    public string $size = '';

    public ?int $price = null;

    public bool $is_active = true;

    #[Computed]
    public function activeLevel(): ?Level
    {
        return Level::active();
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
     * @return Collection<int, UniformItem>
     */
    #[Computed]
    public function items(): Collection
    {
        return UniformItem::query()
            ->with('level')
            ->when($this->activeLevel, fn ($query, Level $level) => $query->whereBelongsTo($level))
            ->orderBy(Level::query()->select('sort_order')->whereColumn('levels.id', 'uniform_items.level_id'))
            ->orderBy('name')
            ->orderBy('size')
            ->get();
    }

    public function create(): void
    {
        $this->authorize('manage-payment-settings');

        $this->resetValidation();
        $this->reset('editingId', 'name', 'size', 'price');
        $this->is_active = true;
        $this->level_id = $this->activeLevel?->id;

        $this->dispatch('open-modal', name: 'uniform-item-form');
    }

    public function edit(UniformItem $item): void
    {
        $this->authorize('manage-payment-settings');

        $this->resetValidation();
        $this->editingId = $item->id;
        $this->level_id = $item->level_id;
        $this->name = $item->name;
        $this->size = (string) $item->size;
        $this->price = $item->price;
        $this->is_active = $item->is_active;

        $this->dispatch('open-modal', name: 'uniform-item-form');
    }

    public function save(): void
    {
        $this->authorize('manage-payment-settings');

        $validated = $this->validate([
            'level_id' => ['required', 'integer', Rule::exists(Level::class, 'id')],
            'name' => ['required', 'string', 'max:100'],
            'size' => ['nullable', 'string', 'max:20'],
            'price' => ['required', 'integer', 'min:1000'],
            'is_active' => ['boolean'],
        ], [], [
            'level_id' => 'jenjang',
            'name' => 'nama item',
            'size' => 'ukuran',
            'price' => 'harga',
        ]);

        $item = UniformItem::query()->updateOrCreate(['id' => $this->editingId], [...$validated, 'size' => $validated['size'] ?: null]);

        ActivityLog::record(
            $this->editingId ? 'uniform_item.updated' : 'uniform_item.created',
            ($this->editingId ? 'Mengubah' : 'Menambah').' item seragam '.$item->label(),
            $item,
            ['price' => $item->price],
        );

        $this->dispatch('close-modal', name: 'uniform-item-form');
        $this->dispatch('toast', variant: 'success', text: 'Item seragam disimpan.');
        unset($this->items);
    }
    public function delete(UniformItem $item, DeleteUnusedRecord $deleteUnusedRecord): void
    {
        $this->authorize('manage-payment-settings');

        try {
            $deleteUnusedRecord->handle($item);
        } catch (ValidationException $exception) {
            $this->dispatch('toast', variant: 'error', text: $exception->getMessage());

            return;
        }

        ActivityLog::record('uniform_item.deleted', 'Menghapus item seragam '.$item->name, properties: ['uniform_item_id' => $item->id]);

        $this->dispatch('toast', variant: 'success', text: 'Item seragam dihapus.');
        unset($this->items);
    }
}; ?>

<div>
    <x-page-header title="Katalog Seragam" :description="'Item seragam dan harganya, '.($this->activeLevel ? 'jenjang '.$this->activeLevel->name : 'semua jenjang').'. Tidak ada pencatatan stok.'">
        <x-slot:actions>
            <button type="button" class="btn btn-primary" wire:click="create">
                <x-icon name="plus" class="size-4" /> Tambah item
            </button>
        </x-slot:actions>
    </x-page-header>

    <div class="overflow-x-auto rounded-box border border-base-300">
        <table class="table">
            <thead>
                <tr>
                    <th>Jenjang</th>
                    <th>Item</th>
                    <th>Ukuran</th>
                    <th class="text-end">Harga</th>
                    <th>Status</th>
                    <th class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->items as $item)
                    <tr wire:key="uniform-item-{{ $item->id }}">
                        <td><span class="badge badge-outline">{{ $item->level->code }}</span></td>
                        <td class="font-medium">{{ $item->name }}</td>
                        <td>{{ $item->size ?? '-' }}</td>
                        <td class="text-end font-mono">{{ \Illuminate\Support\Number::rupiah($item->price) }}</td>
                        <td>
                            @if ($item->is_active)
                                <span class="badge badge-success">Dijual</span>
                            @else
                                <span class="badge badge-ghost">Tidak dijual</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-ghost btn-square" wire:click="edit({{ $item->id }})" aria-label="Ubah">
                                <x-icon name="pencil-square" class="size-4" />
                            </button>
                            <x-confirm-button
                                :action="'delete('.$item->id.')'"
                                title="Hapus item seragam"
                                :message="'Hapus '.$item->name.'? Data yang sudah dipakai di transaksi tidak bisa dihapus, nonaktifkan saja.'"
                                confirm-label="Hapus"
                                danger
                                class="btn btn-sm btn-ghost btn-square text-error"
                                aria-label="Hapus {{ $item->name }}"
                            >
                                <x-icon name="trash" class="size-4" />
                            </x-confirm-button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-8 text-center text-base-content/60">Belum ada item seragam.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-modal name="uniform-item-form" :title="$editingId ? 'Ubah item seragam' : 'Tambah item seragam'">
        <form wire:submit="save" class="space-y-4">
            <x-select wire:model="level_id" label="Jenjang" placeholder="Pilih jenjang" required>
                @foreach ($this->levels as $level)
                    <option value="{{ $level->id }}" wire:key="form-level-{{ $level->id }}">{{ $level->name }}</option>
                @endforeach
            </x-select>
            <div class="grid gap-4 sm:grid-cols-3">
                <x-input wire:model="name" label="Nama item" placeholder="Seragam batik" required class="sm:col-span-2" />
                <x-input wire:model="size" label="Ukuran" placeholder="M" />
            </div>
            <x-money-input wire:model="price" label="Harga" required />
            <label class="label cursor-pointer gap-2 text-base-content">
                <input type="checkbox" class="toggle toggle-primary toggle-sm" wire:model="is_active" />
                <span class="text-sm">Masih dijual</span>
            </label>

            <div class="modal-action">
                <button type="button" class="btn" x-on:click="$el.closest('dialog').close()">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </x-modal>
</div>
