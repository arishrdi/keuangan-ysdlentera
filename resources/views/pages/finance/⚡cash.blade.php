<?php

use App\Actions\Cash\CashLedger;
use App\Enums\CashDirection;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Level;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Kas')] class extends Component {
    #[Url(as: 'akun')]
    public ?int $accountFilter = null;

    #[Url(as: 'dari')]
    public string $from = '';

    #[Url(as: 'sampai')]
    public string $until = '';

    public ?int $fromAccountId = null;

    public ?int $toAccountId = null;

    public ?int $transferAmount = null;

    public string $transferDate = '';

    public string $transferNote = 'Setor tunai ke bank';

    public ?int $openingAccountId = null;

    public ?int $openingAmount = null;

    public string $openingDate = '';

    public function mount(): void
    {
        $this->from = $this->from ?: now()->startOfMonth()->toDateString();
        $this->until = $this->until ?: now()->endOfMonth()->toDateString();
    }

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('manage-cash');
    }

    #[Computed]
    public function activeLevel(): ?Level
    {
        return Level::active();
    }

    /**
     * @return Collection<int, CashAccount>
     */
    #[Computed]
    public function accounts(): Collection
    {
        return CashAccount::query()
            ->with('level')
            ->withBalance()
            ->when($this->activeLevel, fn ($query, Level $level) => $query->whereBelongsTo($level))
            ->orderBy(Level::query()->select('sort_order')->whereColumn('levels.id', 'cash_accounts.level_id'))
            ->orderBy('type', 'desc')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function selectedAccount(): ?CashAccount
    {
        return $this->accounts->firstWhere('id', $this->accountFilter);
    }

    /**
     * Balance just before the period, used as the start of the running balance.
     */
    #[Computed]
    public function openingBalance(): int
    {
        $accountIds = $this->selectedAccount ? [$this->selectedAccount->id] : $this->accounts->modelKeys();

        return (int) CashTransaction::query()
            ->whereIn('cash_account_id', $accountIds)
            ->whereDate('transacted_on', '<', $this->from)
            ->selectRaw('coalesce(sum(case when direction = ? then amount else -amount end), 0) as balance', [CashDirection::In->value])
            ->value('balance');
    }

    /**
     * Mutations of the period with the running balance after each one.
     *
     * @return Collection<int, CashTransaction>
     */
    #[Computed]
    public function mutations(): Collection
    {
        $accountIds = $this->selectedAccount ? [$this->selectedAccount->id] : $this->accounts->modelKeys();
        $balance = $this->openingBalance;

        return CashTransaction::query()
            ->with(['cashAccount', 'category'])
            ->whereIn('cash_account_id', $accountIds)
            ->whereDate('transacted_on', '>=', $this->from)
            ->whereDate('transacted_on', '<=', $this->until)
            ->orderBy('transacted_on')
            ->orderBy('id')
            ->get()
            ->each(function (CashTransaction $mutation) use (&$balance): void {
                $balance += $mutation->signedAmount();
                $mutation->setAttribute('running_balance', $balance);
            });
    }

    public function openTransfer(): void
    {
        $this->authorize('manage-cash');

        if ($this->activeLevel === null) {
            $this->dispatch('toast', variant: 'warning', text: 'Pilih jenjang tertentu di kanan atas terlebih dahulu.');

            return;
        }

        $this->resetValidation();
        $this->reset('transferAmount');
        $this->fromAccountId = $this->accounts->where('is_active', true)->first()?->id;
        $this->toAccountId = $this->accounts->where('is_active', true)->firstWhere('id', '!=', $this->fromAccountId)?->id;
        $this->transferDate = now()->toDateString();

        $this->dispatch('open-modal', name: 'transfer');
    }

    public function transfer(CashLedger $ledger): void
    {
        $this->authorize('manage-cash');
        abort_if($this->activeLevel === null, 422);

        $activeAccountIds = $this->accounts->where('is_active', true)->modelKeys();

        $this->validate([
            'fromAccountId' => ['required', 'integer', Rule::in($activeAccountIds)],
            'toAccountId' => ['required', 'integer', 'different:fromAccountId', Rule::in($activeAccountIds)],
            'transferAmount' => ['required', 'integer', 'min:1'],
            'transferDate' => ['required', 'date', 'before_or_equal:today'],
            'transferNote' => ['required', 'string', 'max:200'],
        ], [], [
            'fromAccountId' => 'akun asal',
            'toAccountId' => 'akun tujuan',
            'transferAmount' => 'nominal',
            'transferDate' => 'tanggal',
            'transferNote' => 'keterangan',
        ]);

        try {
            $ledger->transfer(
                $this->accounts->firstWhere('id', $this->fromAccountId),
                $this->accounts->firstWhere('id', $this->toAccountId),
                $this->transferAmount,
                Carbon::parse($this->transferDate),
                $this->transferNote,
            );
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->addError('transferAmount', collect($exception->errors())->flatten()->first());

            return;
        }

        $this->dispatch('close-modal', name: 'transfer');
        $this->dispatch('toast', variant: 'success', text: 'Transfer antar akun dicatat.');
        unset($this->accounts, $this->mutations, $this->openingBalance);
    }

    public function openOpening(int $accountId): void
    {
        $this->authorize('manage-cash');
        $this->resetValidation();

        $this->openingAccountId = $accountId;
        $this->reset('openingAmount');
        $this->openingDate = now()->toDateString();

        $this->dispatch('open-modal', name: 'opening-balance');
    }

    public function recordOpening(CashLedger $ledger): void
    {
        $this->authorize('manage-cash');

        $this->validate([
            'openingAccountId' => ['required', 'integer', Rule::in($this->accounts->modelKeys())],
            'openingAmount' => ['required', 'integer', 'min:1'],
            'openingDate' => ['required', 'date', 'before_or_equal:today'],
        ], [], ['openingAmount' => 'saldo awal', 'openingDate' => 'tanggal']);

        $ledger->recordOpeningBalance($this->accounts->firstWhere('id', $this->openingAccountId), $this->openingAmount, Carbon::parse($this->openingDate));

        $this->dispatch('close-modal', name: 'opening-balance');
        $this->dispatch('toast', variant: 'success', text: 'Saldo awal dicatat.');
        unset($this->accounts, $this->mutations, $this->openingBalance);
    }
}; ?>

<div>
    <x-page-header title="Kas" :description="($this->activeLevel ? 'Jenjang '.$this->activeLevel->name : 'Semua jenjang').' · Saldo dan mutasi setiap akun kas.'">
        <x-slot:actions>
            @if ($this->canManage)
                <button type="button" class="btn btn-outline btn-primary" wire:click="openTransfer">Transfer antar akun</button>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="mb-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="card border border-primary bg-primary/5">
            <div class="card-body gap-1 p-4">
                <div class="text-sm text-base-content/70">Total saldo</div>
                <div class="text-2xl font-semibold">{{ \Illuminate\Support\Number::rupiah($this->accounts->sum('balance')) }}</div>
                <div class="text-xs text-base-content/60">{{ $this->accounts->count() }} akun</div>
            </div>
        </div>
        @foreach ($this->accounts as $account)
            <button
                type="button"
                wire:key="account-card-{{ $account->id }}"
                wire:click="$set('accountFilter', {{ $accountFilter === $account->id ? 'null' : $account->id }})"
                @class(['card border text-start transition hover:border-primary', 'border-primary ring-1 ring-primary' => $accountFilter === $account->id, 'border-base-300' => $accountFilter !== $account->id])
            >
                <div class="card-body gap-1 p-4">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-sm font-medium">{{ $account->name }}</span>
                        <span class="badge badge-outline badge-xs">{{ $account->level->code }}</span>
                    </div>
                    <div @class(['text-xl font-semibold', 'text-error' => $account->balance < 0])>{{ \Illuminate\Support\Number::rupiah($account->balance) }}</div>
                    <div class="text-xs text-base-content/60">{{ $account->type->label() }}{{ $account->is_active ? '' : ' · nonaktif' }}</div>
                    @if ($this->canManage && (int) $account->balance === 0)
                        <span class="link link-primary text-xs" wire:click.stop="openOpening({{ $account->id }})">Isi saldo awal</span>
                    @endif
                </div>
            </button>
        @endforeach
    </div>

    <div class="mb-3 flex flex-wrap items-center gap-2">
        <h2 class="me-auto font-semibold">Mutasi {{ $this->selectedAccount?->name ?? 'semua akun' }}</h2>
        <input type="date" class="input input-sm w-40" wire:model.live="from" aria-label="Dari tanggal" />
        <span class="text-sm">s.d.</span>
        <input type="date" class="input input-sm w-40" wire:model.live="until" aria-label="Sampai tanggal" />
    </div>

    <div class="overflow-x-auto rounded-box border border-base-300">
        <table class="table table-sm">
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th>No.</th>
                    <th>Jenis</th>
                    <th>Keterangan</th>
                    @unless ($this->selectedAccount)<th>Akun</th>@endunless
                    <th class="text-end">Masuk</th>
                    <th class="text-end">Keluar</th>
                    <th class="text-end">Saldo</th>
                </tr>
            </thead>
            <tbody>
                <tr class="bg-base-200">
                    <td colspan="{{ $this->selectedAccount ? 6 : 7 }}" class="text-sm">Saldo sebelum {{ \Illuminate\Support\Carbon::parse($from)->translatedFormat('j M Y') }}</td>
                    <td class="text-end font-mono font-semibold">{{ \Illuminate\Support\Number::rupiah($this->openingBalance) }}</td>
                </tr>
                @forelse ($this->mutations as $mutation)
                    <tr wire:key="mutation-{{ $mutation->id }}" @class(['text-base-content/50' => $mutation->isCancelled() || $mutation->isReversal()])>
                        <td class="whitespace-nowrap">{{ $mutation->transacted_on->translatedFormat('j M Y') }}</td>
                        <td class="font-mono text-xs">{{ $mutation->number }}</td>
                        <td class="text-sm">{{ $mutation->isReversal() ? 'Pembatalan' : ($mutation->category?->name ?? $mutation->type->label()) }}</td>
                        <td class="text-sm">{{ $mutation->description }}</td>
                        @unless ($this->selectedAccount)<td class="text-sm">{{ $mutation->cashAccount->name }}</td>@endunless
                        <td class="text-end font-mono text-success">{{ $mutation->direction === \App\Enums\CashDirection::In ? \Illuminate\Support\Number::rupiah($mutation->amount) : '' }}</td>
                        <td class="text-end font-mono text-error">{{ $mutation->direction === \App\Enums\CashDirection::Out ? \Illuminate\Support\Number::rupiah($mutation->amount) : '' }}</td>
                        <td class="text-end font-mono">{{ \Illuminate\Support\Number::rupiah($mutation->running_balance) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="py-6 text-center text-base-content/60">Tidak ada mutasi pada periode ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($this->canManage)
        <x-modal name="transfer" title="Transfer antar akun">
            <form wire:submit="transfer" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-select wire:model="fromAccountId" label="Dari akun" required>
                        @foreach ($this->accounts->where('is_active', true) as $account)
                            <option value="{{ $account->id }}" wire:key="from-{{ $account->id }}">{{ $account->name }} ({{ \Illuminate\Support\Number::rupiah($account->balance) }})</option>
                        @endforeach
                    </x-select>
                    <x-select wire:model="toAccountId" label="Ke akun" required>
                        @foreach ($this->accounts->where('is_active', true) as $account)
                            <option value="{{ $account->id }}" wire:key="to-{{ $account->id }}">{{ $account->name }}</option>
                        @endforeach
                    </x-select>
                    <x-input wire:model="transferAmount" label="Nominal (Rp)" type="number" min="1" required />
                    <x-input wire:model="transferDate" label="Tanggal" type="date" required />
                </div>
                <x-input wire:model="transferNote" label="Keterangan" required />
                <div class="modal-action">
                    <button type="button" class="btn" x-on:click="$el.closest('dialog').close()">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan transfer</button>
                </div>
            </form>
        </x-modal>

        <x-modal name="opening-balance" title="Saldo awal akun">
            <form wire:submit="recordOpening" class="space-y-4">
                <p class="text-sm text-base-content/70">Isi saldo yang sudah ada sebelum sistem dipakai. Saldo awal hanya bisa dicatat sekali per akun.</p>
                <x-input wire:model="openingAmount" label="Saldo awal (Rp)" type="number" min="1" required />
                <x-input wire:model="openingDate" label="Per tanggal" type="date" required />
                <div class="modal-action">
                    <button type="button" class="btn" x-on:click="$el.closest('dialog').close()">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
