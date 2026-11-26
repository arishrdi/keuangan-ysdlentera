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
    @php
        $latestReceipt = $this->payments->onFirstPage()
            ? $this->payments->getCollection()->first(fn ($payment) => $payment->hasReceipt() && $payment->status === \App\Enums\PaymentStatus::Verified)
            : null;
    @endphp

    <x-guardian.band title="Riwayat Pembayaran" description="Semua pembayaran yang pernah dikirim beserta status dan kuitansinya." />

    <x-guardian.overlap class="lg:grid lg:grid-cols-3 lg:items-start lg:gap-4 lg:space-y-0">
        @if ($latestReceipt)
            <section class="panel p-5 text-center shadow-sm lg:sticky lg:top-20" aria-labelledby="latest-receipt">
                <span class="mx-auto flex size-14 items-center justify-center rounded-full bg-primary text-primary-content">
                    <x-icon name="check" class="size-7" />
                </span>
                <h2 id="latest-receipt" class="mt-3 text-lg font-bold text-primary">Pembayaran terakhir terverifikasi</h2>
                <p class="text-sm text-base-content/65">{{ $latestReceipt->student->name }}, {{ $latestReceipt->paid_on->translatedFormat('j M Y') }}</p>

                <dl class="mt-4 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 rounded-box border border-dashed border-base-300 p-4 text-start text-sm">
                    <dt class="text-base-content/65">No. kuitansi</dt>
                    <dd class="text-end font-mono font-semibold">{{ $latestReceipt->receipt_number }}</dd>
                    <dt class="text-base-content/65">Metode</dt>
                    <dd class="text-end font-semibold">{{ $latestReceipt->method->label() }}{{ $latestReceipt->channel ? ' ('.$latestReceipt->channel->name.')' : '' }}</dd>
                    <dt class="text-base-content/65">Jumlah</dt>
                    <dd class="text-end font-semibold">{{ \Illuminate\Support\Number::rupiah($latestReceipt->amount) }}</dd>
                </dl>

                <a href="{{ route('payments.receipt', $latestReceipt) }}" target="_blank" class="btn btn-outline btn-primary btn-block mt-4">Unduh kuitansi (PDF)</a>
            </section>
        @endif

        <section @class(['panel px-5 py-2', 'lg:col-span-2' => $latestReceipt, 'lg:col-span-3' => ! $latestReceipt]) aria-label="Daftar pembayaran">
            <ul class="divide-y divide-base-300">
                @forelse ($this->payments as $payment)
                    <li class="flex gap-3 py-4" wire:key="payment-{{ $payment->id }}">
                        <x-guardian.date-tile :date="$payment->paid_on" />

                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-3">
                                <p class="font-semibold">{{ $payment->allocations->map(fn ($allocation) => $allocation->bill->description)->join(', ') }}</p>
                                <p class="shrink-0 font-bold">{{ \Illuminate\Support\Number::rupiah($payment->amount) }}</p>
                            </div>
                            <div class="mt-1 flex items-center justify-between gap-3">
                                <p class="min-w-0 text-xs text-base-content/60">
                                    {{ $payment->student->name }}, {{ $payment->method->label() }}{{ $payment->channel ? ' ('.$payment->channel->name.')' : '' }}
                                </p>
                                <span class="badge badge-soft badge-sm shrink-0 font-semibold {{ $payment->status->badgeClass() }}">{{ $payment->status->label() }}</span>
                            </div>

                            @if ($payment->rejection_reason)
                                <p class="mt-2 rounded-field bg-error/10 px-3 py-2 text-sm text-error">Ditolak: {{ $payment->rejection_reason }}. Silakan kirim ulang bukti yang benar.</p>
                            @endif

                            @if ($payment->hasReceipt())
                                <a href="{{ route('payments.receipt', $payment) }}" target="_blank" class="link link-hover mt-1 inline-block text-sm font-medium text-primary">Kuitansi {{ $payment->receipt_number }}</a>
                            @endif
                        </div>
                    </li>
                @empty
                    <li class="py-6 text-center text-sm text-base-content/60">Belum ada pembayaran.</li>
                @endforelse
            </ul>
        </section>

        <div class="lg:col-span-3">{{ $this->payments->links() }}</div>
    </x-guardian.overlap>
</div>
