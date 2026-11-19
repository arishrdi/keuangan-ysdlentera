<?php

use App\Enums\BillStatus;
use App\Models\Bill;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Cicilan')] class extends Component {
    /**
     * Installable bills of every linked child.
     *
     * @return Collection<int, Bill>
     */
    #[Computed]
    public function bills(): Collection
    {
        return Bill::query()
            ->with(['student', 'paymentCategory', 'allocations.payment'])
            ->whereIn('student_id', auth()->user()->students()->pluck('students.id'))
            ->whereHas('paymentCategory', fn ($query) => $query->where('is_installable', true))
            ->where('status', '!=', BillStatus::Cancelled)
            ->orderBy('student_id')
            ->orderBy('id')
            ->get();
    }
}; ?>

<div>
    <x-page-header title="Cicilan" description="Perkembangan pembayaran tagihan yang boleh dicicil (DPP, Bangunan, dan lainnya)." />

    <div class="grid gap-4 md:grid-cols-2">
        @forelse ($this->bills as $bill)
            @php
                $verified = $bill->allocations->filter(fn ($allocation) => $allocation->payment->status === \App\Enums\PaymentStatus::Verified);
            @endphp
            <div class="card border border-base-300" wire:key="installment-{{ $bill->id }}">
                <div class="card-body gap-2">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <h3 class="font-semibold">{{ $bill->description }}</h3>
                            <p class="text-sm text-base-content/70">{{ $bill->student->name }}</p>
                        </div>
                        <span class="badge badge-sm {{ $bill->status->badgeClass() }}">{{ $bill->status->label() }}</span>
                    </div>
                    <progress class="progress progress-primary" value="{{ $bill->paid_amount }}" max="{{ max($bill->netAmount(), 1) }}"></progress>
                    <div class="grid grid-cols-3 text-sm">
                        <div><div class="text-xs text-base-content/60">Total</div><div class="font-mono">{{ \Illuminate\Support\Number::rupiah($bill->netAmount()) }}</div></div>
                        <div><div class="text-xs text-base-content/60">Terbayar ({{ $verified->count() }}×)</div><div class="font-mono text-success">{{ \Illuminate\Support\Number::rupiah($bill->paid_amount) }}</div></div>
                        <div><div class="text-xs text-base-content/60">Sisa</div><div class="font-mono text-error">{{ \Illuminate\Support\Number::rupiah($bill->remainingAmount()) }}</div></div>
                    </div>
                    @if ($bill->due_date && $bill->remainingAmount() > 0)
                        <p class="text-xs text-base-content/60">Jatuh tempo pelunasan: {{ $bill->due_date->translatedFormat('j F Y') }}</p>
                    @endif
                </div>
            </div>
        @empty
            <div class="rounded-box border border-dashed border-base-300 p-6 text-sm text-base-content/70 md:col-span-2">Tidak ada tagihan cicilan.</div>
        @endforelse
    </div>
</div>
