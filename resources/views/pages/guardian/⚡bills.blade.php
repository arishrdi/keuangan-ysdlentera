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
    <x-guardian.band title="Tagihan" description="Pilih tagihan, transfer ke rekening atau QRIS sekolah, lalu unggah buktinya.">
        @if ($this->children->count() > 1)
            <x-guardian.child-pills :children="$this->children" :active="$studentId" />
        @endif
    </x-guardian.band>

    @if ($this->children->isEmpty())
        <x-guardian.overlap>
            <div class="panel p-6 text-sm text-base-content/70 shadow-sm">Belum ada siswa yang terhubung dengan akun Anda. Hubungi admin sekolah.</div>
        </x-guardian.overlap>
    @else
        <x-guardian.overlap>
        <div class="panel flex items-center justify-between gap-3 px-5 py-4 shadow-sm">
            <div class="min-w-0">
                <p class="truncate font-semibold">{{ $this->student->name }}</p>
                <p class="text-sm text-base-content/65">{{ $this->student->level->name }}, kelas {{ $this->student->currentEnrollment?->classroom->name ?? '-' }}</p>
            </div>
            <div class="shrink-0 text-end">
                <p class="text-xs text-base-content/60">Sisa tagihan</p>
                <p class="font-bold text-primary">{{ \Illuminate\Support\Number::rupiah($this->bills->sum(fn ($bill) => $bill->remainingAmount())) }}</p>
            </div>
        </div>

        <div class="space-y-3">
            @forelse ($this->bills as $bill)
                @php
                    $isSelected = array_key_exists($bill->id, $allocations);
                    $payable = $bill->payableAmount();
                @endphp
                <div wire:key="bill-{{ $bill->id }}" @class(['panel transition-colors', 'border-primary ring-1 ring-primary' => $isSelected])>
                    <div class="p-4">
                        <div class="flex items-start gap-3">
                            @if ($payable > 0)
                                <input type="checkbox" class="checkbox checkbox-primary mt-0.5" @checked($isSelected) wire:click="toggleBill({{ $bill->id }})" aria-label="Bayar {{ $bill->description }}" />
                            @endif
                            <div class="flex-1">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <h3 class="font-semibold">{{ $bill->description }}</h3>
                                    <span class="badge badge-soft badge-sm font-semibold {{ $bill->status->badgeClass() }}">{{ $bill->status->label() }}</span>
                                </div>
                                <div class="text-sm text-base-content/70">{{ $bill->paymentCategory->name }}</div>
                                @if ($bill->due_date)
                                    <div @class(['text-sm', 'font-medium text-error' => $bill->isOverdue(), 'text-base-content/70' => ! $bill->isOverdue()])>
                                        {{ $bill->isOverdue() ? 'Lewat jatuh tempo' : 'Jatuh tempo' }} {{ $bill->due_date->translatedFormat('j M Y') }}
                                    </div>
                                @endif
                                @if ($bill->items->isNotEmpty())
                                    <div class="text-xs text-base-content/60">{{ $bill->items->map(fn ($item) => $item->quantity.'× '.$item->name.($item->size ? ' ('.$item->size.')' : ''))->join(', ') }}</div>
                                @endif
                                <dl class="mt-3 grid grid-cols-3 gap-2 rounded-field bg-base-200 p-3 text-sm">
                                    <div>
                                        <dt class="text-xs text-base-content/60">Tagihan</dt>
                                        <dd class="font-semibold">{{ \Illuminate\Support\Number::rupiah($bill->netAmount()) }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs text-base-content/60">Dibayar</dt>
                                        <dd class="font-semibold">{{ \Illuminate\Support\Number::rupiah($bill->paid_amount) }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs text-base-content/60">Sisa</dt>
                                        <dd @class(['font-semibold', 'text-error' => $bill->remainingAmount() > 0])>{{ \Illuminate\Support\Number::rupiah($bill->remainingAmount()) }}</dd>
                                    </div>
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
                </div>
            @empty
                <div class="panel flex items-center gap-4 p-5">
                    <span class="flex size-12 shrink-0 items-center justify-center rounded-full bg-primary text-primary-content">
                        <x-icon name="check" class="size-6" />
                    </span>
                    <div>
                        <p class="font-semibold">Tidak ada tunggakan</p>
                        <p class="text-sm text-base-content/70">Alhamdulillah, tidak ada tagihan yang belum dibayar.</p>
                    </div>
                </div>
            @endforelse
        </div>
        </x-guardian.overlap>

        @if ($allocations !== [])
            <div class="fixed inset-x-0 bottom-[calc(3.5rem+env(safe-area-inset-bottom))] z-30 border-t border-base-300 bg-base-100 px-4 py-3 lg:bottom-0 lg:left-64">
                <div class="mx-auto flex max-w-3xl items-center justify-between gap-3">
                    <div>
                        <div class="text-xs text-base-content/60">{{ count($allocations) }} tagihan dipilih</div>
                        <div class="text-lg font-semibold">{{ \Illuminate\Support\Number::rupiah($this->allocationTotal) }}</div>
                    </div>
                    <button type="button" class="btn btn-primary btn-lg" wire:click="openPayment">Bayar sekarang</button>
                </div>
            </div>
        @endif
    @endif

    <x-modal name="pay" title="Bayar tagihan" max-width="max-w-xl">
        <form wire:submit="submit" class="space-y-4">
            <div class="rounded-box bg-neutral p-4 text-neutral-content">
                <div class="text-sm text-neutral-content/75">Jumlah yang harus ditransfer</div>
                <div class="mt-1 text-3xl font-bold tracking-tight">{{ \Illuminate\Support\Number::rupiah($this->allocationTotal) }}</div>
            </div>

            @if ($this->channels->isEmpty())
                <div role="alert" class="alert alert-warning"><span>Sekolah belum menyediakan rekening atau QRIS. Silakan bayar langsung ke bendahara.</span></div>
            @else
                <fieldset class="fieldset p-0">
                    <legend class="fieldset-legend pt-0">1. Pilih tujuan pembayaran</legend>
                    <div class="grid gap-2">
                        @foreach ($this->channels as $channelOption)
                            <label wire:key="channel-option-{{ $channelOption->id }}" @class(['flex cursor-pointer items-start gap-3 rounded-box border p-4 transition-colors', 'border-primary bg-primary/5' => $channelId === $channelOption->id, 'border-base-300' => $channelId !== $channelOption->id])>
                                <input type="radio" class="radio radio-primary radio-sm mt-1" value="{{ $channelOption->id }}" wire:model.live="channelId" />
                                <div class="text-sm">
                                    <div class="font-medium">{{ $channelOption->name }} <span class="badge badge-ghost badge-sm">{{ $channelOption->type->label() }}</span></div>
                                    @if ($channelOption->type === \App\Enums\PaymentChannelType::BankAccount)
                                        <div>{{ $channelOption->bank_name }}</div>
                                        <div class="mt-1 flex flex-wrap items-center gap-2">
                                            <span class="font-mono text-lg font-bold tracking-wide text-primary">{{ $channelOption->account_number }}</span>
                                            <button
                                                type="button"
                                                class="btn btn-primary btn-xs"
                                                x-data="{ copied: false }"
                                                x-on:click.prevent="navigator.clipboard.writeText(@js($channelOption->account_number)); copied = true; setTimeout(() => copied = false, 2000)"
                                                x-text="copied ? 'Tersalin' : 'Salin'"
                                            >Salin</button>
                                        </div>
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
