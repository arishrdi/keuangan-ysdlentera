<?php

use App\Actions\Cash\CashLedger;
use App\Enums\BudgetStatus;
use App\Enums\CashTransactionType;
use App\Enums\TransactionCategoryType;
use App\Models\BudgetProposal;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Level;
use App\Models\TransactionCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * List and record either incomes (pemasukan) or expenses (pengeluaran) of the active level.
 */
new class extends Component {
    use WithFileUploads, WithPagination;

    #[Locked]
    public string $kind = 'pemasukan';

    #[Url(as: 'dari')]
    public string $from = '';

    #[Url(as: 'sampai')]
    public string $until = '';

    #[Url(as: 'kategori')]
    public ?int $categoryFilter = null;

    #[Url(as: 'cari')]
    public string $search = '';

    public ?int $accountId = null;

    public ?int $categoryId = null;

    public ?int $amount = null;

    public string $transactedOn = '';

    public string $counterparty = '';

    public string $description = '';

    public ?UploadedFile $proof = null;

    #[Url(as: 'anggaran')]
    public ?int $budgetId = null;

    public ?int $cancellingId = null;

    public string $cancellationReason = '';

    public function mount(string $kind): void
    {
        $this->kind = TransactionCategoryType::from($kind)->value;
        $this->from = $this->from ?: now()->startOfMonth()->toDateString();
        $this->until = $this->until ?: now()->endOfMonth()->toDateString();

        if ($this->budgetId !== null && $this->canManage && ! $this->isIncome) {
            $requestedBudgetId = $this->budgetId;
            $this->create();
            $this->budgetId = $this->budgets->firstWhere('id', $requestedBudgetId)?->id;
        }
    }

    /**
     * Approved budgets of the active level that expenses may be charged to.
     *
     * @return Collection<int, BudgetProposal>
     */
    #[Computed]
    public function budgets(): Collection
    {
        if ($this->activeLevel === null || $this->isIncome) {
            return new Collection;
        }

        return BudgetProposal::query()
            ->whereBelongsTo($this->activeLevel)
            ->whereIn('status', [BudgetStatus::Approved, BudgetStatus::Disbursing])
            ->withRealizedAmount()
            ->orderBy('number')
            ->get();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'until', 'categoryFilter', 'search'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function isIncome(): bool
    {
        return $this->kind === TransactionCategoryType::Income->value;
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
     * @return array<int, CashTransactionType>
     */
    private function types(): array
    {
        return $this->isIncome ? [CashTransactionType::StudentPayment, CashTransactionType::Income] : [CashTransactionType::Expense];
    }

    /**
     * @return Builder<CashTransaction>
     */
    private function query(): Builder
    {
        return CashTransaction::query()
            ->original()
            ->whereIn('type', $this->types())
            ->when($this->activeLevel, fn ($query, Level $level) => $query->whereBelongsTo($level))
            ->when($this->from !== '', fn ($query) => $query->whereDate('transacted_on', '>=', $this->from))
            ->when($this->until !== '', fn ($query) => $query->whereDate('transacted_on', '<=', $this->until))
            ->when($this->categoryFilter === -1, fn ($query) => $query->where('type', CashTransactionType::StudentPayment))
            ->when($this->categoryFilter > 0, fn ($query) => $query->where('transaction_category_id', $this->categoryFilter))
            ->when($this->search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('description', 'like', '%'.$this->search.'%')
                ->orWhere('counterparty', 'like', '%'.$this->search.'%')
                ->orWhere('number', 'like', '%'.$this->search.'%')));
    }

    /**
     * @return LengthAwarePaginator<int, CashTransaction>
     */
    #[Computed]
    public function entries(): LengthAwarePaginator
    {
        return $this->query()
            ->with(['cashAccount', 'category', 'level', 'creator', 'budgetProposal'])
            ->latest('transacted_on')
            ->latest('id')
            ->paginate(30);
    }

    #[Computed]
    public function total(): int
    {
        return (int) $this->query()->whereNull('cancelled_at')->sum('amount');
    }

    /**
     * @return Collection<int, TransactionCategory>
     */
    #[Computed]
    public function categories(): Collection
    {
        return TransactionCategory::query()->ofType(TransactionCategoryType::from($this->kind))->orderBy('name')->get();
    }

    /**
     * @return Collection<int, CashAccount>
     */
    #[Computed]
    public function accounts(): Collection
    {
        if ($this->activeLevel === null) {
            return new Collection;
        }

        return CashAccount::query()->whereBelongsTo($this->activeLevel)->active()->withBalance()->orderBy('type', 'desc')->orderBy('name')->get();
    }

    public function create(): void
    {
        $this->authorize('manage-cash');

        if ($this->activeLevel === null) {
            $this->dispatch('toast', variant: 'warning', text: 'Pilih jenjang tertentu di kanan atas terlebih dahulu.');

            return;
        }

        $this->resetValidation();
        $this->reset('categoryId', 'amount', 'counterparty', 'description', 'proof', 'budgetId');
        $this->accountId = $this->accounts->first()?->id;
        $this->transactedOn = now()->toDateString();

        $this->dispatch('open-modal', name: 'entry-form-'.$this->kind);
    }

    public function save(CashLedger $ledger): void
    {
        $this->authorize('manage-cash');
        abort_if($this->activeLevel === null, 422);

        $this->validate([
            'accountId' => ['required', 'integer', Rule::in($this->accounts->modelKeys())],
            'categoryId' => ['required', 'integer', Rule::in($this->categories->where('is_active', true)->modelKeys())],
            'amount' => ['required', 'integer', 'min:1'],
            'transactedOn' => ['required', 'date', 'before_or_equal:today'],
            'counterparty' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:255'],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:2048'],
            'budgetId' => ['nullable', 'integer', Rule::in($this->budgets->modelKeys())],
        ], [], [
            'budgetId' => 'anggaran',
            'accountId' => 'akun kas',
            'categoryId' => 'kategori',
            'amount' => 'nominal',
            'transactedOn' => 'tanggal',
            'counterparty' => $this->isIncome ? 'diterima dari' : 'dibayarkan kepada',
            'description' => 'keterangan',
            'proof' => 'bukti',
        ]);

        $arguments = [
            $this->accounts->firstWhere('id', $this->accountId),
            $this->categories->firstWhere('id', $this->categoryId),
            $this->amount,
            Carbon::parse($this->transactedOn),
            $this->description,
            $this->counterparty ?: null,
            $this->proof,
        ];

        $entry = $this->isIncome
            ? $ledger->recordIncome(...$arguments)
            : $ledger->recordExpense(...$arguments, budget: $this->budgets->firstWhere('id', $this->budgetId));

        $this->dispatch('close-modal', name: 'entry-form-'.$this->kind);
        $this->dispatch('toast', variant: 'success', text: ($this->isIncome ? 'Pemasukan' : 'Pengeluaran').' '.$entry->number.' dicatat.');
        $this->reset('proof');
        $this->reset('budgetId');
        unset($this->entries, $this->total, $this->accounts, $this->budgets);
    }

    public function confirmCancel(int $entryId): void
    {
        $this->authorize('manage-cash');
        $this->resetValidation();

        $this->cancellingId = $entryId;
        $this->cancellationReason = '';

        $this->dispatch('open-modal', name: 'entry-cancel-'.$this->kind);
    }

    public function cancel(CashLedger $ledger): void
    {
        $this->authorize('manage-cash');

        $this->validate(['cancellationReason' => ['required', 'string', 'max:255']], [], ['cancellationReason' => 'alasan pembatalan']);

        $entry = $this->query()->findOrFail($this->cancellingId);

        $ledger->cancel($entry, auth()->user(), $this->cancellationReason);

        $this->dispatch('close-modal', name: 'entry-cancel-'.$this->kind);
        $this->dispatch('toast', variant: 'success', text: 'Transaksi '.$entry->number.' dibatalkan.');
        unset($this->entries, $this->total);
    }
}; ?>

<div>
    <x-page-header
        :title="$this->isIncome ? 'Pemasukan' : 'Pengeluaran'"
        :meta="[($this->activeLevel ? 'Jenjang '.$this->activeLevel->name : 'Semua jenjang')]"
        :description="($this->isIncome ? 'Pembayaran siswa masuk otomatis; catat di sini pemasukan lain seperti donasi.' : 'Catat setiap uang yang keluar beserta buktinya.')"
    >
        <x-slot:actions>
            @if ($this->canManage)
                <button type="button" class="btn btn-primary" wire:click="create">
                    <x-icon name="plus" class="size-4" /> Catat {{ $this->isIncome ? 'pemasukan' : 'pengeluaran' }}
                </button>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <input type="date" class="input input-sm w-40" wire:model.live="from" aria-label="Dari tanggal" />
        <span class="text-sm">s.d.</span>
        <input type="date" class="input input-sm w-40" wire:model.live="until" aria-label="Sampai tanggal" />
        <select class="select select-sm w-48" wire:model.live="categoryFilter" aria-label="Kategori">
            <option value="">Semua kategori</option>
            @if ($this->isIncome)
                <option value="-1">Pembayaran siswa</option>
            @endif
            @foreach ($this->categories as $category)
                <option value="{{ $category->id }}" wire:key="filter-category-{{ $category->id }}">{{ $category->name }}</option>
            @endforeach
        </select>
        <label class="input input-sm w-56">
            <x-icon name="magnifying-glass" class="size-4 opacity-60" />
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Cari keterangan / nomor" />
        </label>
        <div class="ms-auto text-end">
            <div class="text-xs text-base-content/60">Total periode ini</div>
            <div @class(['text-xl font-semibold', 'text-success' => $this->isIncome, 'text-error' => ! $this->isIncome])>{{ \Illuminate\Support\Number::rupiah($this->total) }}</div>
        </div>
    </div>

    <div class="overflow-x-auto rounded-box border border-base-300">
        <table class="table table-sm">
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th>No.</th>
                    <th>Kategori</th>
                    <th>Keterangan</th>
                    <th>{{ $this->isIncome ? 'Dari' : 'Kepada' }}</th>
                    <th>Akun</th>
                    <th class="text-end">Jumlah</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->entries as $entry)
                    <tr wire:key="entry-{{ $entry->id }}" @class(['opacity-60' => $entry->isCancelled()])>
                        <td class="whitespace-nowrap">{{ $entry->transacted_on->translatedFormat('j M Y') }}</td>
                        <td class="font-mono text-xs">{{ $entry->number }}</td>
                        <td>{{ $entry->category?->name ?? $entry->type->label() }}</td>
                        <td>
                            {{ $entry->description }}
                            @if ($entry->budgetProposal)
                                <div class="text-xs"><a href="{{ route('budgets.show', $entry->budgetProposal) }}" class="link" wire:navigate>Anggaran {{ $entry->budgetProposal->number }}</a></div>
                            @endif
                            @if ($entry->isCancelled())
                                <div class="text-xs text-error">Dibatalkan: {{ $entry->cancellation_reason }}</div>
                            @endif
                        </td>
                        <td>{{ $entry->counterparty ?? '-' }}</td>
                        <td class="text-sm">{{ $entry->cashAccount->name }}</td>
                        <td @class(['text-end font-mono', 'line-through' => $entry->isCancelled()])>{{ \Illuminate\Support\Number::rupiah($entry->amount) }}</td>
                        <td class="text-end">
                            <div class="flex justify-end gap-1">
                                @if ($entry->proof_path)
                                    <a href="{{ route('finance.proof', $entry) }}" target="_blank" class="btn btn-xs btn-ghost">Bukti</a>
                                @endif
                                @if ($this->canManage && ! $entry->isCancelled() && $entry->type !== \App\Enums\CashTransactionType::StudentPayment)
                                    <button type="button" class="btn btn-xs btn-ghost text-error" wire:click="confirmCancel({{ $entry->id }})">Batalkan</button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="py-8 text-center text-base-content/60">Tidak ada transaksi pada periode ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->entries->links() }}</div>

    @if ($this->canManage)
        <x-modal :name="'entry-form-'.$kind" :title="'Catat '.($this->isIncome ? 'pemasukan' : 'pengeluaran').' '.($this->activeLevel?->name ?? '')">
            <form wire:submit="save" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    {{-- The option shows only the account name; the chosen account's balance sits under the field. --}}
                    <div x-data="{ balances: @js($this->accounts->mapWithKeys(fn ($account) => [$account->id => \Illuminate\Support\Number::rupiah($account->balance)])) }">
                        <x-select wire:model="accountId" :label="$this->isIncome ? 'Masuk ke akun' : 'Dibayar dari akun'" placeholder="Pilih akun" required>
                            @foreach ($this->accounts as $account)
                                <option value="{{ $account->id }}" wire:key="account-{{ $account->id }}">{{ $account->name }}</option>
                            @endforeach
                        </x-select>
                        <p class="mt-1 text-xs text-base-content/60" x-show="balances[$wire.accountId]" x-cloak>
                            Saldo saat ini <span class="font-semibold text-base-content" x-text="balances[$wire.accountId]"></span>
                        </p>
                    </div>
                    <x-select wire:model="categoryId" label="Kategori" placeholder="Pilih kategori" required>
                        @foreach ($this->categories->where('is_active', true) as $category)
                            <option value="{{ $category->id }}" wire:key="form-category-{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </x-select>
                    <x-money-input wire:model="amount" label="Nominal" required />
                    <x-input wire:model="transactedOn" label="Tanggal" type="date" required />
                </div>
                @unless ($this->isIncome)
                    {{-- The option shows only the budget title; its number and remaining amount sit under the field. --}}
                    <div x-data="{ budgets: @js($this->budgets->mapWithKeys(fn ($budget) => [$budget->id => ['number' => $budget->number, 'remaining' => \Illuminate\Support\Number::rupiah($budget->total_amount - (int) $budget->realized_amount)]])) }">
                        <x-select wire:model="budgetId" label="Realisasi dari anggaran (opsional)" placeholder="Tidak terkait anggaran">
                            @foreach ($this->budgets as $budget)
                                <option value="{{ $budget->id }}" wire:key="budget-{{ $budget->id }}">{{ $budget->title }}</option>
                            @endforeach
                        </x-select>
                        <p class="mt-1 text-xs text-base-content/60" x-show="budgets[$wire.budgetId]" x-cloak>
                            <span x-text="budgets[$wire.budgetId]?.number"></span>, sisa anggaran
                            <span class="font-semibold text-base-content" x-text="budgets[$wire.budgetId]?.remaining"></span>
                        </p>
                    </div>
                @endunless
                <x-input wire:model="counterparty" :label="$this->isIncome ? 'Diterima dari (opsional)' : 'Dibayarkan kepada (opsional)'" />
                <x-input wire:model="description" label="Keterangan" required />
                <fieldset class="fieldset p-0">
                    <label for="proof-{{ $kind }}" class="fieldset-legend pt-0">Bukti / nota (opsional, maks. 2 MB)</label>
                    <input id="proof-{{ $kind }}" type="file" accept="image/*,application/pdf" class="file-input w-full" wire:model="proof" />
                    @error('proof') <p class="label text-error">{{ $message }}</p> @enderror
                </fieldset>

                <div class="modal-action">
                    <button type="button" class="btn" x-on:click="$el.closest('dialog').close()">Batal</button>
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Simpan</button>
                </div>
            </form>
        </x-modal>

        <x-modal :name="'entry-cancel-'.$kind" title="Batalkan transaksi">
            <form wire:submit="cancel" class="space-y-4">
                <p class="text-sm text-base-content/70">Transaksi tidak dihapus. Sistem membuat mutasi pembalik dan mencatat alasan pembatalan.</p>
                <x-input wire:model="cancellationReason" label="Alasan pembatalan" required />
                <div class="modal-action">
                    <button type="button" class="btn" x-on:click="$el.closest('dialog').close()">Kembali</button>
                    <button type="submit" class="btn btn-error">Batalkan transaksi</button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
