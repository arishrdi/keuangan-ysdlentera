<?php

use App\Enums\BudgetStatus;
use App\Models\BudgetProposal;
use App\Models\Level;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Anggaran')] class extends Component {
    use WithPagination;

    #[Url]
    public string $tab = '';

    #[Url(as: 'cari')]
    public string $search = '';

    public function mount(): void
    {
        if ($this->tab === '' && $this->awaitingStatus() !== null) {
            $this->tab = 'antrean';
        }
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('manage-budgets');
    }

    #[Computed]
    public function activeLevel(): ?Level
    {
        return Level::active();
    }

    /**
     * The status waiting for the current user's decision, if the user approves proposals.
     */
    public function awaitingStatus(): ?BudgetStatus
    {
        return collect(BudgetStatus::cases())->first(fn (BudgetStatus $status) => $status->awaitingRole() === auth()->user()->role);
    }

    /**
     * @return Builder<BudgetProposal>
     */
    private function scoped(): Builder
    {
        return BudgetProposal::query()->when($this->activeLevel, fn ($query, Level $level) => $query->whereBelongsTo($level));
    }

    #[Computed]
    public function queueCount(): int
    {
        $status = $this->awaitingStatus();

        return $status ? $this->scoped()->where('status', $status)->count() : 0;
    }

    /**
     * @return array<string, array{total: int, realized: int}>
     */
    #[Computed]
    public function totals(): array
    {
        $proposals = $this->scoped()->withRealizedAmount()->get();

        return [
            'submitted' => ['total' => (int) $proposals->where('status', '!=', BudgetStatus::Draft)->sum('total_amount'), 'realized' => 0],
            'approved' => [
                'total' => (int) $proposals->filter(fn (BudgetProposal $proposal) => $proposal->status->acceptsExpenses() || $proposal->status === BudgetStatus::Completed)->sum('total_amount'),
                'realized' => (int) $proposals->sum('realized_amount'),
            ],
        ];
    }

    /**
     * @return LengthAwarePaginator<int, BudgetProposal>
     */
    #[Computed]
    public function proposals(): LengthAwarePaginator
    {
        return $this->scoped()
            ->with(['level', 'category', 'creator'])
            ->withRealizedAmount()
            ->when($this->tab === 'antrean', fn ($query) => $query->where('status', $this->awaitingStatus()))
            ->when($this->tab === 'proses', fn ($query) => $query->whereIn('status', [BudgetStatus::AwaitingPrincipal, BudgetStatus::AwaitingFoundation, BudgetStatus::NeedsRevision]))
            ->when($this->tab === 'disetujui', fn ($query) => $query->whereIn('status', [BudgetStatus::Approved, BudgetStatus::Disbursing, BudgetStatus::Completed]))
            ->when($this->tab === 'ditolak', fn ($query) => $query->whereIn('status', [BudgetStatus::RejectedByPrincipal, BudgetStatus::RejectedByFoundation]))
            ->when($this->tab === 'draft', fn ($query) => $query->where('status', BudgetStatus::Draft))
            ->when(! $this->canManage, fn ($query) => $query->where('status', '!=', BudgetStatus::Draft))
            ->when($this->search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('title', 'like', '%'.$this->search.'%')
                ->orWhere('number', 'like', '%'.$this->search.'%')))
            ->latest('updated_at')
            ->paginate(20);
    }
}; ?>

<div>
    <x-page-header title="Anggaran" :meta="[($this->activeLevel ? 'Jenjang '.$this->activeLevel->name : 'Semua jenjang')]" description="Pengajuan, persetujuan, dan realisasi anggaran.">
        <x-slot:actions>
            @if ($this->canManage)
                <a href="{{ route('budgets.create') }}" class="btn btn-primary" wire:navigate>
                    <x-icon name="plus" class="size-4" /> Buat pengajuan
                </a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="stats stats-vertical mb-6 w-full border border-base-300 md:stats-horizontal">
        <div class="stat">
            <div class="stat-title">Total diajukan</div>
            <div class="stat-value text-2xl">{{ \Illuminate\Support\Number::rupiah($this->totals['submitted']['total']) }}</div>
        </div>
        <div class="stat">
            <div class="stat-title">Total disetujui</div>
            <div class="stat-value text-2xl text-success">{{ \Illuminate\Support\Number::rupiah($this->totals['approved']['total']) }}</div>
        </div>
        <div class="stat">
            <div class="stat-title">Realisasi</div>
            <div class="stat-value text-2xl">{{ \Illuminate\Support\Number::rupiah($this->totals['approved']['realized']) }}</div>
            <div class="stat-desc">Sisa {{ \Illuminate\Support\Number::rupiah($this->totals['approved']['total'] - $this->totals['approved']['realized']) }}</div>
        </div>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <div role="tablist" class="tabs tabs-box w-fit">
            @if ($this->awaitingStatus())
                <button type="button" role="tab" @class(['tab gap-2', 'tab-active' => $tab === 'antrean']) wire:click="$set('tab', 'antrean')">
                    Menunggu keputusan saya
                    @if ($this->queueCount > 0)<span class="badge badge-sm badge-warning">{{ $this->queueCount }}</span>@endif
                </button>
            @endif
            <button type="button" role="tab" @class(['tab', 'tab-active' => $tab === '']) wire:click="$set('tab', '')">Semua</button>
            <button type="button" role="tab" @class(['tab', 'tab-active' => $tab === 'proses']) wire:click="$set('tab', 'proses')">Dalam proses</button>
            <button type="button" role="tab" @class(['tab', 'tab-active' => $tab === 'disetujui']) wire:click="$set('tab', 'disetujui')">Disetujui</button>
            <button type="button" role="tab" @class(['tab', 'tab-active' => $tab === 'ditolak']) wire:click="$set('tab', 'ditolak')">Ditolak</button>
            @if ($this->canManage)
                <button type="button" role="tab" @class(['tab', 'tab-active' => $tab === 'draft']) wire:click="$set('tab', 'draft')">Draft</button>
            @endif
        </div>
        <label class="input input-sm ms-auto w-56">
            <x-icon name="magnifying-glass" class="size-4 opacity-60" />
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Cari kegiatan / nomor" />
        </label>
    </div>

    <div class="overflow-x-auto rounded-box border border-base-300">
        <table class="table">
            <thead>
                <tr>
                    <th>Nomor</th>
                    <th>Kegiatan</th>
                    <th>Kategori</th>
                    <th class="text-end">Total</th>
                    <th class="text-end">Realisasi</th>
                    <th>Status</th>
                    <th>Diperbarui</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->proposals as $proposal)
                    <tr wire:key="proposal-{{ $proposal->id }}" class="hover:bg-base-200">
                        <td class="font-mono text-xs">{{ $proposal->number ?? '—' }}</td>
                        <td>
                            <a href="{{ route('budgets.show', $proposal) }}" class="link link-hover font-medium" wire:navigate>{{ $proposal->title }}</a>
                            @unless ($this->activeLevel)<span class="badge badge-outline badge-xs">{{ $proposal->level->code }}</span>@endunless
                        </td>
                        <td class="text-sm">{{ $proposal->category->name }}</td>
                        <td class="text-end font-mono">{{ \Illuminate\Support\Number::rupiah($proposal->total_amount) }}</td>
                        <td class="text-end font-mono">{{ $proposal->realized_amount ? \Illuminate\Support\Number::rupiah($proposal->realized_amount) : '-' }}</td>
                        <td><span class="badge badge-sm {{ $proposal->status->badgeClass() }}">{{ $proposal->status->label() }}</span></td>
                        <td class="text-sm text-base-content/70">{{ $proposal->updated_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-8 text-center text-base-content/60">Tidak ada pengajuan.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->proposals->links() }}</div>
</div>
