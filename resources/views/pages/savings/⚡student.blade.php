<?php

use App\Actions\Savings\SavingsLedger;
use App\Enums\SavingsTransactionType;
use App\Models\SavingsTransaction;
use App\Models\Student;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Student $student;

    public string $type = 'setoran';

    public ?int $amount = null;

    public string $transactedOn = '';

    public string $notes = '';

    public ?int $cancellingId = null;

    public string $cancellationReason = '';

    public function mount(Student $student): void
    {
        $this->student = $student->load('level', 'currentEnrollment.classroom', 'guardians');
    }

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('manage-savings');
    }

    #[Computed]
    public function balance(): int
    {
        return $this->student->savingsBalance();
    }

    #[Computed]
    public function hasOpeningBalance(): bool
    {
        return $this->student->savingsTransactions()
            ->where('type', SavingsTransactionType::OpeningBalance)
            ->whereNull('reversal_of_id')
            ->whereNull('cancelled_at')
            ->exists();
    }

    /**
     * Every entry, newest first, with the balance after it.
     *
     * @return Collection<int, SavingsTransaction>
     */
    #[Computed]
    public function transactions(): Collection
    {
        $balance = 0;

        return $this->student->savingsTransactions()
            ->with('creator')
            ->orderBy('transacted_on')
            ->orderBy('id')
            ->get()
            ->each(function (SavingsTransaction $transaction) use (&$balance): void {
                $balance += $transaction->signedAmount();
                $transaction->setAttribute('running_balance', $balance);
            })
            ->reverse()
            ->values();
    }

    public function open(string $type): void
    {
        $this->authorize('manage-savings');
        $this->resetValidation();

        $this->type = SavingsTransactionType::from($type)->value;
        $this->reset('amount', 'notes');
        $this->transactedOn = now()->toDateString();

        if ($this->type === SavingsTransactionType::Withdrawal->value && $this->student->status !== \App\Enums\StudentStatus::Active) {
            $this->amount = $this->balance;
            $this->notes = 'Penarikan seluruh saldo ('.$this->student->status->label().')';
        }

        $this->dispatch('open-modal', name: 'savings-entry');
    }

    public function save(SavingsLedger $ledger): void
    {
        $this->authorize('manage-savings');

        $this->validate([
            'type' => ['required', Rule::enum(SavingsTransactionType::class)],
            'amount' => ['required', 'integer', 'min:1'],
            'transactedOn' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], ['amount' => 'nominal', 'transactedOn' => 'tanggal', 'notes' => 'catatan']);

        $date = Carbon::parse($this->transactedOn);

        $entry = match (SavingsTransactionType::from($this->type)) {
            SavingsTransactionType::Deposit => $ledger->deposit($this->student, $this->amount, $date, $this->notes ?: null),
            SavingsTransactionType::Withdrawal => $ledger->withdraw($this->student, $this->amount, $date, $this->notes ?: null),
            SavingsTransactionType::OpeningBalance => $ledger->recordOpeningBalance($this->student, $this->amount, $date),
        };

        $this->dispatch('close-modal', name: 'savings-entry');
        $this->dispatch('toast', variant: 'success', text: $entry->type->label().' dicatat ('.$entry->number.').');
        unset($this->balance, $this->transactions, $this->hasOpeningBalance);
    }

    public function confirmCancel(int $transactionId): void
    {
        $this->authorize('manage-savings');
        $this->resetValidation();

        $this->cancellingId = $transactionId;
        $this->cancellationReason = '';

        $this->dispatch('open-modal', name: 'savings-cancel');
    }

    public function cancel(SavingsLedger $ledger): void
    {
        $this->authorize('manage-savings');

        $this->validate(['cancellationReason' => ['required', 'string', 'max:255']], [], ['cancellationReason' => 'alasan pembatalan']);

        $ledger->cancel($this->student->savingsTransactions()->findOrFail($this->cancellingId), auth()->user(), $this->cancellationReason);

        $this->dispatch('close-modal', name: 'savings-cancel');
        $this->dispatch('toast', variant: 'success', text: 'Transaksi tabungan dibatalkan.');
        unset($this->balance, $this->transactions, $this->hasOpeningBalance);
    }

    public function render(): mixed
    {
        return $this->view()->title('Tabungan '.$this->student->name);
    }
}; ?>

<div>
    <a href="{{ route('savings.index') }}" class="btn btn-ghost btn-sm mb-4" wire:navigate>
        <x-icon name="arrow-left" class="size-4" /> Kembali ke daftar tabungan
    </a>

    <x-page-header
        :title="'Tabungan '.$student->name"
        :description="$student->level->name.' · Kelas '.($student->currentEnrollment?->classroom->name ?? '-').' · NIS '.($student->nis ?? '-').' · '.$student->status->label()"
    >
        <x-slot:actions>
            @if ($this->canManage)
                @if ($student->status === \App\Enums\StudentStatus::Active)
                    <button type="button" class="btn btn-primary" wire:click="open('setoran')">Setor</button>
                @endif
                <button type="button" class="btn btn-outline" wire:click="open('penarikan')" @disabled($this->balance === 0)>Tarik</button>
                @unless ($this->hasOpeningBalance)
                    <button type="button" class="btn btn-ghost" wire:click="open('saldo_awal')">Saldo awal</button>
                @endunless
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="card mb-6 w-full border border-primary bg-primary/5 sm:w-80">
        <div class="card-body gap-1 p-4">
            <div class="text-sm text-base-content/70">Saldo tabungan</div>
            <div class="text-3xl font-semibold">{{ \Illuminate\Support\Number::rupiah($this->balance) }}</div>
            @if ($student->status !== \App\Enums\StudentStatus::Active && $this->balance > 0)
                <div class="text-sm text-warning">Siswa sudah tidak aktif. Kembalikan saldo kepada wali.</div>
            @endif
        </div>
    </div>

    <div class="overflow-x-auto rounded-box border border-base-300">
        <table class="table table-sm">
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th>No.</th>
                    <th>Jenis</th>
                    <th>Catatan</th>
                    <th class="text-end">Masuk</th>
                    <th class="text-end">Keluar</th>
                    <th class="text-end">Saldo</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->transactions as $transaction)
                    <tr wire:key="savings-{{ $transaction->id }}" @class(['text-base-content/50' => $transaction->isCancelled() || $transaction->isReversal()])>
                        <td class="whitespace-nowrap">{{ $transaction->transacted_on->translatedFormat('j M Y') }}</td>
                        <td class="font-mono text-xs">{{ $transaction->number }}</td>
                        <td>{{ $transaction->isReversal() ? 'Pembatalan' : $transaction->type->label() }}</td>
                        <td class="text-sm">
                            {{ $transaction->notes ?? '-' }}
                            @if ($transaction->isCancelled())
                                <div class="text-xs text-error">Dibatalkan: {{ $transaction->cancellation_reason }}</div>
                            @endif
                        </td>
                        <td class="text-end font-mono text-success">{{ $transaction->direction === \App\Enums\CashDirection::In ? \Illuminate\Support\Number::rupiah($transaction->amount) : '' }}</td>
                        <td class="text-end font-mono text-error">{{ $transaction->direction === \App\Enums\CashDirection::Out ? \Illuminate\Support\Number::rupiah($transaction->amount) : '' }}</td>
                        <td class="text-end font-mono">{{ \Illuminate\Support\Number::rupiah($transaction->running_balance) }}</td>
                        <td class="text-end">
                            @if ($this->canManage && ! $transaction->isCancelled() && ! $transaction->isReversal())
                                <button type="button" class="btn btn-xs btn-ghost text-error" wire:click="confirmCancel({{ $transaction->id }})">Batalkan</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="py-8 text-center text-base-content/60">Belum ada transaksi tabungan.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($this->canManage)
        <x-modal name="savings-entry" :title="\App\Enums\SavingsTransactionType::from($type)->label().' tabungan'">
            <form wire:submit="save" class="space-y-4">
                <div class="rounded-box bg-base-200 p-3 text-sm">Saldo saat ini: <strong>{{ \Illuminate\Support\Number::rupiah($this->balance) }}</strong></div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-input wire:model="amount" label="Nominal (Rp)" type="number" min="1" required />
                    <x-input wire:model="transactedOn" :label="$type === 'saldo_awal' ? 'Per tanggal' : 'Tanggal'" type="date" required />
                </div>
                @if ($type !== 'saldo_awal')
                    <x-input wire:model="notes" label="Catatan (opsional)" />
                @endif
                <div class="modal-action">
                    <button type="button" class="btn" x-on:click="$el.closest('dialog').close()">Batal</button>
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Simpan</button>
                </div>
            </form>
        </x-modal>

        <x-modal name="savings-cancel" title="Batalkan transaksi tabungan">
            <form wire:submit="cancel" class="space-y-4">
                <p class="text-sm text-base-content/70">Transaksi tidak dihapus. Sistem membuat mutasi pembalik dan mencatat alasannya.</p>
                <x-input wire:model="cancellationReason" label="Alasan pembatalan" required />
                <div class="modal-action">
                    <button type="button" class="btn" x-on:click="$el.closest('dialog').close()">Kembali</button>
                    <button type="submit" class="btn btn-error">Batalkan transaksi</button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
