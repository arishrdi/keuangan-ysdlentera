<?php

use App\Actions\Budgets\CompleteBudgetProposal;
use App\Actions\Budgets\DecideBudgetProposal;
use App\Actions\Budgets\StartBudgetDisbursement;
use App\Actions\Budgets\SubmitBudgetProposal;
use App\Enums\BudgetAction;
use App\Enums\BudgetStatus;
use App\Models\BudgetProposal;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public BudgetProposal $budgetProposal;

    public string $notes = '';

    public string $completionNotes = '';

    public function mount(BudgetProposal $budgetProposal): void
    {
        abort_if($budgetProposal->status === BudgetStatus::Draft && ! Gate::allows('manage-budgets'), 404);
    }

    #[Computed]
    public function proposal(): BudgetProposal
    {
        return $this->budgetProposal->fresh(['level', 'category', 'creator', 'items', 'attachments', 'approvals.user', 'expenses.cashAccount'])->loadSum(
            ['expenses as realized_amount' => fn ($query) => $query->whereNull('cancelled_at')],
            'amount',
        );
    }

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('manage-budgets');
    }

    #[Computed]
    public function canDecide(): bool
    {
        $role = $this->proposal->status->awaitingRole();

        return $role !== null && auth()->user()->hasRole($role);
    }

    public function decide(string $decision, DecideBudgetProposal $decideBudgetProposal): void
    {
        abort_unless($this->canDecide, 403);
        $this->resetValidation();

        $decideBudgetProposal->handle($this->proposal, auth()->user(), BudgetAction::from($decision), $this->notes ?: null);

        $this->reset('notes');
        $this->dispatch('toast', variant: 'success', text: 'Keputusan disimpan. Status: '.$this->proposal->fresh()->status->label().'.');
        unset($this->proposal, $this->canDecide);
    }

    public function submit(SubmitBudgetProposal $submitBudgetProposal): void
    {
        $this->authorize('manage-budgets');

        $submitBudgetProposal->handle($this->proposal, auth()->user());

        $this->dispatch('toast', variant: 'success', text: 'Pengajuan dikirim ke Kepala Sekolah.');
        unset($this->proposal);
    }

    public function startDisbursement(StartBudgetDisbursement $startBudgetDisbursement): void
    {
        $this->authorize('manage-budgets');

        $startBudgetDisbursement->handle($this->proposal, auth()->user());

        $this->dispatch('toast', variant: 'success', text: 'Status anggaran: Dalam Proses Pencairan.');
        unset($this->proposal);
    }

    public function complete(CompleteBudgetProposal $completeBudgetProposal): void
    {
        $this->authorize('manage-budgets');

        $completeBudgetProposal->handle($this->proposal, auth()->user(), $this->completionNotes ?: null);

        $this->dispatch('close-modal', name: 'complete-budget');
        $this->dispatch('toast', variant: 'success', text: 'Anggaran diselesaikan.');
        unset($this->proposal);
    }

    public function render(): mixed
    {
        return $this->view()->title('Anggaran '.($this->budgetProposal->number ?? $this->budgetProposal->title));
    }
}; ?>

<div>
    @php
        $proposal = $this->proposal;
        $realized = (int) $proposal->realized_amount;
    @endphp

    <a href="{{ route('budgets.index') }}" class="btn btn-ghost btn-sm mb-4" wire:navigate>
        <x-icon name="arrow-left" class="size-4" /> Kembali ke daftar anggaran
    </a>

    <x-page-header
        :title="$proposal->title"
        :description="($proposal->number ?? 'Draft').' · '.$proposal->level->name.' · '.$proposal->category->name.' · Diajukan oleh '.($proposal->creator?->name ?? '-').($proposal->submitted_at ? ' pada '.$proposal->submitted_at->translatedFormat('j F Y H:i') : '')"
    >
        <x-slot:actions>
            <span class="badge badge-lg {{ $proposal->status->badgeClass() }}">{{ $proposal->status->label() }}</span>
            @if ($this->canManage && $proposal->status->isEditable())
                <a href="{{ route('budgets.edit', $proposal) }}" class="btn btn-sm" wire:navigate>
                    <x-icon name="pencil-square" class="size-4" /> Ubah
                </a>
                <button type="button" class="btn btn-sm btn-primary" wire:click="submit" wire:confirm="Kirim pengajuan ke Kepala Sekolah?">Kirim pengajuan</button>
            @endif
        </x-slot:actions>
    </x-page-header>

    @error('proposal') <div role="alert" class="alert alert-error mb-4"><span>{{ $message }}</span></div> @enderror
    @error('decision') <div role="alert" class="alert alert-error mb-4"><span>{{ $message }}</span></div> @enderror

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="card border border-base-300">
                <div class="card-body">
                    <h2 class="card-title text-base">Rincian kebutuhan</h2>
                    <div class="overflow-x-auto">
                        <table class="table table-sm">
                            <thead>
                                <tr><th>No</th><th>Nama barang/jasa</th><th class="text-end">Jumlah</th><th class="text-end">Harga satuan</th><th class="text-end">Total</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($proposal->items as $item)
                                    <tr wire:key="item-{{ $item->id }}">
                                        <td>{{ $loop->iteration }}</td>
                                        <td>{{ $item->name }}</td>
                                        <td class="text-end">{{ $item->quantity }} {{ $item->unit }}</td>
                                        <td class="text-end font-mono">{{ \Illuminate\Support\Number::rupiah($item->unit_price) }}</td>
                                        <td class="text-end font-mono">{{ \Illuminate\Support\Number::rupiah($item->subtotal) }}</td>
                                    </tr>
                                @endforeach
                                <tr class="font-semibold">
                                    <td colspan="4" class="text-end">TOTAL</td>
                                    <td class="text-end font-mono">{{ \Illuminate\Support\Number::rupiah($proposal->total_amount) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <h3 class="mt-4 font-semibold">Alasan pengajuan</h3>
                    <p class="whitespace-pre-line text-sm">{{ $proposal->reason }}</p>

                    <h3 class="mt-4 font-semibold">Lampiran</h3>
                    <ul class="space-y-1 text-sm">
                        @forelse ($proposal->attachments as $attachment)
                            <li wire:key="attachment-{{ $attachment->id }}">
                                <a href="{{ route('budgets.attachment', [$proposal, $attachment]) }}" class="link">{{ $attachment->original_name }}</a>
                                <span class="text-base-content/60">({{ \Illuminate\Support\Number::fileSize($attachment->size) }})</span>
                            </li>
                        @empty
                            <li class="text-base-content/60">Tidak ada lampiran.</li>
                        @endforelse
                    </ul>
                </div>
            </div>

            @if ($proposal->status->acceptsExpenses() || $proposal->status === \App\Enums\BudgetStatus::Completed)
                <div class="card border border-base-300">
                    <div class="card-body">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h2 class="card-title text-base">Realisasi</h2>
                            @if ($this->canManage && $proposal->status->acceptsExpenses())
                                <div class="flex gap-2">
                                    @if ($proposal->status === \App\Enums\BudgetStatus::Approved)
                                        <button type="button" class="btn btn-sm" wire:click="startDisbursement">Mulai pencairan</button>
                                    @endif
                                    <a href="{{ route('finance.expenses', ['anggaran' => $proposal->id]) }}" class="btn btn-sm btn-primary" wire:navigate>Catat pengeluaran</a>
                                    <button type="button" class="btn btn-sm btn-outline" x-data x-on:click="$dispatch('open-modal', 'complete-budget')">Selesaikan</button>
                                </div>
                            @endif
                        </div>
                        <div class="grid grid-cols-3 gap-2 text-sm">
                            <div><div class="text-base-content/60">Disetujui</div><div class="font-mono text-lg">{{ \Illuminate\Support\Number::rupiah($proposal->total_amount) }}</div></div>
                            <div><div class="text-base-content/60">Terpakai</div><div class="font-mono text-lg text-error">{{ \Illuminate\Support\Number::rupiah($realized) }}</div></div>
                            <div><div class="text-base-content/60">Sisa</div><div class="font-mono text-lg text-success">{{ \Illuminate\Support\Number::rupiah($proposal->total_amount - $realized) }}</div></div>
                        </div>
                        <progress class="progress progress-primary" value="{{ $realized }}" max="{{ max($proposal->total_amount, 1) }}"></progress>
                        <table class="table table-sm mt-2">
                            <thead><tr><th>Tanggal</th><th>No.</th><th>Keterangan</th><th>Akun</th><th class="text-end">Jumlah</th></tr></thead>
                            <tbody>
                                @forelse ($proposal->expenses as $expense)
                                    <tr wire:key="expense-{{ $expense->id }}" @class(['opacity-50' => $expense->isCancelled()])>
                                        <td>{{ $expense->transacted_on->translatedFormat('j M Y') }}</td>
                                        <td class="font-mono text-xs">{{ $expense->number }}</td>
                                        <td>{{ $expense->description }}{{ $expense->isCancelled() ? ' (dibatalkan)' : '' }}</td>
                                        <td class="text-sm">{{ $expense->cashAccount->name }}</td>
                                        <td @class(['text-end font-mono', 'line-through' => $expense->isCancelled()])>{{ \Illuminate\Support\Number::rupiah($expense->amount) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center text-base-content/60">Belum ada pengeluaran dari anggaran ini.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>

        <div class="space-y-6">
            @if ($this->canDecide)
                <div class="card border-2 border-warning">
                    <div class="card-body gap-3">
                        <h2 class="card-title text-base">Keputusan {{ $proposal->status === \App\Enums\BudgetStatus::AwaitingPrincipal ? 'tahap 1 (Kepala Sekolah)' : 'tahap 2 (Yayasan)' }}</h2>
                        <x-textarea wire:model="notes" label="Catatan (wajib bila menolak atau minta revisi)" rows="3" />
                        <div class="grid gap-2">
                            <button type="button" class="btn btn-primary" wire:click="decide('disetujui')" wire:confirm="Setujui pengajuan ini?">Setujui</button>
                            <button type="button" class="btn btn-info btn-outline" wire:click="decide('minta_revisi')">Minta revisi</button>
                            <button type="button" class="btn btn-error btn-outline" wire:click="decide('ditolak')" wire:confirm="Tolak pengajuan ini? Penolakan bersifat final.">Tolak</button>
                        </div>
                    </div>
                </div>
            @endif

            <div class="card border border-base-300">
                <div class="card-body">
                    <h2 class="card-title text-base">Riwayat persetujuan</h2>
                    <ul class="timeline timeline-vertical timeline-compact">
                        @forelse ($proposal->approvals as $approval)
                            <li wire:key="approval-{{ $approval->id }}">
                                @unless ($loop->first)<hr />@endunless
                                <div class="timeline-middle"><span class="badge badge-xs {{ $approval->action->badgeClass() }}"></span></div>
                                <div class="timeline-end mb-4 text-sm">
                                    <div class="font-medium">{{ $approval->action->label() }}{{ $approval->stage ? ' · tahap '.$approval->stage : '' }}</div>
                                    <div class="text-base-content/70">{{ $approval->user?->name ?? 'Sistem' }}{{ $approval->role ? ' ('.$approval->role->label().')' : '' }}</div>
                                    <div class="text-xs text-base-content/50">{{ $approval->created_at->translatedFormat('j M Y H:i') }} · {{ \Illuminate\Support\Number::rupiah($approval->total_amount) }}</div>
                                    @if ($approval->notes)
                                        <div class="mt-1 rounded-box bg-base-200 p-2">{{ $approval->notes }}</div>
                                    @endif
                                </div>
                                @unless ($loop->last)<hr />@endunless
                            </li>
                        @empty
                            <li class="text-sm text-base-content/60">Belum dikirim.</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        </div>
    </div>

    @if ($this->canManage)
        <x-modal name="complete-budget" title="Selesaikan anggaran">
            <form wire:submit="complete" class="space-y-4">
                <p class="text-sm text-base-content/70">
                    Realisasi {{ \Illuminate\Support\Number::rupiah($realized) }} dari {{ \Illuminate\Support\Number::rupiah($proposal->total_amount) }}.
                    Sisa {{ \Illuminate\Support\Number::rupiah($proposal->total_amount - $realized) }} akan tercatat dan anggaran tidak bisa dipakai lagi.
                </p>
                <x-input wire:model="completionNotes" label="Catatan (opsional)" />
                <div class="modal-action">
                    <button type="button" class="btn" x-on:click="$el.closest('dialog').close()">Batal</button>
                    <button type="submit" class="btn btn-primary">Selesaikan</button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
