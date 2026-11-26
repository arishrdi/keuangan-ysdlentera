<?php

use App\Actions\Budgets\SaveBudgetProposal;
use App\Actions\Budgets\SubmitBudgetProposal;
use App\Enums\TransactionCategoryType;
use App\Models\BudgetProposal;
use App\Models\Level;
use App\Models\TransactionCategory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    #[Locked]
    public ?int $proposalId = null;

    public string $title = '';

    public ?int $categoryId = null;

    public string $reason = '';

    /**
     * @var array<int, array{name: string, quantity: int|string|null, unit: string, unit_price: int|string|null}>
     */
    public array $items = [];

    /**
     * @var array<int, \Illuminate\Http\UploadedFile>
     */
    public array $newAttachments = [];

    /**
     * @var array<int, int>
     */
    public array $removedAttachmentIds = [];

    public function mount(?BudgetProposal $budgetProposal = null): void
    {
        $this->authorize('manage-budgets');

        if ($budgetProposal?->exists) {
            $this->authorize('access-level', $budgetProposal->level_id);
            abort_unless($budgetProposal->status->isEditable(), 403);

            $this->proposalId = $budgetProposal->id;
            $this->title = $budgetProposal->title;
            $this->categoryId = $budgetProposal->transaction_category_id;
            $this->reason = $budgetProposal->reason;
            $this->items = $budgetProposal->items->map(fn ($item) => [
                'name' => $item->name,
                'quantity' => $item->quantity,
                'unit' => $item->unit,
                'unit_price' => $item->unit_price,
            ])->all();
        }

        if ($this->items === []) {
            $this->addItem();
        }
    }

    #[Computed]
    public function proposal(): ?BudgetProposal
    {
        return $this->proposalId ? BudgetProposal::query()->with(['attachments', 'level', 'approvals.user'])->find($this->proposalId) : null;
    }

    #[Computed]
    public function level(): ?Level
    {
        return $this->proposal?->level ?? Level::active();
    }

    /**
     * @return Collection<int, TransactionCategory>
     */
    #[Computed]
    public function categories(): Collection
    {
        return TransactionCategory::query()->ofType(TransactionCategoryType::Budget)->where('is_active', true)->orderBy('name')->get();
    }

    public function itemSubtotal(int $index): int
    {
        return (int) ($this->items[$index]['quantity'] ?? 0) * (int) ($this->items[$index]['unit_price'] ?? 0);
    }

    #[Computed]
    public function total(): int
    {
        return array_sum(array_map(fn (int $index) => $this->itemSubtotal($index), array_keys($this->items)));
    }

    public function addItem(): void
    {
        $this->items[] = ['name' => '', 'quantity' => 1, 'unit' => 'buah', 'unit_price' => null];
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function removeAttachment(int $attachmentId): void
    {
        $this->removedAttachmentIds[] = $attachmentId;
    }

    public function save(SaveBudgetProposal $saveBudgetProposal): void
    {
        $proposal = $this->persist($saveBudgetProposal);

        $this->dispatch('toast', variant: 'success', text: 'Draft pengajuan disimpan.');
        $this->redirectRoute('budgets.show', $proposal, navigate: true);
    }

    public function submit(SaveBudgetProposal $saveBudgetProposal, SubmitBudgetProposal $submitBudgetProposal): void
    {
        $proposal = $this->persist($saveBudgetProposal);
        $submitBudgetProposal->handle($proposal, auth()->user());

        $this->dispatch('toast', variant: 'success', text: 'Pengajuan '.$proposal->number.' dikirim ke Kepala Sekolah.');
        $this->redirectRoute('budgets.show', $proposal, navigate: true);
    }

    private function persist(SaveBudgetProposal $saveBudgetProposal): BudgetProposal
    {
        $this->authorize('manage-budgets');

        if ($this->level === null) {
            throw ValidationException::withMessages(['proposal' => 'Pilih jenjang tertentu di kanan atas terlebih dahulu.']);
        }

        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'categoryId' => ['required', 'integer', Rule::in($this->categories->modelKeys())],
            'reason' => ['required', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit' => ['required', 'string', 'max:30'],
            'items.*.unit_price' => ['required', 'integer', 'min:1'],
            'newAttachments.*' => ['file', 'mimes:pdf,jpg,jpeg,png,xls,xlsx,doc,docx', 'max:5120'],
        ], [], [
            'title' => 'nama kegiatan',
            'categoryId' => 'kategori anggaran',
            'reason' => 'alasan pengajuan',
            'items.*.name' => 'nama barang/jasa',
            'items.*.quantity' => 'jumlah',
            'items.*.unit' => 'satuan',
            'items.*.unit_price' => 'harga satuan',
            'newAttachments.*' => 'lampiran',
        ]);

        $proposal = $saveBudgetProposal->handle(
            actor: auth()->user(),
            proposal: $this->proposal,
            level: $this->level,
            category: $this->categories->firstWhere('id', $this->categoryId),
            title: $this->title,
            reason: $this->reason,
            items: array_map(fn (array $item) => [
                'name' => $item['name'],
                'quantity' => (int) $item['quantity'],
                'unit' => $item['unit'],
                'unit_price' => (int) $item['unit_price'],
            ], array_values($this->items)),
            newAttachments: $this->newAttachments,
            removedAttachmentIds: $this->removedAttachmentIds,
        );

        $this->proposalId = $proposal->id;
        $this->reset('newAttachments', 'removedAttachmentIds');

        return $proposal;
    }

    public function render(): mixed
    {
        return $this->view()->title($this->proposalId ? 'Ubah pengajuan anggaran' : 'Buat pengajuan anggaran');
    }
}; ?>

<div class="pb-24">
    <a href="{{ $this->proposal ? route('budgets.show', $this->proposal) : route('budgets.index') }}" class="btn btn-ghost btn-sm mb-4" wire:navigate>
        <x-icon name="arrow-left" class="size-4" /> Kembali
    </a>

    <x-page-header
        :title="$this->proposal ? 'Ubah pengajuan anggaran' : 'Buat pengajuan anggaran'"
        :meta="['Jenjang '.($this->level?->name ?? '-'), $this->proposal?->number, 'Pengaju: '.auth()->user()->name]"
    />

    @if ($this->level === null)
        <div role="alert" class="alert alert-info mb-6"><span>Pilih jenjang tertentu melalui tombol <strong>Jenjang</strong> di kanan atas sebelum membuat pengajuan.</span></div>
    @endif

    @if ($this->proposal?->status === \App\Enums\BudgetStatus::NeedsRevision)
        @php $lastNote = $this->proposal->approvals->last(); @endphp
        <div role="alert" class="alert alert-info mb-6">
            <div>
                <div class="font-semibold">Diminta revisi oleh {{ $lastNote?->user?->name }} ({{ $lastNote?->role?->label() }})</div>
                <div class="text-sm">{{ $lastNote?->notes }}</div>
            </div>
        </div>
    @endif

    <form wire:submit="save" class="space-y-6">
        <div class="card border border-base-300">
            <div class="card-body gap-4">
                <h2 class="card-title text-base">Informasi pengajuan</h2>
                <div class="grid gap-4 md:grid-cols-2">
                    <x-input wire:model="title" label="Nama kegiatan" placeholder="contoh: Pengadaan laptop laboratorium" required />
                    <x-select wire:model="categoryId" label="Kategori anggaran" placeholder="Pilih kategori" required>
                        @foreach ($this->categories as $category)
                            <option value="{{ $category->id }}" wire:key="category-{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </x-select>
                </div>
            </div>
        </div>

        <div class="card border border-base-300">
            <div class="card-body gap-4">
                <div class="flex items-center justify-between">
                    <h2 class="card-title text-base">Rincian kebutuhan (RAB)</h2>
                    <button type="button" class="btn btn-sm btn-outline" wire:click="addItem">
                        <x-icon name="plus" class="size-4" /> Tambah baris
                    </button>
                </div>
                @error('items') <p class="text-sm text-error">{{ $message }}</p> @enderror
                <div class="overflow-x-auto">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th class="min-w-56">Nama barang/jasa</th>
                                <th class="w-24">Jumlah</th>
                                <th class="w-28">Satuan</th>
                                <th class="w-40">Harga satuan</th>
                                <th class="w-40 text-end">Total</th>
                                <th class="w-10"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($items as $index => $item)
                                <tr wire:key="item-{{ $index }}">
                                    <td>
                                        <input type="text" class="input input-sm w-full @error('items.'.$index.'.name') input-error @enderror" wire:model.blur="items.{{ $index }}.name" aria-label="Nama barang/jasa baris {{ $index + 1 }}" />
                                        @error('items.'.$index.'.name') <p class="text-xs text-error">{{ $message }}</p> @enderror
                                    </td>
                                    <td><input type="number" min="1" class="input input-sm w-full @error('items.'.$index.'.quantity') input-error @enderror" wire:model.live.debounce.400ms="items.{{ $index }}.quantity" aria-label="Jumlah baris {{ $index + 1 }}" /></td>
                                    <td><input type="text" class="input input-sm w-full @error('items.'.$index.'.unit') input-error @enderror" wire:model.blur="items.{{ $index }}.unit" aria-label="Satuan baris {{ $index + 1 }}" /></td>
                                    <td>
                                        <x-money-input wire:model.live.debounce.400ms="items.{{ $index }}.unit_price" size="sm" class="min-w-36" aria-label="Harga satuan baris {{ $index + 1 }}" />
                                    </td>
                                    <td class="text-end font-mono">{{ \Illuminate\Support\Number::rupiah($this->itemSubtotal($index)) }}</td>
                                    <td>
                                        @if (count($items) > 1)
                                            <button type="button" class="btn btn-ghost btn-xs btn-square text-error" wire:click="removeItem({{ $index }})" aria-label="Hapus baris {{ $index + 1 }}">
                                                <x-icon name="trash" class="size-4" />
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            <tr class="text-base font-semibold">
                                <td colspan="4" class="text-end">Total</td>
                                <td class="text-end font-mono">{{ \Illuminate\Support\Number::rupiah($this->total) }}</td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card border border-base-300">
            <div class="card-body gap-4">
                <h2 class="card-title text-base">Alasan & lampiran</h2>
                <x-textarea wire:model="reason" label="Alasan pengajuan (tujuan dan kebutuhan)" rows="4" required />

                <fieldset class="fieldset p-0">
                    <legend class="fieldset-legend pt-0">Lampiran RAB & dokumen pendukung (PDF, gambar, Excel, atau Word, maks. 5 MB per file)</legend>
                    @if ($this->proposal)
                        <ul class="mb-2 space-y-1">
                            @foreach ($this->proposal->attachments->whereNotIn('id', $removedAttachmentIds) as $attachment)
                                <li class="flex items-center justify-between rounded-box border border-base-300 bg-base-100 px-3 py-2 text-sm" wire:key="attachment-{{ $attachment->id }}">
                                    <a href="{{ route('budgets.attachment', [$this->proposal, $attachment]) }}" class="link">{{ $attachment->original_name }}</a>
                                    <button type="button" class="btn btn-ghost btn-xs text-error" wire:click="removeAttachment({{ $attachment->id }})">Hapus</button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <input type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.xls,.xlsx,.doc,.docx" class="file-input w-full" wire:model="newAttachments" aria-label="Lampiran" />
                    <div wire:loading wire:target="newAttachments" class="label">Mengunggah…</div>
                    @error('newAttachments.*') <p class="label text-error">{{ $message }}</p> @enderror
                </fieldset>
            </div>
        </div>

        @error('proposal') <div role="alert" class="alert alert-error"><span>{{ $message }}</span></div> @enderror

        <div class="sticky bottom-0 flex flex-wrap items-center justify-between gap-3 rounded-box border border-base-300 bg-base-100 p-4 shadow">
            <div>
                <div class="text-sm text-base-content/60">Total pengajuan</div>
                <div class="text-xl font-semibold">{{ \Illuminate\Support\Number::rupiah($this->total) }}</div>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn" wire:loading.attr="disabled">Simpan draft</button>
                <x-confirm-button action="submit()" title="Kirim pengajuan" message="Kirim pengajuan ke Kepala Sekolah? Setelah dikirim, pengajuan tidak bisa diubah kecuali diminta revisi." confirm-label="Kirim" class="btn btn-primary" wire:loading.attr="disabled">
                    Kirim pengajuan
                </x-confirm-button>
            </div>
        </div>
    </form>
</div>
