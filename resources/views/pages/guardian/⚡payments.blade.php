<?php

use App\Models\Payment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Riwayat Pembayaran')] class extends Component {
    use WithPagination;

    /**
     * Payments for every linked child.
     *
     * @return LengthAwarePaginator<int, Payment>
     */
    #[Computed]
    public function payments(): LengthAwarePaginator
    {
        return Payment::query()
            ->with(['student', 'channel', 'allocations.bill'])
            ->whereIn('student_id', auth()->user()->students()->pluck('students.id'))
            ->latest('paid_on')
            ->latest('id')
            ->paginate(20);
    }
}; ?>

<div>
    <x-page-header title="Riwayat Pembayaran" description="Semua pembayaran yang pernah dikirim beserta status dan kuitansinya." />

    <div class="space-y-3">
        @forelse ($this->payments as $payment)
            <div class="card border border-base-300" wire:key="payment-{{ $payment->id }}">
                <div class="card-body gap-1 p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="font-semibold">{{ \Illuminate\Support\Number::rupiah($payment->amount) }}</div>
                        <span class="badge badge-sm {{ $payment->status->badgeClass() }}">{{ $payment->status->label() }}</span>
                    </div>
                    <div class="text-sm text-base-content/70">
                        {{ $payment->student->name }} · {{ $payment->paid_on->translatedFormat('j M Y') }} · {{ $payment->method->label() }}{{ $payment->channel ? ' ('.$payment->channel->name.')' : '' }}
                    </div>
                    <div class="text-sm">{{ $payment->allocations->map(fn ($allocation) => $allocation->bill->description)->join(', ') }}</div>
                    @if ($payment->rejection_reason)
                        <div class="text-sm text-error">Ditolak: {{ $payment->rejection_reason }}. Silakan kirim ulang bukti yang benar.</div>
                    @endif
                    @if ($payment->hasReceipt())
                        <div class="mt-1 flex flex-wrap items-center gap-2">
                            <span class="font-mono text-xs text-base-content/60">{{ $payment->receipt_number }}</span>
                            <a href="{{ route('payments.receipt', $payment) }}" target="_blank" class="btn btn-xs btn-outline btn-primary">Lihat / unduh kuitansi</a>
                        </div>
                    @endif
                </div>
            </div>
        @empty
            <div class="rounded-box border border-dashed border-base-300 p-6 text-sm text-base-content/70">Belum ada pembayaran.</div>
        @endforelse
    </div>

    <div class="mt-4">{{ $this->payments->links() }}</div>
</div>
