<?php

use App\Actions\DeleteUnusedRecord;
use App\Enums\TransactionCategoryType;
use App\Models\ActivityLog;
use App\Models\TransactionCategory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

new #[Title('Kategori Transaksi')] class extends Component {
    public ?int $editingId = null;

    public string $type = 'pengeluaran';

    public string $name = '';

    public bool $is_active = true;

    /**
     * @return Collection<int, TransactionCategory>
     */
    #[Computed]
    public function categories(): Collection
    {
        return TransactionCategory::query()->orderBy('type')->orderBy('name')->get();
    }

    public function create(string $type): void
    {
        $this->authorize('manage-payment-settings');

        $this->resetValidation();
        $this->reset('editingId', 'name');
        $this->type = TransactionCategoryType::from($type)->value;
        $this->is_active = true;

        $this->dispatch('open-modal', name: 'category-form');
    }

    public function edit(TransactionCategory $category): void
    {
        $this->authorize('manage-payment-settings');

        $this->resetValidation();
        $this->editingId = $category->id;
        $this->type = $category->type->value;
        $this->name = $category->name;
        $this->is_active = $category->is_active;

        $this->dispatch('open-modal', name: 'category-form');
    }

    public function save(): void
    {
        $this->authorize('manage-payment-settings');

        $validated = $this->validate([
            'type' => ['required', Rule::enum(TransactionCategoryType::class)],
            'name' => ['required', 'string', 'max:100', Rule::unique(TransactionCategory::class)->where('type', $this->type)->ignore($this->editingId)],
            'is_active' => ['boolean'],
        ], [], ['name' => 'nama kategori']);

        $category = TransactionCategory::query()->updateOrCreate(['id' => $this->editingId], $validated);

        ActivityLog::record($this->editingId ? 'transaction_category.updated' : 'transaction_category.created', 'Menyimpan kategori '.mb_strtolower($category->type->label()).' '.$category->name, $category);

        $this->dispatch('close-modal', name: 'category-form');
        $this->dispatch('toast', variant: 'success', text: 'Kategori disimpan.');
        unset($this->categories);
    }
    public function delete(TransactionCategory $category, DeleteUnusedRecord $deleteUnusedRecord): void
    {
        $this->authorize('manage-payment-settings');

        try {
            $deleteUnusedRecord->handle($category);
        } catch (ValidationException $exception) {
            $this->dispatch('toast', variant: 'error', text: $exception->getMessage());

            return;
        }

        ActivityLog::record('transaction_category.deleted', 'Menghapus kategori '.$category->name, properties: ['transaction_category_id' => $category->id]);

        $this->dispatch('toast', variant: 'success', text: 'Kategori dihapus.');
        unset($this->categories);
    }
}; ?>

<div>
    <x-page-header title="Kategori Transaksi" description="Kategori untuk pencatatan pemasukan lain dan pengeluaran. Kategori yang tidak dipakai lagi cukup dinonaktifkan." />

    <div class="grid gap-6 lg:grid-cols-2">
        @foreach (\App\Enums\TransactionCategoryType::cases() as $categoryType)
            <div class="card border border-base-300" wire:key="type-{{ $categoryType->value }}">
                <div class="card-body">
                    <div class="flex items-center justify-between">
                        <h2 class="card-title text-base">{{ $categoryType->label() }}</h2>
                        <button type="button" class="btn btn-sm btn-primary" wire:click="create('{{ $categoryType->value }}')">
                            <x-icon name="plus" class="size-4" /> Tambah
                        </button>
                    </div>
                    <ul class="divide-y divide-base-300">
                        @foreach ($this->categories->where('type', $categoryType) as $category)
                            <li class="flex items-center justify-between py-2" wire:key="category-{{ $category->id }}">
                                <span @class(['text-base-content/50 line-through' => ! $category->is_active])>{{ $category->name }}</span>
                                <button type="button" class="btn btn-xs btn-ghost btn-square" wire:click="edit({{ $category->id }})" aria-label="Ubah {{ $category->name }}">
                                    <x-icon name="pencil-square" class="size-4" />
                                </button>
                                <x-confirm-button
                                    :action="'delete('.$category->id.')'"
                                    title="Hapus kategori"
                                    :message="'Hapus '.$category->name.'? Data yang sudah dipakai di transaksi tidak bisa dihapus, nonaktifkan saja.'"
                                    confirm-label="Hapus"
                                    danger
                                    class="btn btn-xs btn-ghost btn-square text-error"
                                    aria-label="Hapus {{ $category->name }}"
                                >
                                    <x-icon name="trash" class="size-4" />
                                </x-confirm-button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endforeach
    </div>

    <x-modal name="category-form" :title="($editingId ? 'Ubah' : 'Tambah').' kategori '.\App\Enums\TransactionCategoryType::from($type)->label()">
        <form wire:submit="save" class="space-y-4">
            <x-input wire:model="name" label="Nama kategori" required />
            <label class="label cursor-pointer gap-2 text-base-content">
                <input type="checkbox" class="toggle toggle-primary toggle-sm" wire:model="is_active" />
                <span class="text-sm">Aktif</span>
            </label>
            <div class="modal-action">
                <button type="button" class="btn" x-on:click="$el.closest('dialog').close()">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </x-modal>
</div>
