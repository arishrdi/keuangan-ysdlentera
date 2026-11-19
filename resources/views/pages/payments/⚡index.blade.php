<?php

use App\Actions\Payments\CancelPayment;
use App\Actions\Payments\RejectPayment;
use App\Actions\Payments\VerifyPayment;
use App\Enums\PaymentStatus;
use App\Models\Level;
use App\Models\Payment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Pembayaran')] class extends Component {
    use WithPagination;

    #[Url]
    public string $status = '';

    #[Url(as: 'cari')]
    public string $search = '';

    #[Url(as: 'dari')]
    public string $from = '';

    #[Url(as: 'sampai')]
    public string $until = '';

    public ?int $selectedId = null;

    public string $rejectionReason = '';

    public string $cancellationReason = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'search', 'from', 'until'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('manage-billing');
    }

    #[Computed]
    public function activeLevel(): ?Level
    {
        return Level::active();
    }

    #[Computed]
    public function pendingCount(): int
    {
        return Payment::query()
            ->where('status', PaymentStatus::Pending)
            ->when($this->activeLevel, fn ($query, Level $level) => $query->whereBelongsTo($level))
            ->count();
    }

    /**
     * @return LengthAwarePaginator<int, Payment>
     */
    #[Computed]
    public function payments(): LengthAwarePaginator
    {
        return Payment::query()
            ->with(['student', 'level', 'channel'])
            ->when($this->activeLevel, fn ($query, Level $level) => $query->whereBelongsTo($level))
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->when($this->search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('receipt_number', 'like', '%'.$this->search.'%')
                ->orWhereHas('student', fn ($query) => $query->where('name', 'like', '%'.$this->search.'%'))))
            ->when($this->from !== '', fn ($query) => $query->whereDate('paid_on', '>=', $this->from))
            ->when($this->until !== '', fn ($query) => $query->whereDate('paid_on', '<=', $this->until))
            ->latest('paid_on')
            ->latest('id')
            ->paginate(30);
    }

    #[Computed]
    public function selected(): ?Payment
    {
        return $this->selectedId
            ? Payment::query()->with(['student', 'level', 'channel', 'submitter', 'verifier', 'canceller', 'allocations.bill.paymentCategory'])->find($this->selectedId)
            : null;
    }

    public function show(int $paymentId): void
    {
        $this->resetValidation();
        $this->selectedId = $paymentId;
        $this->rejectionReason = '';
        $this->cancellationReason = '';

        $this->dispatch('open-modal', name: 'payment-detail');
    }

    public function verify(VerifyPayment $verifyPayment): void
    {
        $this->authorize('manage-billing');
        $this->resetValidation();

        $payment = $verifyPayment->handle($this->selected, auth()->user());

        $this->dispatch('toast', variant: 'success', text: 'Pembayaran diverifikasi. No. kuitansi '.$payment->receipt_number.'.');
        unset($this->selected, $this->payments, $this->pendingCount);
    }

    public function reject(RejectPayment $rejectPayment): void
    {
        $this->authorize('manage-billing');

        $this->validate(['rejectionReason' => ['required', 'string', 'max:255']], [], ['rejectionReason' => 'alasan penolakan']);

        $rejectPayment->handle($this->selected, auth()->user(), $this->rejectionReason);

        $this->dispatch('close-modal', name: 'payment-detail');
        $this->dispatch('toast', variant: 'success', text: 'Pembayaran ditolak dan wali murid diberi tahu.');
        unset($this->selected, $this->payments, $this->pendingCount);
    }

    public function cancel(CancelPayment $cancelPayment): void
    {
        $this->authorize('manage-billing');

        $this->validate(['cancellationReason' => ['required', 'string', 'max:255']], [], ['cancellationReason' => 'alasan pembatalan']);

        $cancelPayment->handle($this->selected, auth()->user(), $this->cancellationReason);

        $this->dispatch('close-modal', name: 'payment-detail');
        $this->dispatch('toast', variant: 'success', text: 'Pembayaran dibatalkan. Kuitansi ditandai BATAL.');
        unset($this->selected, $this->payments);
    }
}; ?>

<div>
    <x-page-header title="Pembayaran" :description="($this->activeLevel ? 'Jenjang '.$this->activeLevel->name : 'Semua jenjang').' · Verifikasi bukti transfer dan pantau pembayaran masuk.'" />

    <div role="tablist" class="tabs tabs-box mb-4 w-fit">
        <button type="button" role="tab" @class(['tab', 'tab-active' => $status === '']) wire:click="$set('status', '')">Semua</button>
        <button type="button" role="tab" @class(['tab gap-2', 'tab-active' => $status === 'menunggu']) wire:click="$set('status', 'menunggu')">
            Menunggu verifikasi
            @if ($this->pendingCount > 0)<span class="badge badge-sm badge-warning">{{ $this->pendingCount }}</span>@endif
        </button>
        <button type="button" role="tab" @class(['tab', 'tab-active' => $status === 'terverifikasi']) wire:click="$set('status', 'terverifikasi')">Terverifikasi</button>
        <button type="button" role="tab" @class(['tab', 'tab-active' => $status === 'ditolak']) wire:click="$set('status', 'ditolak')">Ditolak</button>
        <button type="button" role="tab" @class(['tab', 'tab-active' => $status === 'dibatalkan']) wire:click="$set('status', 'dibatalkan')">Dibatalkan</button>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <label class="input input-sm w-64">
            <x-icon name="magnifying-glass" class="size-4 opacity-60" />
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Cari siswa / no. kuitansi" />
        </label>
        <input type="date" class="input input-sm w-40" wire:model.live="from" aria-label="Dari tanggal" />
        <span class="text-sm">s.d.</span>
        <input type="date" class="input input-sm w-40" wire:model.live="until" aria-label="Sampai tanggal" />
    </div>

    <div class="overflow-x-auto rounded-box border border-base-300">
        <table class="table table-sm">
            <thead>
                <tr>
                    <th>Tanggal bayar</th>
                    <th>Siswa</th>
                    <th>No. kuitansi</th>
                    <th>Metode</th>
                    <th class="text-end">Jumlah</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->payments as $payment)
                    <tr wire:key="payment-{{ $payment->id }}">
                        <td>{{ $payment->paid_on->translatedFormat('j M Y') }}</td>
                        <td>
                            <a href="{{ route('billing.student', $payment->student) }}" class="link link-hover font-medium" wire:navigate>{{ $payment->student->name }}</a>
                            <span class="badge badge-outline badge-xs">{{ $payment->level->code }}</span>
                        </td>
                        <td class="font-mono text-xs">{{ $payment->receipt_number ?? '-' }}</td>
                        <td>{{ $payment->method->label() }}{{ $payment->channel ? ' · '.$payment->channel->name : '' }}</td>
                        <td class="text-end font-mono">{{ \Illuminate\Support\Number::rupiah($payment->amount) }}</td>
                        <td><span class="badge badge-sm {{ $payment->status->badgeClass() }}">{{ $payment->status->label() }}</span></td>
                        <td class="text-end">
                            <button type="button" class="btn btn-xs btn-ghost" wire:click="show({{ $payment->id }})">Detail</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-8 text-center text-base-content/60">Tidak ada pembayaran.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->payments->links() }}</div>

    <x-modal name="payment-detail" title="Detail pembayaran" max-width="max-w-3xl">
        @if ($payment = $this->selected)
            <div class="grid gap-6 md:grid-cols-2">
                <div class="space-y-3 text-sm">
                    <div class="flex items-center gap-2">
                        <span class="badge {{ $payment->status->badgeClass() }}">{{ $payment->status->label() }}</span>
                        <span class="font-mono text-xs">{{ $payment->receipt_number }}</span>
                    </div>
                    <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
                        <dt class="text-base-content/60">Siswa</dt><dd>{{ $payment->student->name }} ({{ $payment->level->name }})</dd>
                        <dt class="text-base-content/60">Tanggal bayar</dt><dd>{{ $payment->paid_on->translatedFormat('j F Y') }}</dd>
                        <dt class="text-base-content/60">Metode</dt><dd>{{ $payment->method->label() }}{{ $payment->channel ? ' · '.$payment->channel->name : '' }}</dd>
                        <dt class="text-base-content/60">Dicatat oleh</dt><dd>{{ $payment->submitter?->name ?? '-' }} · {{ $payment->created_at->translatedFormat('j M Y H:i') }}</dd>
                        @if ($payment->verifier)
                            <dt class="text-base-content/60">Diperiksa oleh</dt><dd>{{ $payment->verifier->name }} · {{ $payment->verified_at?->translatedFormat('j M Y H:i') }}</dd>
                        @endif
                        @if ($payment->notes)
                            <dt class="text-base-content/60">Catatan</dt><dd>{{ $payment->notes }}</dd>
                        @endif
                        @if ($payment->rejection_reason)
                            <dt class="text-base-content/60">Alasan ditolak</dt><dd class="text-error">{{ $payment->rejection_reason }}</dd>
                        @endif
                        @if ($payment->cancellation_reason)
                            <dt class="text-base-content/60">Dibatalkan</dt><dd class="text-error">{{ $payment->canceller?->name }}: {{ $payment->cancellation_reason }}</dd>
                        @endif
                    </dl>

                    <table class="table table-xs">
                        <thead><tr><th>Tagihan</th><th class="text-end">Jumlah</th></tr></thead>
                        <tbody>
                            @foreach ($payment->allocations as $allocation)
                                <tr wire:key="allocation-{{ $allocation->id }}">
                                    <td>{{ $allocation->bill->description }}</td>
                                    <td class="text-end font-mono">{{ \Illuminate\Support\Number::rupiah($allocation->amount) }}</td>
                                </tr>
                            @endforeach
                            <tr class="font-semibold">
                                <td>Total</td>
                                <td class="text-end font-mono">{{ \Illuminate\Support\Number::rupiah($payment->amount) }}</td>
                            </tr>
                        </tbody>
                    </table>

                    @if ($payment->hasReceipt())
                        <a href="{{ route('payments.receipt', $payment) }}" target="_blank" class="btn btn-sm btn-outline">Buka kuitansi</a>
                    @endif
                </div>

                <div>
                    @if ($payment->proof_path)
                        <div class="mb-1 text-sm text-base-content/60">Bukti pembayaran</div>
                        @if (str_ends_with(strtolower($payment->proof_path), '.pdf'))
                            <a href="{{ route('payments.proof', $payment) }}" target="_blank" class="btn btn-sm">Buka bukti (PDF)</a>
                        @else
                            <a href="{{ route('payments.proof', $payment) }}" target="_blank">
                                <img src="{{ route('payments.proof', $payment) }}" alt="Bukti pembayaran" class="max-h-96 w-full rounded-box border border-base-300 object-contain" />
                            </a>
                        @endif
                    @else
                        <div class="rounded-box border border-dashed border-base-300 p-6 text-center text-sm text-base-content/60">Tidak ada bukti terlampir.</div>
                    @endif
                </div>
            </div>

            @error('payment') <div role="alert" class="alert alert-error mt-4"><span>{{ $message }}</span></div> @enderror

            @if ($this->canManage && $payment->status === \App\Enums\PaymentStatus::Pending)
                <div class="mt-6 grid gap-3 border-t border-base-300 pt-4 md:grid-cols-[1fr_auto_auto] md:items-end">
                    <x-input wire:model="rejectionReason" label="Alasan penolakan (wajib bila ditolak)" placeholder="contoh: Dana belum masuk ke rekening" />
                    <button type="button" class="btn btn-error btn-outline" wire:click="reject">Tolak</button>
                    <button type="button" class="btn btn-primary" wire:click="verify" wire:confirm="Pastikan dana sudah masuk ke rekening. Verifikasi pembayaran ini?">Verifikasi</button>
                </div>
            @elseif ($this->canManage && $payment->status === \App\Enums\PaymentStatus::Verified)
                <details class="collapse-arrow collapse mt-6 border border-base-300">
                    <summary class="collapse-title text-sm font-medium">Batalkan pembayaran ini</summary>
                    <div class="collapse-content space-y-3">
                        <p class="text-sm text-base-content/70">Gunakan hanya bila pembayaran salah catat. Kuitansi akan ditandai BATAL dan tagihan kembali belum lunas.</p>
                        <x-input wire:model="cancellationReason" label="Alasan pembatalan" />
                        <button type="button" class="btn btn-error btn-sm" wire:click="cancel" wire:confirm="Batalkan pembayaran ini?">Batalkan pembayaran</button>
                    </div>
                </details>
            @endif
        @endif

        <div class="modal-action">
            <button type="button" class="btn" x-on:click="$el.closest('dialog').close()">Tutup</button>
        </div>
    </x-modal>
</div>
