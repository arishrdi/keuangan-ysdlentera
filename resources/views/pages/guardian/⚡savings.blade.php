<?php

use App\Models\SavingsTransaction;
use App\Models\Student;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Tabungan Anak')] class extends Component {
    #[Url(as: 'anak')]
    public ?int $studentId = null;

    public function mount(): void
    {
        $this->studentId = $this->children->firstWhere('id', $this->studentId)?->id ?? $this->children->first()?->id;
    }

    /**
     * @return Collection<int, Student>
     */
    #[Computed]
    public function children(): Collection
    {
        return auth()->user()->students()->with(['level', 'currentEnrollment.classroom'])->withSavingsBalance()->orderBy('name')->get();
    }

    #[Computed]
    public function student(): ?Student
    {
        return $this->children->firstWhere('id', $this->studentId);
    }

    /**
     * Entries of the selected child, newest first, without cancelled entries and their reversals.
     *
     * @return Collection<int, SavingsTransaction>
     */
    #[Computed]
    public function transactions(): Collection
    {
        if ($this->student === null) {
            return new Collection;
        }

        return $this->student->savingsTransactions()
            ->whereNull('reversal_of_id')
            ->whereNull('cancelled_at')
            ->latest('transacted_on')
            ->latest('id')
            ->limit(50)
            ->get();
    }
}; ?>

<div>
    <x-guardian.band title="Tabungan" description="Setoran dan penarikan dicatat oleh petugas yayasan.">
        @if ($this->children->count() > 1)
            <x-guardian.child-pills :children="$this->children" :active="$studentId" />
        @endif
    </x-guardian.band>

    <x-guardian.overlap>
        @if ($this->children->isEmpty())
            <div class="panel p-6 text-sm text-base-content/70 shadow-sm">Belum ada siswa yang terhubung dengan akun Anda.</div>
        @else
            @foreach ($this->children as $child)
                @if ($child->id === $studentId)
                    <section class="panel p-5 shadow-sm" wire:key="child-{{ $child->id }}" aria-label="Saldo tabungan {{ $child->name }}">
                        <p class="text-sm text-base-content/70">Saldo tabungan</p>
                        <p class="mt-1 text-4xl font-bold tracking-tight text-primary">{{ \Illuminate\Support\Number::rupiah((int) $child->savings_balance) }}</p>
                        <p class="mt-2 text-sm text-base-content/65">{{ $child->name }}, {{ $child->level->name }}</p>
                    </section>
                @endif
            @endforeach

            <section class="panel px-5 py-2" aria-labelledby="savings-history">
                <h2 id="savings-history" class="pb-1 pt-3 text-sm font-semibold">Riwayat</h2>
                <ul class="divide-y divide-base-300">
                    @forelse ($this->transactions as $transaction)
                        <li class="flex items-center gap-3 py-3" wire:key="savings-{{ $transaction->id }}">
                            <x-guardian.date-tile :date="$transaction->transacted_on" />
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold">{{ $transaction->type->label() }}</p>
                                @if ($transaction->notes)
                                    <p class="truncate text-xs text-base-content/60">{{ $transaction->notes }}</p>
                                @endif
                            </div>
                            <p @class(['shrink-0 font-bold', 'text-success' => $transaction->direction === \App\Enums\CashDirection::In, 'text-error' => $transaction->direction === \App\Enums\CashDirection::Out])>
                                {{ $transaction->direction === \App\Enums\CashDirection::In ? '+' : '−' }}{{ \Illuminate\Support\Number::rupiah($transaction->amount) }}
                            </p>
                        </li>
                    @empty
                        <li class="py-6 text-center text-sm text-base-content/60">Belum ada transaksi tabungan.</li>
                    @endforelse
                </ul>
            </section>
        @endif
    </x-guardian.overlap>
</div>
