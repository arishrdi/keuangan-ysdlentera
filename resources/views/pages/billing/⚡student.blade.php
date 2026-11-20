<?php

use App\Actions\Billing\ApplyBillDiscount;
use App\Actions\Billing\CancelBill;
use App\Actions\Billing\UpdateBill;
use App\Actions\Payments\SubmitPayment;
use App\Enums\PaymentMethod;
use App\Models\Bill;
use App\Models\Payment;
use App\Models\PaymentChannel;
use App\Models\Student;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public Student $student;

    public bool $showSettled = false;

    /**
     * Amounts to pay keyed by bill id.
     *
     * @var array<int, int|string|null>
     */
    public array $allocations = [];

    public string $method = 'tunai';

    public ?int $channelId = null;

    public string $paidOn = '';

    public string $notes = '';

    public ?UploadedFile $proof = null;

    public ?int $actionBillId = null;

    public ?int $discountAmount = null;

    public string $discountReason = '';

    public string $cancellationReason = '';

    public string $editDescription = '';

    public ?int $editAmount = null;

    public string $editDueDate = '';

    public function mount(Student $student): void
    {
        $this->student = $student->load('level', 'currentEnrollment.classroom', 'guardians');
        $this->paidOn = now()->toDateString();
    }

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('manage-billing');
    }

    /**
     * @return Collection<int, Bill>
     */
    #[Computed]
    public function bills(): Collection
    {
        return $this->student->bills()
            ->with(['paymentCategory', 'academicYear', 'items'])
            ->withPendingAmount()
            ->when(! $this->showSettled, fn ($query) => $query->outstanding())
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, Payment>
     */
    #[Computed]
    public function payments(): Collection
    {
        return $this->student->payments()->with(['allocations.bill', 'channel'])->latest('paid_on')->latest('id')->limit(30)->get();
    }

    /**
     * @return Collection<int, PaymentChannel>
     */
    #[Computed]
    public function channels(): Collection
    {
        return PaymentChannel::query()->where('level_id', $this->student->level_id)->active()->orderBy('name')->get();
    }

    #[Computed]
    public function allocationTotal(): int
    {
        return array_sum(array_map('intval', $this->allocations));
    }

    #[Computed]
    public function actionBill(): ?Bill
    {
        return $this->actionBillId ? $this->student->bills()->with('paymentCategory')->find($this->actionBillId) : null;
    }

    /**
     * Tick a bill for payment, prefilled with what is still payable.
     */
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
        $this->authorize('manage-billing');
        $this->resetValidation();

        if ($this->allocations === []) {
            $this->dispatch('toast', variant: 'warning', text: 'Centang tagihan yang akan dibayar terlebih dahulu.');

            return;
        }

        $this->dispatch('open-modal', name: 'record-payment');
    }

    public function recordPayment(SubmitPayment $submitPayment): void
    {
        $this->authorize('manage-billing');

        $this->validate([
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'channelId' => [Rule::requiredIf($this->method !== PaymentMethod::Cash->value), 'nullable', 'integer'],
            'paidOn' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:255'],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:2048'],
        ], [], [
            'method' => 'metode',
            'channelId' => 'rekening/QRIS tujuan',
            'paidOn' => 'tanggal bayar',
            'notes' => 'catatan',
            'proof' => 'bukti pembayaran',
        ]);

        $payment = $submitPayment->handle(
            actor: auth()->user(),
            student: $this->student,
            allocations: array_map('intval', $this->allocations),
            method: PaymentMethod::from($this->method),
            paidOn: Carbon::parse($this->paidOn),
            channel: $this->channelId ? PaymentChannel::query()->find($this->channelId) : null,
            proof: $this->proof,
            notes: $this->notes ?: null,
        );

        $this->reset('allocations', 'notes', 'proof', 'channelId');
        $this->dispatch('close-modal', name: 'record-payment');
        $this->dispatch('toast', variant: 'success', text: 'Pembayaran tercatat. No. kuitansi '.$payment->receipt_number.'.');
        $this->dispatch('open-receipt', url: route('payments.receipt', $payment));
        unset($this->bills, $this->payments);
    }

    public function openDiscount(int $billId): void
    {
        $this->authorize('manage-billing');
        $this->resetValidation();

        $this->actionBillId = $billId;
        $this->discountAmount = $this->actionBill?->discount_amount ?: null;
        $this->discountReason = (string) $this->actionBill?->discount_reason;

        $this->dispatch('open-modal', name: 'bill-discount');
    }

    public function applyDiscount(ApplyBillDiscount $applyBillDiscount): void
    {
        $this->authorize('manage-billing');

        $this->validate([
            'discountAmount' => ['required', 'integer', 'min:0'],
            'discountReason' => ['required', 'string', 'max:255'],
        ], [], ['discountAmount' => 'nominal potongan', 'discountReason' => 'alasan']);

        $applyBillDiscount->handle($this->actionBill, $this->discountAmount, $this->discountReason);

        $this->dispatch('close-modal', name: 'bill-discount');
        $this->dispatch('toast', variant: 'success', text: 'Potongan tagihan disimpan.');
        unset($this->allocations[$this->actionBillId], $this->bills);
    }

    public function openEdit(int $billId): void
    {
        $this->authorize('manage-billing');
        $this->resetValidation();

        $this->actionBillId = $billId;
        $this->editDescription = (string) $this->actionBill?->description;
        $this->editAmount = $this->actionBill?->amount;
        $this->editDueDate = (string) $this->actionBill?->due_date?->toDateString();

        $this->dispatch('open-modal', name: 'bill-edit');
    }

    public function updateBill(UpdateBill $updateBill): void
    {
        $this->authorize('manage-billing');

        $this->validate([
            'editDescription' => ['required', 'string', 'max:255'],
            'editAmount' => ['required', 'integer', 'min:1'],
            'editDueDate' => ['nullable', 'date'],
        ], [], ['editDescription' => 'keterangan', 'editAmount' => 'nominal', 'editDueDate' => 'jatuh tempo']);

        $updateBill->handle(
            $this->actionBill,
            $this->editDescription,
            $this->editAmount,
            $this->editDueDate !== '' ? Carbon::parse($this->editDueDate) : null,
        );

        $this->dispatch('close-modal', name: 'bill-edit');
        $this->dispatch('toast', variant: 'success', text: 'Tagihan diperbarui.');
        unset($this->allocations[$this->actionBillId], $this->bills);
    }

    public function openCancellation(int $billId): void
    {
        $this->authorize('manage-billing');
        $this->resetValidation();

        $this->actionBillId = $billId;
        $this->cancellationReason = '';

        $this->dispatch('open-modal', name: 'bill-cancel');
    }

    public function cancelBill(CancelBill $cancelBill): void
    {
        $this->authorize('manage-billing');

        $this->validate(['cancellationReason' => ['required', 'string', 'max:255']], [], ['cancellationReason' => 'alasan pembatalan']);

        $cancelBill->handle($this->actionBill, $this->cancellationReason);

        $this->dispatch('close-modal', name: 'bill-cancel');
        $this->dispatch('toast', variant: 'success', text: 'Tagihan dibatalkan.');
        unset($this->allocations[$this->actionBillId], $this->bills);
    }

    public function render(): mixed
    {
        return $this->view()->title('Tagihan '.$this->student->name);
    }
}; ?>

<div x-data x-on:open-receipt.window="window.open($event.detail.url, '_blank')">
    <a href="{{ route('billing.index') }}" class="btn btn-ghost btn-sm mb-4" wire:navigate>
        <x-icon name="arrow-left" class="size-4" /> Kembali ke daftar tagihan
    </a>

    <x-page-header
        :title="$student->name"
        :meta="[$student->level->name, 'Kelas '.($student->currentEnrollment?->classroom->name ?? '-'), 'NIS '.($student->nis ?? '-'), 'Wali: '.($student->guardians->pluck('name')->join(', ') ?: '-')]"
    >
        <x-slot:actions>
            <label class="label cursor-pointer gap-2 text-base-content">
                <input type="checkbox" class="toggle toggle-sm" wire:model.live="showSettled" />
                <span class="text-sm">Tampilkan yang lunas/dibatalkan</span>
            </label>
        </x-slot:actions>
    </x-page-header>

    <div class="overflow-x-auto rounded-box border border-base-300">
        <table class="table">
            <thead>
                <tr>
                    @if ($this->canManage)<th class="w-10"></th>@endif
                    <th>Tagihan</th>
                    <th class="text-end">Nominal</th>
                    <th class="text-end">Dibayar</th>
                    <th class="text-end">Sisa</th>
                    <th>Jatuh tempo</th>
                    <th>Status</th>
                    @if ($this->canManage)<th class="text-end">Aksi</th>@endif
                </tr>
            </thead>
            <tbody>
                @forelse ($this->bills as $bill)
                    @php $isSelected = array_key_exists($bill->id, $allocations); @endphp
                    <tr wire:key="bill-{{ $bill->id }}" @class(['bg-primary/5' => $isSelected])>
                        @if ($this->canManage)
                            <td>
                                @if ($bill->payableAmount() > 0)
                                    <input type="checkbox" class="checkbox checkbox-sm checkbox-primary" @checked($isSelected) wire:click="toggleBill({{ $bill->id }})" aria-label="Bayar {{ $bill->description }}" />
                                @endif
                            </td>
                        @endif
                        <td>
                            <div class="font-medium">{{ $bill->description }}</div>
                            <div class="text-xs text-base-content/60">
                                {{ $bill->paymentCategory->name }} {{ $bill->academicYear->name }}
                                @if ($bill->paymentCategory->is_installable)
                                    <span class="badge badge-ghost badge-xs ms-1">boleh dicicil</span>
                                @endif
                            </div>
                            @if ($bill->items->isNotEmpty())
                                <div class="text-xs text-base-content/60">{{ $bill->items->map(fn ($item) => $item->quantity.'× '.$item->name.($item->size ? ' ('.$item->size.')' : ''))->join(', ') }}</div>
                            @endif
                            @if ($bill->discount_amount > 0)
                                <div class="text-xs text-info">Potongan {{ \Illuminate\Support\Number::rupiah($bill->discount_amount) }}: {{ $bill->discount_reason }}</div>
                            @endif
                            @if ($bill->status === \App\Enums\BillStatus::Cancelled)
                                <div class="text-xs text-error">Dibatalkan: {{ $bill->cancellation_reason }}</div>
                            @endif
                        </td>
                        <td class="text-end font-mono">{{ \Illuminate\Support\Number::rupiah($bill->netAmount()) }}</td>
                        <td class="text-end font-mono">{{ \Illuminate\Support\Number::rupiah($bill->paid_amount) }}</td>
                        <td class="text-end font-mono">
                            @if ($isSelected && $bill->paymentCategory->is_installable)
                                <x-money-input wire:model.live.debounce.500ms="allocations.{{ $bill->id }}" size="sm" class="ms-auto w-40" aria-label="Nominal dibayar {{ $bill->description }}" />
                            @else
                                {{ \Illuminate\Support\Number::rupiah($bill->remainingAmount()) }}
                            @endif
                        </td>
                        <td @class(['text-error font-medium' => $bill->isOverdue()])>{{ $bill->due_date?->translatedFormat('j M Y') ?? '-' }}</td>
                        <td>
                            <span class="badge badge-sm {{ $bill->status->badgeClass() }}">{{ $bill->status->label() }}</span>
                            @if ($bill->pending_amount > 0)
                                <span class="badge badge-sm badge-warning badge-outline">Menunggu verifikasi {{ \Illuminate\Support\Number::rupiah($bill->pending_amount) }}</span>
                            @endif
                        </td>
                        @if ($this->canManage)
                            <td class="text-end">
                                @if (in_array($bill->status, \App\Enums\BillStatus::outstanding(), true))
                                    <div class="flex justify-end gap-1">
                                        @if ($bill->paid_amount === 0 && ! $bill->pending_amount)
                                            <button type="button" class="btn btn-xs btn-ghost" wire:click="openEdit({{ $bill->id }})">Ubah</button>
                                        @endif
                                        <button type="button" class="btn btn-xs btn-ghost" wire:click="openDiscount({{ $bill->id }})">Potongan</button>
                                        @if ($bill->paid_amount === 0)
                                            <button type="button" class="btn btn-xs btn-ghost text-error" wire:click="openCancellation({{ $bill->id }})">Batalkan</button>
                                        @endif
                                    </div>
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="8" class="py-8 text-center text-base-content/60">Tidak ada tagihan yang belum lunas.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @error('allocations') <p class="mt-2 text-sm text-error">{{ $message }}</p> @enderror

    @if ($this->canManage)
        <div class="sticky bottom-0 mt-4 flex flex-wrap items-center justify-between gap-3 rounded-box border border-base-300 bg-base-100 p-4 shadow">
            <div>
                <div class="text-sm text-base-content/60">{{ count($allocations) }} tagihan dipilih</div>
                <div class="text-xl font-semibold">{{ \Illuminate\Support\Number::rupiah($this->allocationTotal) }}</div>
            </div>
            <button type="button" class="btn btn-primary" wire:click="openPayment" @disabled($allocations === [])>Catat pembayaran</button>
        </div>
    @endif

    <h2 class="mb-3 mt-8 font-semibold">Riwayat pembayaran</h2>
    <div class="overflow-x-auto rounded-box border border-base-300">
        <table class="table table-sm">
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th>No. kuitansi</th>
                    <th>Untuk</th>
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
                        <td class="font-mono text-xs">{{ $payment->receipt_number ?? '-' }}</td>
                        <td class="text-sm">{{ $payment->allocations->map(fn ($allocation) => $allocation->bill->description)->join(', ') }}</td>
                        <td>{{ $payment->method->label() }}</td>
                        <td class="text-end font-mono">{{ \Illuminate\Support\Number::rupiah($payment->amount) }}</td>
                        <td><span class="badge badge-sm {{ $payment->status->badgeClass() }}">{{ $payment->status->label() }}</span></td>
                        <td class="text-end">
                            @if ($payment->hasReceipt())
                                <a href="{{ route('payments.receipt', $payment) }}" target="_blank" class="btn btn-xs btn-ghost">Kuitansi</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-base-content/60">Belum ada pembayaran.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($this->canManage)
        <x-modal name="record-payment" title="Catat pembayaran">
            <form wire:submit="recordPayment" class="space-y-4">
                <div class="rounded-box bg-base-200 p-3 text-sm">
                    Total: <strong>{{ \Illuminate\Support\Number::rupiah($this->allocationTotal) }}</strong> untuk {{ count($allocations) }} tagihan.
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-select wire:model.live="method" label="Metode">
                        @foreach (\App\Enums\PaymentMethod::cases() as $methodOption)
                            <option value="{{ $methodOption->value }}" wire:key="method-{{ $methodOption->value }}">{{ $methodOption->label() }}</option>
                        @endforeach
                    </x-select>
                    <x-input wire:model="paidOn" label="Tanggal bayar" type="date" required />
                </div>
                @if ($method !== 'tunai')
                    <x-select wire:model="channelId" label="Rekening / QRIS tujuan" placeholder="Pilih tujuan">
                        @foreach ($this->channels->filter(fn ($channel) => $channel->type->paymentMethod()->value === $method) as $channel)
                            <option value="{{ $channel->id }}" wire:key="channel-{{ $channel->id }}">{{ $channel->name }}</option>
                        @endforeach
                    </x-select>
                    @error('channel') <p class="text-sm text-error">{{ $message }}</p> @enderror
                    <fieldset class="fieldset p-0">
                        <label for="proof" class="fieldset-legend pt-0">Bukti (opsional)</label>
                        <input id="proof" type="file" accept="image/*,application/pdf" class="file-input w-full" wire:model="proof" />
                        @error('proof') <p class="label text-error">{{ $message }}</p> @enderror
                    </fieldset>
                @endif
                <x-input wire:model="notes" label="Catatan (opsional)" />

                @error('payment') <div role="alert" class="alert alert-error text-sm"><span>{{ $message }}</span></div> @enderror

                @foreach ($errors->get('allocations.*') as $messages)
                    <p class="text-sm text-error">{{ $messages[0] }}</p>
                @endforeach

                <div class="modal-action">
                    <button type="button" class="btn" x-on:click="$el.closest('dialog').close()">Batal</button>
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Simpan & cetak kuitansi</button>
                </div>
            </form>
        </x-modal>

        <x-modal name="bill-discount" :title="'Potongan tagihan'.($this->actionBill ? ' — '.$this->actionBill->description : '')">
            <form wire:submit="applyDiscount" class="space-y-4">
                @if ($this->actionBill)
                    <p class="text-sm text-base-content/70">
                        Nominal asli {{ \Illuminate\Support\Number::rupiah($this->actionBill->amount) }}, sudah dibayar {{ \Illuminate\Support\Number::rupiah($this->actionBill->paid_amount) }}.
                        Isi 0 untuk menghapus potongan.
                    </p>
                @endif
                <x-money-input wire:model="discountAmount" label="Total potongan" required />
                <x-input wire:model="discountReason" label="Alasan" placeholder="contoh: Beasiswa yatim" required />
                <div class="modal-action">
                    <button type="button" class="btn" x-on:click="$el.closest('dialog').close()">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan potongan</button>
                </div>
            </form>
        </x-modal>

        <x-modal name="bill-edit" :title="'Ubah tagihan'.($this->actionBill ? ' — '.$this->actionBill->description : '')">
            <form wire:submit="updateBill" class="space-y-4">
                <p class="text-sm text-base-content/70">Hanya untuk tagihan yang belum ada pembayaran. Setiap perubahan tercatat di log aktivitas.</p>
                <x-input wire:model="editDescription" label="Keterangan" required />
                @if ($this->actionBill?->items()->exists())
                    <div>
                        <x-money-input wire:model="editAmount" label="Nominal" disabled />
                        <p class="mt-1 text-xs text-base-content/60">Nominal tagihan seragam mengikuti item dan tidak bisa diubah.</p>
                    </div>
                @else
                    <x-money-input wire:model="editAmount" label="Nominal" required />
                @endif
                @if ($this->actionBill?->discount_amount > 0)
                    <p class="text-xs text-info">Tagihan ini memiliki potongan {{ \Illuminate\Support\Number::rupiah($this->actionBill->discount_amount) }}. Nominal tidak boleh lebih kecil dari potongan.</p>
                @endif
                <x-input wire:model="editDueDate" label="Jatuh tempo (opsional)" type="date" class="" />
                <div class="modal-action">
                    <button type="button" class="btn" x-on:click="$el.closest('dialog').close()">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan perubahan</button>
                </div>
            </form>
        </x-modal>

        <x-modal name="bill-cancel" :title="'Batalkan tagihan'.($this->actionBill ? ' — '.$this->actionBill->description : '')">
            <form wire:submit="cancelBill" class="space-y-4">
                <p class="text-sm text-base-content/70">Tagihan yang dibatalkan tetap tersimpan sebagai riwayat dan tidak bisa diaktifkan kembali.</p>
                <x-input wire:model="cancellationReason" label="Alasan pembatalan" required />
                <div class="modal-action">
                    <button type="button" class="btn" x-on:click="$el.closest('dialog').close()">Kembali</button>
                    <button type="submit" class="btn btn-error">Batalkan tagihan</button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
