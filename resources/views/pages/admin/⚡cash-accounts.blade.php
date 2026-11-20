<?php

use App\Actions\DeleteUnusedRecord;
use App\Enums\CashAccountType;
use App\Models\ActivityLog;
use App\Models\CashAccount;
use App\Models\Level;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

new #[Title('Akun Kas')] class extends Component {
    public ?int $editingId = null;

    public ?int $level_id = null;

    public string $type = 'bank';

    public string $name = '';

    public string $bank_name = '';

    public string $account_number = '';

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
     * @return Collection<int, CashAccount>
     */
    #[Computed]
    public function accounts(): Collection
    {
        return CashAccount::query()
            ->with('level')
            ->when($this->activeLevel, fn ($query, Level $level) => $query->availableTo($level))
            ->orderBy(Level::query()->select('sort_order')->whereColumn('levels.id', 'cash_accounts.level_id'))
            ->orderBy('type', 'desc')
            ->orderBy('name')
            ->get();
    }

    public function create(): void
    {
        $this->authorize('manage-payment-settings');

        $this->resetValidation();
        $this->reset('editingId', 'name', 'bank_name', 'account_number');
        $this->type = CashAccountType::Bank->value;
        $this->is_active = true;
        $this->level_id = $this->activeLevel?->id;

        $this->dispatch('open-modal', name: 'cash-account-form');
    }

    public function edit(CashAccount $account): void
    {
        $this->authorize('manage-payment-settings');

        $this->resetValidation();
        $this->editingId = $account->id;
        $this->level_id = $account->level_id;
        $this->type = $account->type->value;
        $this->name = $account->name;
        $this->bank_name = (string) $account->bank_name;
        $this->account_number = (string) $account->account_number;
        $this->is_active = $account->is_active;

        $this->dispatch('open-modal', name: 'cash-account-form');
    }

    public function save(): void
    {
        $this->authorize('manage-payment-settings');

        $isBank = $this->type === CashAccountType::Bank->value;
        $existing = $this->editingId ? CashAccount::query()->findOrFail($this->editingId) : null;

        $validated = $this->validate([
            'level_id' => ['nullable', 'integer', Rule::exists(Level::class, 'id')],
            'type' => ['required', Rule::enum(CashAccountType::class)],
            'name' => ['required', 'string', 'max:100', Rule::unique(CashAccount::class)->where(fn ($query) => $existing ? $query->where('level_id', $existing->level_id) : $query->where('level_id', $this->level_id))->ignore($this->editingId)],
            'bank_name' => [Rule::requiredIf($isBank), 'nullable', 'string', 'max:100'],
            'account_number' => [Rule::requiredIf($isBank), 'nullable', 'string', 'max:50'],
            'is_active' => ['boolean'],
        ], [], [
            'level_id' => 'jenjang',
            'type' => 'jenis',
            'name' => 'nama akun',
            'bank_name' => 'nama bank',
            'account_number' => 'nomor rekening',
        ]);

        $account = CashAccount::query()->updateOrCreate(['id' => $this->editingId], [
            'level_id' => $existing ? $existing->level_id : $validated['level_id'],
            'type' => $existing->type ?? $validated['type'],
            'name' => $validated['name'],
            'bank_name' => $isBank ? $validated['bank_name'] : null,
            'account_number' => $isBank ? $validated['account_number'] : null,
            'is_active' => $validated['is_active'],
        ]);

        ActivityLog::record($existing ? 'cash_account.updated' : 'cash_account.created', ($existing ? 'Mengubah' : 'Menambah').' akun kas '.$account->name, $account);

        $this->dispatch('close-modal', name: 'cash-account-form');
        $this->dispatch('toast', variant: 'success', text: 'Akun kas disimpan.');
        unset($this->accounts);
    }
    public function delete(CashAccount $account, DeleteUnusedRecord $deleteUnusedRecord): void
    {
        $this->authorize('manage-payment-settings');

        try {
            $deleteUnusedRecord->handle($account);
        } catch (ValidationException $exception) {
            $this->dispatch('toast', variant: 'error', text: $exception->getMessage());

            return;
        }

        ActivityLog::record('cash_account.deleted', 'Menghapus akun kas '.$account->name, properties: ['cash_account_id' => $account->id]);

        $this->dispatch('toast', variant: 'success', text: 'Akun kas dihapus.');
        unset($this->accounts);
    }
}; ?>

<div>
    <x-page-header title="Akun Kas" description="Tempat penyimpanan uang: kas tunai dan rekening bank, milik satu jenjang atau dipakai bersama semua jenjang. Saldo dihitung otomatis dari transaksi.">
        <x-slot:actions>
            <button type="button" class="btn btn-primary" wire:click="create">
                <x-icon name="plus" class="size-4" /> Tambah akun
            </button>
        </x-slot:actions>
    </x-page-header>

    <div class="overflow-x-auto rounded-box border border-base-300">
        <table class="table">
            <thead>
                <tr>
                    <th>Jenjang</th>
                    <th>Nama akun</th>
                    <th>Jenis</th>
                    <th>Rekening</th>
                    <th>Status</th>
                    <th class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->accounts as $account)
                    <tr wire:key="account-{{ $account->id }}">
                        <td><span @class(['badge', 'badge-outline' => ! $account->isShared(), 'badge-info badge-soft' => $account->isShared()])>{{ $account->isShared() ? 'Semua jenjang' : $account->level->code }}</span></td>
                        <td class="font-medium">{{ $account->name }}</td>
                        <td>{{ $account->type->label() }}</td>
                        <td class="text-sm">
                            @if ($account->bank_name)
                                {{ $account->bank_name }}
                                <div class="font-mono text-xs text-base-content/60">{{ $account->account_number }}</div>
                            @else
                                -
                            @endif
                        </td>
                        <td>
                            @if ($account->is_active)
                                <span class="badge badge-success">Aktif</span>
                            @else
                                <span class="badge badge-ghost">Nonaktif</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-ghost btn-square" wire:click="edit({{ $account->id }})" aria-label="Ubah">
                                <x-icon name="pencil-square" class="size-4" />
                            </button>
                            <x-confirm-button
                                :action="'delete('.$account->id.')'"
                                title="Hapus akun kas"
                                :message="'Hapus '.$account->name.'? Data yang sudah dipakai di transaksi tidak bisa dihapus, nonaktifkan saja.'"
                                confirm-label="Hapus"
                                danger
                                class="btn btn-sm btn-ghost btn-square text-error"
                                aria-label="Hapus {{ $account->name }}"
                            >
                                <x-icon name="trash" class="size-4" />
                            </x-confirm-button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-8 text-center text-base-content/60">Belum ada akun kas.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-modal name="cash-account-form" :title="$editingId ? 'Ubah akun kas' : 'Tambah akun kas'">
        <form wire:submit="save" class="space-y-4">
            @unless ($editingId)
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-select wire:model.live="level_id" label="Jenjang">
                        <option value="">Semua jenjang (akun bersama)</option>
                        @foreach ($this->levels as $level)
                            <option value="{{ $level->id }}" wire:key="form-level-{{ $level->id }}">{{ $level->name }}</option>
                        @endforeach
                    </x-select>
                    <x-select wire:model.live="type" label="Jenis" required>
                        @foreach (\App\Enums\CashAccountType::cases() as $accountType)
                            <option value="{{ $accountType->value }}" wire:key="type-{{ $accountType->value }}">{{ $accountType->label() }}</option>
                        @endforeach
                    </x-select>
                </div>
            @endunless
            @if (! $editingId && $level_id === null)
                <p class="text-xs text-base-content/60">Akun bersama dipakai semua jenjang. Saldonya tetap dicatat terpisah per jenjang, dan tiap jenjang hanya bisa memakai saldonya sendiri.</p>
            @endif
            <x-input wire:model="name" label="Nama akun" placeholder="contoh: BSI SD Lentera" required />
            @if ($type === 'bank')
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-input wire:model="bank_name" label="Nama bank" required />
                    <x-input wire:model="account_number" label="Nomor rekening" required />
                </div>
            @endif
            <label class="label cursor-pointer gap-2 text-base-content">
                <input type="checkbox" class="toggle toggle-primary toggle-sm" wire:model="is_active" />
                <span class="text-sm">Akun aktif (bisa dipakai untuk transaksi baru)</span>
            </label>

            <div class="modal-action">
                <button type="button" class="btn" x-on:click="$el.closest('dialog').close()">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </x-modal>
</div>
