<?php

use App\Actions\Budgets\CompleteBudgetProposal;
use App\Actions\Cash\CashLedger;
use App\Actions\Budgets\DecideBudgetProposal;
use App\Actions\Budgets\StartBudgetDisbursement;
use App\Actions\Budgets\SubmitBudgetProposal;
use App\Enums\BudgetAction;
use App\Enums\BudgetStatus;
use App\Enums\TransactionCategoryType;
use App\Models\BudgetProposal;
use App\Models\CashAccount;
use App\Models\TransactionCategory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public BudgetProposal $budgetProposal;

    public ?int $expenseAccountId = null;

    public ?int $expenseCategoryId = null;

    public ?int $expenseAmount = null;

    public string $expenseDate = '';

    public string $expenseCounterparty = '';

    public string $expenseDescription = '';

    public ?UploadedFile $expenseProof = null;

    public ?int $cancellingExpenseId = null;

    public string $expenseCancellationReason = '';

    public string $notes = '';

    public string $completionNotes = '';

    public function mount(BudgetProposal $budgetProposal): void
    {
        $this->authorize('access-level', $budgetProposal->level_id);

        abort_if($budgetProposal->status === BudgetStatus::Draft && ! Gate::allows('manage-budgets'), 404);
    }

    #[Computed]
    public function proposal(): BudgetProposal
    {
        return $this->budgetProposal->fresh(['level', 'category', 'creator', 'items', 'attachments', 'approvals.user', 'expenses.cashAccount'])->loadSum(
            ['expenses as realized_amount' => fn ($query) => $query->whereNull('cancelled_at')],
            'amount',
        );
    }

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('manage-budgets');
    }

    #[Computed]
    public function canDecide(): bool
    {
        $role = $this->proposal->status->awaitingRole();

        return $role !== null && auth()->user()->hasRole($role);
    }

    /**
     * Cash accounts of the budget's level that the expense can be paid from.
     *
     * @return Collection<int, CashAccount>
     */
    #[Computed]
    public function expenseAccounts(): Collection
    {
        $level = $this->budgetProposal->level;

        return CashAccount::query()->availableTo($level)->active()->withBalance($level)->orderBy('type', 'desc')->orderBy('name')->get();
    }

    /**
     * @return Collection<int, TransactionCategory>
     */
    #[Computed]
    public function expenseCategories(): Collection
    {
        return TransactionCategory::query()->ofType(TransactionCategoryType::Expense)->where('is_active', true)->orderBy('name')->get();
    }

    public function openExpense(): void
    {
        $this->authorize('manage-budgets');
        abort_unless($this->proposal->status->acceptsExpenses(), 403);

        $this->resetValidation();
        $this->reset('expenseCategoryId', 'expenseAmount', 'expenseCounterparty', 'expenseProof');
        $this->expenseAccountId = $this->expenseAccounts->first()?->id;
        $this->expenseDate = now()->toDateString();
        $this->expenseDescription = $this->proposal->title;

        $this->dispatch('open-modal', name: 'budget-expense');
    }

    public function recordExpense(CashLedger $ledger): void
    {
        $this->authorize('manage-budgets');
        abort_unless($this->proposal->status->acceptsExpenses(), 403);

        $this->validate([
            'expenseAccountId' => ['required', 'integer', Rule::in($this->expenseAccounts->modelKeys())],
            'expenseCategoryId' => ['required', 'integer', Rule::in($this->expenseCategories->modelKeys())],
            'expenseAmount' => ['required', 'integer', 'min:1'],
            'expenseDate' => ['required', 'date', 'before_or_equal:today'],
            'expenseCounterparty' => ['nullable', 'string', 'max:255'],
            'expenseDescription' => ['required', 'string', 'max:255'],
            'expenseProof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:2048'],
        ], [], [
            'expenseAccountId' => 'akun kas',
            'expenseCategoryId' => 'kategori',
            'expenseAmount' => 'nominal',
            'expenseDate' => 'tanggal',
            'expenseCounterparty' => 'dibayarkan kepada',
            'expenseDescription' => 'keterangan',
            'expenseProof' => 'bukti',
        ]);

        try {
            $entry = $ledger->recordExpense(
                $this->expenseAccounts->firstWhere('id', $this->expenseAccountId),
                $this->expenseCategories->firstWhere('id', $this->expenseCategoryId),
                $this->expenseAmount,
                Carbon::parse($this->expenseDate),
                $this->expenseDescription,
                $this->expenseCounterparty ?: null,
                $this->expenseProof,
                budget: $this->budgetProposal,
                level: $this->budgetProposal->level,
            );
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key) => [
                ['amount' => 'expenseAmount', 'accountId' => 'expenseAccountId', 'categoryId' => 'expenseCategoryId', 'budgetId' => 'budget'][$key] ?? $key => $messages,
            ])->all());
        }

        $this->reset('expenseProof');
        $this->dispatch('close-modal', name: 'budget-expense');
        $this->dispatch('toast', variant: 'success', text: 'Pengeluaran '.$entry->number.' dicatat.');
        unset($this->proposal, $this->expenseAccounts);
    }

    public function confirmCancelExpense(int $expenseId): void
    {
        $this->authorize('manage-budgets');
        $this->resetValidation();

        $this->cancellingExpenseId = $expenseId;
        $this->expenseCancellationReason = '';

        $this->dispatch('open-modal', name: 'budget-expense-cancel');
    }

    public function cancelExpense(CashLedger $ledger): void
    {
        $this->authorize('manage-budgets');

        $this->validate(['expenseCancellationReason' => ['required', 'string', 'max:255']], [], ['expenseCancellationReason' => 'alasan pembatalan']);

        $expense = $this->budgetProposal->expenses()->whereNull('cancelled_at')->findOrFail($this->cancellingExpenseId);

        $ledger->cancel($expense, auth()->user(), $this->expenseCancellationReason);

        $this->dispatch('close-modal', name: 'budget-expense-cancel');
        $this->dispatch('toast', variant: 'success', text: 'Pengeluaran '.$expense->number.' dibatalkan.');
        unset($this->proposal, $this->expenseAccounts);
    }

    public function decide(string $decision, DecideBudgetProposal $decideBudgetProposal): void
    {
        abort_unless($this->canDecide, 403);
        $this->resetValidation();

        $decideBudgetProposal->handle($this->proposal, auth()->user(), BudgetAction::from($decision), $this->notes ?: null);

        $this->reset('notes');
        $this->dispatch('toast', variant: 'success', text: 'Keputusan disimpan. Status: '.$this->proposal->fresh()->status->label().'.');
        unset($this->proposal, $this->canDecide);
    }

    public function submit(SubmitBudgetProposal $submitBudgetProposal): void
    {
        $this->authorize('manage-budgets');

        $submitBudgetProposal->handle($this->proposal, auth()->user());

        $this->dispatch('toast', variant: 'success', text: 'Pengajuan dikirim ke Kepala Sekolah.');
        unset($this->proposal);
    }

    public function startDisbursement(StartBudgetDisbursement $startBudgetDisbursement): void
    {
        $this->authorize('manage-budgets');

        $startBudgetDisbursement->handle($this->proposal, auth()->user());

        $this->dispatch('toast', variant: 'success', text: 'Status anggaran: Dalam Proses Pencairan.');
        unset($this->proposal);
    }

    public function complete(CompleteBudgetProposal $completeBudgetProposal): void
    {
        $this->authorize('manage-budgets');

        $completeBudgetProposal->handle($this->proposal, auth()->user(), $this->completionNotes ?: null);

        $this->dispatch('close-modal', name: 'complete-budget');
        $this->dispatch('toast', variant: 'success', text: 'Anggaran diselesaikan.');
        unset($this->proposal);
    }

    public function render(): mixed
    {
        return $this->view()->title('Anggaran '.($this->budgetProposal->number ?? $this->budgetProposal->title));
    }
}; ?>

<div>
    @php
        $proposal = $this->proposal;
        $realized = (int) $proposal->realized_amount;
    @endphp

    <a href="{{ route('budgets.index') }}" class="btn btn-ghost btn-sm mb-4" wire:navigate>
        <x-icon name="arrow-left" class="size-4" /> Kembali ke daftar anggaran
    </a>

    <x-page-header
        :title="$proposal->title"
        :meta="[$proposal->number ?? 'Draft', $proposal->level->name, $proposal->category->name]"
        :description="'Diajukan oleh '.($proposal->creator?->name ?? '-').($proposal->submitted_at ? ' pada '.$proposal->submitted_at->translatedFormat('j F Y H:i') : '')"
    >
        <x-slot:actions>
            <span class="badge badge-lg {{ $proposal->status->badgeClass() }}">{{ $proposal->status->label() }}</span>
            @if ($this->canManage && $proposal->status->isEditable())
                <a href="{{ route('budgets.edit', $proposal) }}" class="btn btn-sm" wire:navigate>
                    <x-icon name="pencil-square" class="size-4" /> Ubah
                </a>
                <x-confirm-button action="submit()" title="Kirim pengajuan" message="Kirim pengajuan ke Kepala Sekolah? Setelah dikirim, pengajuan tidak bisa diubah kecuali diminta revisi." confirm-label="Kirim" class="btn btn-sm btn-primary">Kirim pengajuan</x-confirm-button>
            @endif
        </x-slot:actions>
    </x-page-header>

    @error('proposal') <div role="alert" class="alert alert-error mb-4"><span>{{ $message }}</span></div> @enderror
    @error('decision') <div role="alert" class="alert alert-error mb-4"><span>{{ $message }}</span></div> @enderror

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="card border border-base-300">
                <div class="card-body">
                    <h2 class="card-title text-base">Rincian kebutuhan</h2>
                    <div class="overflow-x-auto">
                        <table class="table table-sm">
                            <thead>
                                <tr><th>No</th><th>Nama barang/jasa</th><th class="text-end">Jumlah</th><th class="text-end">Harga satuan</th><th class="text-end">Total</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($proposal->items as $item)
                                    <tr wire:key="item-{{ $item->id }}">
                                        <td>{{ $loop->iteration }}</td>
                                        <td>{{ $item->name }}</td>
                                        <td class="text-end">{{ $item->quantity }} {{ $item->unit }}</td>
                                        <td class="text-end font-mono">{{ \Illuminate\Support\Number::rupiah($item->unit_price) }}</td>
                                        <td class="text-end font-mono">{{ \Illuminate\Support\Number::rupiah($item->subtotal) }}</td>
                                    </tr>
                                @endforeach
                                <tr class="font-semibold">
                                    <td colspan="4" class="text-end">Total</td>
                                    <td class="text-end font-mono">{{ \Illuminate\Support\Number::rupiah($proposal->total_amount) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <h3 class="mt-4 font-semibold">Alasan pengajuan</h3>
                    <p class="whitespace-pre-line text-sm">{{ $proposal->reason }}</p>

                    <h3 class="mt-4 font-semibold">Lampiran</h3>
                    <ul class="space-y-1 text-sm">
                        @forelse ($proposal->attachments as $attachment)
                            <li wire:key="attachment-{{ $attachment->id }}">
                                <a href="{{ route('budgets.attachment', [$proposal, $attachment]) }}" class="link">{{ $attachment->original_name }}</a>
                                <span class="text-base-content/60">({{ \Illuminate\Support\Number::fileSize($attachment->size) }})</span>
                            </li>
                        @empty
                            <li class="text-base-content/60">Tidak ada lampiran.</li>
                        @endforelse
                    </ul>
                </div>
            </div>

            @if ($proposal->status->acceptsExpenses() || $proposal->status === \App\Enums\BudgetStatus::Completed)
                <div class="card border border-base-300">
                    <div class="card-body">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h2 class="card-title text-base">Realisasi</h2>
                            @if ($this->canManage && $proposal->status->acceptsExpenses())
                                <div class="flex gap-2">
                                    @if ($proposal->status === \App\Enums\BudgetStatus::Approved)
                                        <button type="button" class="btn btn-sm" wire:click="startDisbursement">Mulai pencairan</button>
                                    @endif
                                    <button type="button" class="btn btn-sm btn-primary" wire:click="openExpense">Catat pengeluaran</button>
                                    <button type="button" class="btn btn-sm btn-outline" x-data x-on:click="$dispatch('open-modal', 'complete-budget')">Selesaikan</button>
                                </div>
                            @endif
                        </div>
                        <dl class="mt-1 grid grid-cols-3 gap-4">
                            <div><dt class="kpi-label">Disetujui</dt><dd class="mt-1 text-lg font-semibold">{{ \Illuminate\Support\Number::rupiah($proposal->total_amount) }}</dd></div>
                            <div><dt class="kpi-label">Terpakai</dt><dd class="mt-1 text-lg font-semibold">{{ \Illuminate\Support\Number::rupiah($realized) }}</dd></div>
                            <div><dt class="kpi-label">Sisa</dt><dd class="mt-1 text-lg font-semibold text-primary">{{ \Illuminate\Support\Number::rupiah($proposal->total_amount - $realized) }}</dd></div>
                        </dl>
                        <progress class="progress progress-primary h-2.5" value="{{ $realized }}" max="{{ max($proposal->total_amount, 1) }}"></progress>
                        <table class="table table-sm mt-2">
                            <thead><tr><th>Tanggal</th><th>No.</th><th>Keterangan</th><th>Akun</th><th class="text-end">Jumlah</th>@if ($this->canManage)<th></th>@endif</tr></thead>
                            <tbody>
                                @forelse ($proposal->expenses as $expense)
                                    <tr wire:key="expense-{{ $expense->id }}" @class(['opacity-50' => $expense->isCancelled()])>
                                        <td>{{ $expense->transacted_on->translatedFormat('j M Y') }}</td>
                                        <td class="font-mono text-xs">{{ $expense->number }}</td>
                                        <td>{{ $expense->description }}{{ $expense->isCancelled() ? ' (dibatalkan)' : '' }}</td>
                                        <td class="text-sm">{{ $expense->cashAccount->name }}</td>
                                        <td @class(['text-end font-mono', 'line-through' => $expense->isCancelled()])>{{ \Illuminate\Support\Number::rupiah($expense->amount) }}</td>
                                        @if ($this->canManage)
                                            <td class="text-end">
                                                @unless ($expense->isCancelled())
                                                    <button type="button" class="btn btn-xs btn-ghost text-error" wire:click="confirmCancelExpense({{ $expense->id }})">Batalkan</button>
                                                @endunless
                                            </td>
                                        @endif
                                    </tr>
                                @empty
                                    <tr><td colspan="{{ $this->canManage ? 6 : 5 }}" class="text-center text-base-content/60">Belum ada pengeluaran dari anggaran ini.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>

        <div class="space-y-6">
            @if ($this->canDecide)
                <div class="card border-2 border-warning">
                    <div class="card-body gap-3">
                        <h2 class="card-title text-base">Keputusan {{ $proposal->status === \App\Enums\BudgetStatus::AwaitingPrincipal ? 'tahap 1 (Kepala Sekolah)' : 'tahap 2 (Yayasan)' }}</h2>
                        <x-textarea wire:model="notes" label="Catatan (wajib bila menolak atau minta revisi)" rows="3" />
                        <div class="grid gap-2">
                            <x-confirm-button action="decide('disetujui')" title="Setujui pengajuan" message="Setujui pengajuan anggaran ini?" confirm-label="Setujui" class="btn btn-primary">Setujui</x-confirm-button>
                            <button type="button" class="btn btn-info btn-outline" wire:click="decide('minta_revisi')">Minta revisi</button>
                            <x-confirm-button action="decide('ditolak')" title="Tolak pengajuan" message="Penolakan bersifat final dan pengajuan tidak bisa dikirim ulang." confirm-label="Tolak" danger class="btn btn-error btn-outline">Tolak</x-confirm-button>
                        </div>
                    </div>
                </div>
            @endif

            <div class="card border border-base-300">
                <div class="card-body">
                    <h2 class="card-title text-base">Riwayat persetujuan</h2>
                    <ul class="timeline timeline-vertical timeline-compact">
                        @forelse ($proposal->approvals as $approval)
                            <li wire:key="approval-{{ $approval->id }}">
                                @unless ($loop->first)<hr />@endunless
                                <div class="timeline-middle"><span class="block size-3 rounded-full border-2 border-primary bg-base-100"></span></div>
                                <div class="timeline-end mb-4 text-sm">
                                    <div class="font-medium">{{ $approval->action->label() }}{{ $approval->stage ? ' (tahap '.$approval->stage.')' : '' }}</div>
                                    <div class="text-base-content/70">{{ $approval->user?->name ?? 'Sistem' }}{{ $approval->role ? ' ('.$approval->role->label().')' : '' }}</div>
                                    <div class="text-xs text-base-content/50">{{ $approval->created_at->translatedFormat('j M Y H:i') }}, nilai {{ \Illuminate\Support\Number::rupiah($approval->total_amount) }}</div>
                                    @if ($approval->notes)
                                        <div class="mt-1 rounded-box bg-base-200 p-2">{{ $approval->notes }}</div>
                                    @endif
                                </div>
                                @unless ($loop->last)<hr />@endunless
                            </li>
                        @empty
                            <li class="text-sm text-base-content/60">Belum dikirim.</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        </div>
    </div>

    @if ($this->canManage)
        <x-modal name="complete-budget" title="Selesaikan anggaran">
            <form wire:submit="complete" class="space-y-4">
                <p class="text-sm text-base-content/70">
                    Realisasi {{ \Illuminate\Support\Number::rupiah($realized) }} dari {{ \Illuminate\Support\Number::rupiah($proposal->total_amount) }}.
                    Sisa {{ \Illuminate\Support\Number::rupiah($proposal->total_amount - $realized) }} akan tercatat dan anggaran tidak bisa dipakai lagi.
                </p>
                <x-input wire:model="completionNotes" label="Catatan (opsional)" />
                <div class="modal-action">
                    <button type="button" class="btn" x-on:click="$el.closest('dialog').close()">Batal</button>
                    <button type="submit" class="btn btn-primary">Selesaikan</button>
                </div>
            </form>
        </x-modal>

        <x-modal name="budget-expense" :title="'Catat pengeluaran — '.$proposal->title">
            <form wire:submit="recordExpense" class="space-y-4">
                <p class="text-sm text-base-content/70">Sisa anggaran <span class="font-semibold text-base-content">{{ \Illuminate\Support\Number::rupiah($proposal->total_amount - $realized) }}</span>. Pengeluaran tidak boleh melebihi sisa ini.</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div x-data="{ balances: @js($this->expenseAccounts->mapWithKeys(fn ($account) => [$account->id => \Illuminate\Support\Number::rupiah($account->balance)])) }">
                        <x-select wire:model="expenseAccountId" label="Dibayar dari akun" placeholder="Pilih akun" required>
                            @foreach ($this->expenseAccounts as $account)
                                <option value="{{ $account->id }}" wire:key="expense-account-{{ $account->id }}">{{ $account->name }}</option>
                            @endforeach
                        </x-select>
                        <p class="mt-1 text-xs text-base-content/60" x-show="balances[$wire.expenseAccountId]" x-cloak>
                            Saldo saat ini <span class="font-semibold text-base-content" x-text="balances[$wire.expenseAccountId]"></span>
                        </p>
                    </div>
                    <x-select wire:model="expenseCategoryId" label="Kategori" placeholder="Pilih kategori" required>
                        @foreach ($this->expenseCategories as $category)
                            <option value="{{ $category->id }}" wire:key="expense-category-{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </x-select>
                    <x-money-input wire:model="expenseAmount" label="Nominal" required />
                    <x-input wire:model="expenseDate" label="Tanggal" type="date" required />
                </div>
                <x-input wire:model="expenseCounterparty" label="Dibayarkan kepada (opsional)" />
                <x-input wire:model="expenseDescription" label="Keterangan" required />
                <fieldset class="fieldset p-0">
                    <label for="expense-proof" class="fieldset-legend pt-0">Bukti / nota (opsional, maks. 2 MB)</label>
                    <input id="expense-proof" type="file" accept="image/*,application/pdf" class="file-input w-full" wire:model="expenseProof" />
                    @error('expenseProof') <p class="label text-error">{{ $message }}</p> @enderror
                </fieldset>
                @error('budget') <p class="text-sm text-error">{{ $message }}</p> @enderror

                <div class="modal-action">
                    <button type="button" class="btn" x-on:click="$el.closest('dialog').close()">Batal</button>
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Simpan</button>
                </div>
            </form>
        </x-modal>

        <x-modal name="budget-expense-cancel" title="Batalkan pengeluaran">
            <form wire:submit="cancelExpense" class="space-y-4">
                <p class="text-sm text-base-content/70">Transaksi tidak dihapus. Sistem membuat mutasi pembalik dan mencatat alasan pembatalan.</p>
                <x-input wire:model="expenseCancellationReason" label="Alasan pembatalan" required />
                <div class="modal-action">
                    <button type="button" class="btn" x-on:click="$el.closest('dialog').close()">Kembali</button>
                    <button type="submit" class="btn btn-error">Batalkan pengeluaran</button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
