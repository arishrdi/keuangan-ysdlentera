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
    <x-guardian.band title="Cicilan" description="Perkembangan tagihan yang boleh dicicil, seperti DPP dan Bangunan." />

    <x-guardian.overlap>
        <div class="grid gap-4 md:grid-cols-2">
        @forelse ($this->bills as $bill)
            @php
                $verified = $bill->allocations->filter(fn ($allocation) => $allocation->payment->status === \App\Enums\PaymentStatus::Verified);
                $paidPercent = $bill->netAmount() > 0 ? (int) floor($bill->paid_amount / $bill->netAmount() * 100) : 0;
            @endphp
            <section class="panel p-5 shadow-sm" wire:key="installment-{{ $bill->id }}">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="font-semibold">{{ $bill->description }}</h2>
                        <p class="text-sm text-base-content/65">{{ $bill->student->name }}</p>
                    </div>
                    <span class="badge badge-soft badge-sm shrink-0 font-semibold {{ $bill->status->badgeClass() }}">{{ $bill->status->label() }}</span>
                </div>

                <div class="mt-4 flex items-end justify-between gap-3">
                    <div>
                        <p class="text-xs text-base-content/60">Sisa</p>
                        <p class="text-2xl font-bold tracking-tight text-primary">{{ \Illuminate\Support\Number::rupiah($bill->remainingAmount()) }}</p>
                    </div>
                    <p class="text-sm font-semibold">{{ $paidPercent }}%</p>
                </div>

                <progress class="progress progress-primary mt-2 h-2.5 w-full" value="{{ $bill->paid_amount }}" max="{{ max($bill->netAmount(), 1) }}" aria-label="Terbayar {{ $paidPercent }} persen"></progress>

                <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                    <div><dt class="text-xs text-base-content/60">Total</dt><dd class="font-semibold">{{ \Illuminate\Support\Number::rupiah($bill->netAmount()) }}</dd></div>
                    <div><dt class="text-xs text-base-content/60">Terbayar ({{ $verified->count() }}×)</dt><dd class="font-semibold">{{ \Illuminate\Support\Number::rupiah($bill->paid_amount) }}</dd></div>
                </dl>

                @if ($bill->due_date && $bill->remainingAmount() > 0)
                    <p class="mt-3 border-t border-base-300 pt-3 text-xs text-base-content/60">Jatuh tempo pelunasan {{ $bill->due_date->translatedFormat('j F Y') }}</p>
                @endif
            </section>
        @empty
            <div class="panel p-6 text-sm text-base-content/70 shadow-sm md:col-span-2">Tidak ada tagihan cicilan.</div>
        @endforelse
        </div>
    </x-guardian.overlap>
</div>
