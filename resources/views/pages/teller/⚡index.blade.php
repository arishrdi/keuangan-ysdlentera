<?php

use App\Actions\Payments\SubmitPayment;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Bill;
use App\Models\Level;
use App\Models\Payment;
use App\Models\Student;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Counter page for tellers: find any student of the teller's level and record cash paid at the counter.
 */
new #[Title('Kasir')] class extends Component {
    #[Url(as: 'cari')]
    public string $search = '';

    #[Url(as: 'siswa')]
    public ?int $studentId = null;

    /**
     * Amounts to pay keyed by bill id.
     *
     * @var array<int, int|string|null>
     */
    public array $allocations = [];

    public string $paidOn = '';

    public string $notes = '';

    public function mount(): void
    {
        $this->authorize('record-cash-payments');

        $this->paidOn = now()->toDateString();

        if ($this->student === null) {
            $this->studentId = null;
        }
    }

    #[Computed]
    public function activeLevel(): ?Level
    {
        return Level::active();
    }

    /**
     * Students matching the search, by name, NIS or guardian name.
     *
     * @return Collection<int, Student>
     */
    #[Computed]
    public function results(): Collection
    {
        if (mb_strlen(trim($this->search)) < 2) {
            return new Collection;
        }

        $term = '%'.trim($this->search).'%';

        return Student::query()
            ->with(['level', 'currentEnrollment.classroom', 'guardians'])
            ->active()
            ->when($this->activeLevel, fn ($query, Level $level) => $query->whereBelongsTo($level))
            ->where(fn ($query) => $query
                ->where('name', 'like', $term)
                ->orWhere('nis', 'like', $term)
                ->orWhereHas('guardians', fn ($query) => $query->where('name', 'like', $term)->orWhere('phone', 'like', $term)))
            ->orderBy('name')
            ->limit(15)
            ->get();
    }

    #[Computed]
    public function student(): ?Student
    {
        if ($this->studentId === null) {
            return null;
        }

        return Student::query()
            ->with(['level', 'currentEnrollment.classroom', 'guardians'])
            ->when($this->activeLevel, fn ($query, Level $level) => $query->whereBelongsTo($level))
            ->find($this->studentId);
    }

    /**
     * @return Collection<int, Bill>
     */
    #[Computed]
    public function bills(): Collection
    {
        if ($this->student === null) {
            return new Collection;
        }

        return $this->student->bills()
            ->with(['paymentCategory', 'items'])
            ->withPendingAmount()
            ->outstanding()
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Cash payments this teller recorded today.
     *
     * @return Collection<int, Payment>
     */
    #[Computed]
    public function todaysPayments(): Collection
    {
        return Payment::query()
            ->with('student')
            ->where('submitted_by', auth()->id())
            ->where('method', PaymentMethod::Cash)
            ->whereDate('created_at', today())
            ->latest('id')
            ->get();
    }

    #[Computed]
    public function allocationTotal(): int
    {
        return array_sum(array_map('intval', $this->allocations));
    }

    public function selectStudent(int $studentId): void
    {
        $this->studentId = $studentId;
        $this->reset('allocations', 'notes');
        unset($this->student, $this->bills);
    }

    public function clearStudent(): void
    {
        $this->reset('studentId', 'allocations', 'notes');
        unset($this->student, $this->bills);
    }

    public function toggleBill(int $billId): void
    {
        if (array_key_exists($billId, $this->allocations)) {
            unset($this->allocations[$billId]);

            return;
        }

        $bill = $this->bills->firstWhere('id', $billId);

        if ($bill !== null && $bill->payableAmount() > 0) {
            $this->allocations[$billId] = $bill->payableAmount();
        }
    }

    public function openPayment(): void
    {
        $this->authorize('record-cash-payments');
        $this->resetValidation();

        if ($this->allocations === []) {
            return;
        }

        $this->dispatch('open-modal', name: 'cash-payment');
    }

    public function recordPayment(SubmitPayment $submitPayment): void
    {
        $this->authorize('record-cash-payments');
        abort_if($this->student === null, 404);

        $this->validate([
            'paidOn' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], [
            'paidOn' => 'tanggal bayar',
            'notes' => 'catatan',
        ]);

        $payment = $submitPayment->handle(
            actor: auth()->user(),
            student: $this->student,
            allocations: array_map('intval', $this->allocations),
            method: PaymentMethod::Cash,
            paidOn: Carbon::parse($this->paidOn),
            notes: $this->notes ?: null,
        );

        $this->reset('allocations', 'notes');
        $this->dispatch('close-modal', name: 'cash-payment');
        $this->dispatch('toast', variant: 'success', text: 'Pembayaran tunai tercatat. No. kuitansi '.$payment->receipt_number.'.');
        $this->dispatch('open-receipt', url: route('payments.receipt', $payment));
        unset($this->bills, $this->todaysPayments);
    }
}; ?>

<div x-data x-on:open-receipt.window="window.open($event.detail.url, '_blank')">
    <x-page-header
        title="Kasir"
        :meta="[($this->activeLevel ? 'Jenjang '.$this->activeLevel->name : 'Semua jenjang')]"
        description="Cari siswa, centang tagihan yang dibayar, lalu catat uang tunai yang diterima. Kuitansi langsung terbit."
    />

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-4 xl:col-span-2">
            @if ($this->student === null)
                <label class="input w-full">
                    <x-icon name="magnifying-glass" class="size-4 opacity-60" />
                    <input type="search" wire:model.live.debounce.400ms="search" placeholder="Cari nama siswa, NIS, atau nama / no. HP wali" autofocus aria-label="Cari siswa" />
                </label>

                @if (mb_strlen(trim($search)) < 2)
                    <div class="panel p-5 text-sm text-base-content/60">Ketik minimal 2 huruf untuk mencari siswa.</div>
                @else
                    <div class="panel divide-y divide-base-300">
                        @forelse ($this->results as $result)
                            <button type="button" wire:key="result-{{ $result->id }}" wire:click="selectStudent({{ $result->id }})" class="flex w-full items-center justify-between gap-3 px-5 py-3 text-start hover:bg-base-200">
                                <span class="min-w-0">
                                    <span class="block truncate font-medium">{{ $result->name }}</span>
                                    <span class="block text-sm text-base-content/65">
                                        {{ $result->nis ? 'NIS '.$result->nis.' · ' : '' }}{{ $result->level->name }}, kelas {{ $result->currentEnrollment?->classroom->name ?? '-' }}
                                    </span>
                                    @if ($result->guardians->isNotEmpty())
                                        <span class="block text-xs text-base-content/55">Wali: {{ $result->guardians->pluck('name')->join(', ') }}</span>
                                    @endif
                                </span>
                                <x-icon name="chevron-down" class="size-4 -rotate-90 opacity-50" />
                            </button>
                        @empty
                            <p class="px-5 py-4 text-sm text-base-content/60">Tidak ada siswa aktif yang cocok.</p>
                        @endforelse
                    </div>
                @endif
            @else
                <div class="panel">
                    <div class="flex items-start justify-between gap-3 px-5 pt-4 pb-3">
                        <div class="min-w-0">
                            <p class="truncate font-semibold">{{ $this->student->name }}</p>
                            <p class="text-sm text-base-content/65">
                                {{ $this->student->nis ? 'NIS '.$this->student->nis.' · ' : '' }}{{ $this->student->level->name }}, kelas {{ $this->student->currentEnrollment?->classroom->name ?? '-' }}
                            </p>
                            @if ($this->student->guardians->isNotEmpty())
                                <p class="text-xs text-base-content/55">Wali: {{ $this->student->guardians->pluck('name')->join(', ') }}</p>
                            @endif
                        </div>
                        <button type="button" class="btn btn-sm btn-outline btn-primary shrink-0" wire:click="clearStudent">
                            <x-icon name="magnifying-glass" class="size-4" /> Ganti siswa
                        </button>
                    </div>
                    <div class="flex items-baseline justify-between gap-3 border-t border-base-300 px-5 py-3">
                        <span class="text-sm text-base-content/65">Sisa tagihan</span>
                        <span class="text-lg font-bold text-primary">{{ \Illuminate\Support\Number::rupiah($this->bills->sum(fn ($bill) => $bill->remainingAmount())) }}</span>
                    </div>
                </div>

                <div class="space-y-3">
                    @forelse ($this->bills as $bill)
                        @php
                            $isSelected = array_key_exists($bill->id, $allocations);
                            $payable = $bill->payableAmount();
                        @endphp
                        <div wire:key="bill-{{ $bill->id }}" @class(['panel p-4 transition-colors', 'border-primary ring-1 ring-primary' => $isSelected])>
                            <div class="flex items-start gap-3">
                                @if ($payable > 0)
                                    <input type="checkbox" class="checkbox checkbox-primary mt-0.5" @checked($isSelected) wire:click="toggleBill({{ $bill->id }})" aria-label="Bayar {{ $bill->description }}" />
                                @endif
                                <div class="flex-1">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <h3 class="font-semibold">{{ $bill->description }}</h3>
                                        <span class="badge badge-soft badge-sm font-semibold {{ $bill->status->badgeClass() }}">{{ $bill->status->label() }}</span>
                                    </div>
                                    <div class="text-sm text-base-content/70">
                                        {{ $bill->paymentCategory->name }}
                                        @if ($bill->due_date)
                                            · <span @class(['font-medium text-error' => $bill->isOverdue()])>jatuh tempo {{ $bill->due_date->translatedFormat('j M Y') }}</span>
                                        @endif
                                    </div>
                                    <dl class="mt-3 grid grid-cols-3 gap-2 rounded-field bg-base-200 p-3 text-sm">
                                        <div><dt class="text-xs text-base-content/60">Tagihan</dt><dd class="font-semibold">{{ \Illuminate\Support\Number::rupiah($bill->netAmount()) }}</dd></div>
                                        <div><dt class="text-xs text-base-content/60">Dibayar</dt><dd class="font-semibold">{{ \Illuminate\Support\Number::rupiah($bill->paid_amount) }}</dd></div>
                                        <div><dt class="text-xs text-base-content/60">Sisa</dt><dd @class(['font-semibold', 'text-error' => $bill->remainingAmount() > 0])>{{ \Illuminate\Support\Number::rupiah($bill->remainingAmount()) }}</dd></div>
                                    </dl>
                                    @if ($bill->pending_amount > 0)
                                        <div class="mt-2 text-sm font-medium text-warning-content">{{ \Illuminate\Support\Number::rupiah($bill->pending_amount) }} sedang menunggu verifikasi bendahara.</div>
                                    @endif
                                    @if ($isSelected && $bill->paymentCategory->is_installable)
                                        <x-money-input wire:model.live.debounce.500ms="allocations.{{ $bill->id }}" label="Nominal yang dibayar" class="mt-3 max-w-60" aria-label="Nominal cicilan {{ $bill->description }}" />
                                        <p class="mt-1 text-xs text-base-content/60">Boleh dicicil, minimal Rp10.000.</p>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="panel p-5 text-sm text-base-content/70">Siswa ini tidak punya tagihan yang belum dibayar.</div>
                    @endforelse
                </div>

                @if ($allocations !== [])
                    <div class="panel sticky bottom-4 flex items-center justify-between gap-3 px-5 py-3 shadow-lg">
                        <div>
                            <div class="text-xs text-base-content/60">{{ count($allocations) }} tagihan dipilih</div>
                            <div class="text-lg font-semibold">{{ \Illuminate\Support\Number::rupiah($this->allocationTotal) }}</div>
                        </div>
                        <button type="button" class="btn btn-primary" wire:click="openPayment">Terima tunai</button>
                    </div>
                @endif
            @endif
        </div>

        <section class="panel h-fit p-5" aria-labelledby="teller-today">
            <div class="flex items-baseline justify-between gap-2">
                <h2 id="teller-today" class="font-semibold">Diterima hari ini</h2>
                <span class="text-sm font-semibold">{{ \Illuminate\Support\Number::rupiah($this->todaysPayments->where('status', PaymentStatus::Verified)->sum('amount')) }}</span>
            </div>
            <ul class="mt-3 divide-y divide-base-300">
                @forelse ($this->todaysPayments as $payment)
                    <li wire:key="today-{{ $payment->id }}" class="flex items-center justify-between gap-3 py-2.5 text-sm">
                        <span class="min-w-0">
                            <span @class(['block truncate font-medium', 'line-through opacity-60' => $payment->status === PaymentStatus::Cancelled])>{{ $payment->student->name }}</span>
                            <span class="block font-mono text-xs text-base-content/60">{{ $payment->receipt_number }}</span>
                        </span>
                        <span class="flex shrink-0 items-center gap-2">
                            <span class="font-mono">{{ \Illuminate\Support\Number::rupiah($payment->amount) }}</span>
                            @if ($payment->hasReceipt())
                                <a href="{{ route('payments.receipt', $payment) }}" target="_blank" class="btn btn-xs btn-ghost">Kuitansi</a>
                            @endif
                        </span>
                    </li>
                @empty
                    <li class="py-3 text-sm text-base-content/60">Belum ada pembayaran tunai hari ini.</li>
                @endforelse
            </ul>
        </section>
    </div>

    <x-modal name="cash-payment" title="Terima pembayaran tunai">
        <form wire:submit="recordPayment" class="space-y-4">
            <div class="rounded-box bg-neutral p-4 text-neutral-content">
                <div class="text-sm text-neutral-content/75">Uang tunai yang diterima{{ $this->student ? ' dari wali '.$this->student->name : '' }}</div>
                <div class="mt-1 text-3xl font-bold tracking-tight">{{ \Illuminate\Support\Number::rupiah($this->allocationTotal) }}</div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-input wire:model="paidOn" label="Tanggal bayar" type="date" required />
                <x-input wire:model="notes" label="Catatan (opsional)" />
            </div>
            @foreach ($errors->get('allocations*') as $messages)
                <p class="text-sm text-error">{{ $messages[0] }}</p>
            @endforeach
            @error('student') <p class="text-sm text-error">{{ $message }}</p> @enderror
            @error('payment') <p class="text-sm text-error">{{ $message }}</p> @enderror
            <div class="modal-action">
                <button type="button" class="btn" x-on:click="$el.closest('dialog').close()">Batal</button>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Simpan &amp; cetak kuitansi</button>
            </div>
        </form>
    </x-modal>
</div>
