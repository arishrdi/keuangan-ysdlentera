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
        return auth()->user()->students()->with('level')->withSavingsBalance()->orderBy('name')->get();
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
    <x-page-header title="Tabungan" description="Saldo dan riwayat tabungan anak. Setoran dan penarikan dilakukan di bendahara sekolah." />

    @if ($this->children->isEmpty())
        <div class="rounded-box border border-dashed border-base-300 p-6 text-sm text-base-content/70">Belum ada siswa yang terhubung dengan akun Anda.</div>
    @else
        <div class="mb-4 grid gap-3 sm:grid-cols-2">
            @foreach ($this->children as $child)
                <button
                    type="button"
                    wire:key="child-{{ $child->id }}"
                    wire:click="$set('studentId', {{ $child->id }})"
                    @class(['card border text-start', 'border-primary bg-primary/5' => $child->id === $studentId, 'border-base-300' => $child->id !== $studentId])
                >
                    <div class="card-body gap-1 p-4">
                        <div class="text-sm text-base-content/70">{{ $child->name }} · {{ $child->level->name }}</div>
                        <div class="text-2xl font-semibold">{{ \Illuminate\Support\Number::rupiah((int) $child->savings_balance) }}</div>
                    </div>
                </button>
            @endforeach
        </div>

        <h2 class="mb-2 font-semibold">Riwayat {{ $this->student?->name }}</h2>
        <ul class="divide-y divide-base-300 rounded-box border border-base-300">
            @forelse ($this->transactions as $transaction)
                <li class="flex items-center justify-between gap-3 p-3" wire:key="savings-{{ $transaction->id }}">
                    <div>
                        <div class="font-medium">{{ $transaction->type->label() }}</div>
                        <div class="text-xs text-base-content/60">{{ $transaction->transacted_on->translatedFormat('j M Y') }}{{ $transaction->notes ? ' · '.$transaction->notes : '' }}</div>
                    </div>
                    <div @class(['font-mono', 'text-success' => $transaction->direction === \App\Enums\CashDirection::In, 'text-error' => $transaction->direction === \App\Enums\CashDirection::Out])>
                        {{ $transaction->direction === \App\Enums\CashDirection::In ? '+' : '−' }}{{ \Illuminate\Support\Number::rupiah($transaction->amount) }}
                    </div>
                </li>
            @empty
                <li class="p-6 text-center text-sm text-base-content/60">Belum ada transaksi tabungan.</li>
            @endforelse
        </ul>
    @endif
</div>
