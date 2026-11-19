<?php

use App\Actions\Payments\SubmitPayment;
use App\Enums\PaymentChannelType;
use App\Models\Bill;
use App\Models\PaymentChannel;
use App\Models\Student;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Tagihan Anak')] class extends Component {
    use WithFileUploads;

    #[Url(as: 'anak')]
    public ?int $studentId = null;

    /**
     * Amounts to pay keyed by bill id.
     *
     * @var array<int, int|string|null>
     */
    public array $allocations = [];

    public ?int $channelId = null;

    public string $paidOn = '';

    public string $notes = '';

    public ?UploadedFile $proof = null;

    public function mount(): void
    {
        $this->studentId = $this->children->firstWhere('id', $this->studentId)?->id ?? $this->children->first()?->id;
        $this->paidOn = now()->toDateString();
    }

    /**
     * @return Collection<int, Student>
     */
    #[Computed]
    public function children(): Collection
    {
        return auth()->user()->students()->with(['level', 'currentEnrollment.classroom'])->orderBy('name')->get();
    }

    #[Computed]
    public function student(): ?Student
    {
        return $this->children->firstWhere('id', $this->studentId);
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
     * @return Collection<int, PaymentChannel>
     */
    #[Computed]
    public function channels(): Collection
    {
        if ($this->student === null) {
            return new Collection;
        }

        return PaymentChannel::query()->where('level_id', $this->student->level_id)->active()->orderBy('type')->orderBy('name')->get();
    }

    #[Computed]
    public function channel(): ?PaymentChannel
    {
        return $this->channels->firstWhere('id', $this->channelId);
    }

    #[Computed]
    public function allocationTotal(): int
    {
        return array_sum(array_map('intval', $this->allocations));
    }

    public function updatedStudentId(): void
    {
        $this->reset('allocations', 'channelId');
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
        $this->resetValidation();

        if ($this->allocations === []) {
            return;
        }

        $this->dispatch('open-modal', name: 'pay');
    }

    public function submit(SubmitPayment $submitPayment): void
    {
        abort_if($this->student === null, 404);

        $this->validate([
            'channelId' => ['required', 'integer'],
            'paidOn' => ['required', 'date', 'before_or_equal:today'],
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:2048'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [
            'channelId.required' => 'Pilih rekening atau QRIS tujuan.',
        ], [
            'paidOn' => 'tanggal bayar',
            'proof' => 'bukti pembayaran',
            'notes' => 'catatan',
        ]);

        abort_if($this->channel === null, 422);

        $submitPayment->handle(
            actor: auth()->user(),
            student: $this->student,
            allocations: array_map('intval', $this->allocations),
            method: $this->channel->type->paymentMethod(),
            paidOn: Carbon::parse($this->paidOn),
            channel: $this->channel,
            proof: $this->proof,
            notes: $this->notes ?: null,
        );

        $this->reset('allocations', 'channelId', 'proof', 'notes');
        $this->dispatch('close-modal', name: 'pay');
        $this->dispatch('toast', variant: 'success', text: 'Bukti pembayaran terkirim. Bendahara akan memverifikasi dalam 1–2 hari kerja.', duration: 8000);
        unset($this->bills);
    }
}; ?>

<div class="pb-28">
    <x-page-header title="Tagihan" description="Pilih tagihan yang akan dibayar, transfer ke rekening atau QRIS sekolah, lalu unggah buktinya." />

    @if ($this->children->isEmpty())
        <div class="rounded-box border border-dashed border-base-300 p-6 text-sm text-base-content/70">Belum ada siswa yang terhubung dengan akun Anda. Hubungi admin sekolah.</div>
    @else
        @if ($this->children->count() > 1)
            <div role="tablist" class="tabs tabs-box mb-4 w-full overflow-x-auto">
                @foreach ($this->children as $child)
                    <button type="button" role="tab" wire:key="child-tab-{{ $child->id }}" @class(['tab', 'tab-active' => $child->id === $studentId]) wire:click="$set('studentId', {{ $child->id }})">
                        {{ $child->name }}
                    </button>
                @endforeach
            </div>
        @endif

        <p class="mb-3 text-sm text-base-content/70">{{ $this->student->name }} · {{ $this->student->level->name }} · Kelas {{ $this->student->currentEnrollment?->classroom->name ?? '-' }}</p>

        <div class="space-y-3">
            @forelse ($this->bills as $bill)
                @php
                    $isSelected = array_key_exists($bill->id, $allocations);
                    $payable = $bill->payableAmount();
                @endphp
                <div wire:key="bill-{{ $bill->id }}" @class(['card border bg-base-100', 'border-primary ring-1 ring-primary' => $isSelected, 'border-base-300' => ! $isSelected])>
                    <div class="card-body gap-2 p-4">
                        <div class="flex items-start gap-3">
                            @if ($payable > 0)
                                <input type="checkbox" class="checkbox checkbox-primary mt-1" @checked($isSelected) wire:click="toggleBill({{ $bill->id }})" aria-label="Bayar {{ $bill->description }}" />
                            @endif
                            <div class="flex-1">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <h3 class="font-semibold">{{ $bill->description }}</h3>
                                    <span class="badge badge-sm {{ $bill->status->badgeClass() }}">{{ $bill->status->label() }}</span>
                                </div>
                                <div class="text-sm text-base-content/70">
                                    {{ $bill->paymentCategory->name }}
                                    @if ($bill->due_date) · <span @class(['text-error font-medium' => $bill->isOverdue()])>Jatuh tempo {{ $bill->due_date->translatedFormat('j M Y') }}</span>@endif
                                </div>
                                @if ($bill->items->isNotEmpty())
                                    <div class="text-xs text-base-content/60">{{ $bill->items->map(fn ($item) => $item->quantity.'× '.$item->name.($item->size ? ' ('.$item->size.')' : ''))->join(', ') }}</div>
                                @endif
                                <div class="mt-1 flex flex-wrap gap-x-4 text-sm">
                                    <span>Tagihan <strong class="font-mono">{{ \Illuminate\Support\Number::rupiah($bill->netAmount()) }}</strong></span>
                                    @if ($bill->paid_amount > 0)<span>Dibayar <strong class="font-mono">{{ \Illuminate\Support\Number::rupiah($bill->paid_amount) }}</strong></span>@endif
                                    <span>Sisa <strong class="font-mono text-error">{{ \Illuminate\Support\Number::rupiah($bill->remainingAmount()) }}</strong></span>
                                </div>
                                @if ($bill->pending_amount > 0)
                                    <div class="mt-1 text-sm text-warning">{{ \Illuminate\Support\Number::rupiah($bill->pending_amount) }} sedang menunggu verifikasi bendahara.</div>
                                @endif
                                @if ($isSelected && $bill->paymentCategory->is_installable)
                                    <label class="input input-sm mt-2 w-full max-w-60">
                                        <span class="text-base-content/60">Bayar Rp</span>
                                        <input type="number" min="10000" max="{{ $payable }}" step="1000" wire:model.live.debounce.500ms="allocations.{{ $bill->id }}" aria-label="Nominal cicilan {{ $bill->description }}" />
                                    </label>
                                    <p class="mt-1 text-xs text-base-content/60">Boleh dicicil, minimal Rp10.000.</p>
                                @endif
                                @error('allocations.'.$bill->id) <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <div class="rounded-box border border-dashed border-success p-6 text-center text-sm">Alhamdulillah, tidak ada tagihan yang belum dibayar.</div>
            @endforelse
        </div>

        @if ($allocations !== [])
            <div class="fixed inset-x-0 bottom-16 z-30 border-t border-base-300 bg-base-100 p-4 shadow-lg lg:bottom-0 lg:left-64">
                <div class="mx-auto flex max-w-3xl items-center justify-between gap-3">
                    <div>
                        <div class="text-xs text-base-content/60">{{ count($allocations) }} tagihan dipilih</div>
                        <div class="text-lg font-semibold">{{ \Illuminate\Support\Number::rupiah($this->allocationTotal) }}</div>
                    </div>
                    <button type="button" class="btn btn-primary" wire:click="openPayment">Bayar sekarang</button>
                </div>
            </div>
        @endif
    @endif

    <x-modal name="pay" title="Bayar tagihan" max-width="max-w-xl">
        <form wire:submit="submit" class="space-y-4">
            <div class="rounded-box bg-base-200 p-3">
                <div class="text-sm text-base-content/70">Jumlah yang harus ditransfer</div>
                <div class="text-2xl font-semibold">{{ \Illuminate\Support\Number::rupiah($this->allocationTotal) }}</div>
            </div>

            @if ($this->channels->isEmpty())
                <div role="alert" class="alert alert-warning"><span>Sekolah belum menyediakan rekening atau QRIS. Silakan bayar langsung ke bendahara.</span></div>
            @else
                <fieldset class="fieldset p-0">
                    <legend class="fieldset-legend pt-0">1. Pilih tujuan pembayaran</legend>
                    <div class="grid gap-2">
                        @foreach ($this->channels as $channelOption)
                            <label wire:key="channel-option-{{ $channelOption->id }}" @class(['flex cursor-pointer items-start gap-3 rounded-box border p-3', 'border-primary bg-primary/5' => $channelId === $channelOption->id, 'border-base-300' => $channelId !== $channelOption->id])>
                                <input type="radio" class="radio radio-primary radio-sm mt-1" value="{{ $channelOption->id }}" wire:model.live="channelId" />
                                <div class="text-sm">
                                    <div class="font-medium">{{ $channelOption->name }} <span class="badge badge-ghost badge-sm">{{ $channelOption->type->label() }}</span></div>
                                    @if ($channelOption->type === \App\Enums\PaymentChannelType::BankAccount)
                                        <div>{{ $channelOption->bank_name }} · <span class="font-mono">{{ $channelOption->account_number }}</span></div>
                                        <div class="text-base-content/60">a.n. {{ $channelOption->account_holder }}</div>
                                    @endif
                                </div>
                            </label>
                        @endforeach
                    </div>
                    @error('channelId') <p class="label text-error">{{ $message }}</p> @enderror
                    @error('channel') <p class="label text-error">{{ $message }}</p> @enderror
                </fieldset>

                @if ($this->channel?->type === \App\Enums\PaymentChannelType::Qris)
                    <div class="text-center">
                        <img src="{{ route('payment-channels.qris', $this->channel) }}" alt="QRIS {{ $this->channel->name }}" class="mx-auto max-h-72 rounded-box border border-base-300 bg-white p-2" />
                        <p class="mt-1 text-xs text-base-content/60">Scan dengan aplikasi bank atau e-wallet, lalu masukkan nominal {{ \Illuminate\Support\Number::rupiah($this->allocationTotal) }}.</p>
                    </div>
                @endif

                <fieldset class="fieldset p-0">
                    <legend class="fieldset-legend pt-0">2. Unggah bukti pembayaran</legend>
                    <input type="file" accept="image/*,application/pdf" class="file-input w-full" wire:model="proof" aria-label="Bukti pembayaran" />
                    <p class="label">JPG, PNG, atau PDF, maksimal 2 MB.</p>
                    <div wire:loading wire:target="proof" class="label">Mengunggah…</div>
                    @error('proof') <p class="label text-error">{{ $message }}</p> @enderror
                </fieldset>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-input wire:model="paidOn" label="Tanggal transfer" type="date" required />
                    <x-input wire:model="notes" label="Catatan (opsional)" />
                </div>
            @endif

            @foreach ($errors->get('allocations*') as $messages)
                <p class="text-sm text-error">{{ $messages[0] }}</p>
            @endforeach

            <div class="modal-action">
                <button type="button" class="btn" x-on:click="$el.closest('dialog').close()">Batal</button>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" @disabled($this->channels->isEmpty())>Kirim bukti pembayaran</button>
            </div>
        </form>
    </x-modal>
</div>
