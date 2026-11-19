<?php

use App\Models\ActivityLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Riwayat Aktivitas')] class extends Component {
    use WithPagination;

    #[Url(as: 'cari')]
    public string $search = '';

    #[Url(as: 'dari')]
    public string $from = '';

    #[Url(as: 'sampai')]
    public string $until = '';

    public function updated(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, ActivityLog>
     */
    #[Computed]
    public function activities(): LengthAwarePaginator
    {
        return ActivityLog::query()
            ->with('user')
            ->when($this->search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('description', 'like', '%'.$this->search.'%')
                ->orWhereHas('user', fn ($query) => $query->where('name', 'like', '%'.$this->search.'%'))))
            ->when($this->from !== '', fn ($query) => $query->whereDate('created_at', '>=', $this->from))
            ->when($this->until !== '', fn ($query) => $query->whereDate('created_at', '<=', $this->until))
            ->latest('id')
            ->paginate(50);
    }
}; ?>

<div>
    <x-page-header title="Riwayat Aktivitas" description="Catatan perubahan data penting oleh seluruh pengguna." />

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <label class="input input-sm w-64">
            <x-icon name="magnifying-glass" class="size-4 opacity-60" />
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Cari aktivitas / pengguna" />
        </label>
        <input type="date" class="input input-sm w-40" wire:model.live="from" aria-label="Dari tanggal" />
        <span class="text-sm">s.d.</span>
        <input type="date" class="input input-sm w-40" wire:model.live="until" aria-label="Sampai tanggal" />
    </div>

    <div class="overflow-x-auto rounded-box border border-base-300">
        <table class="table">
            <thead>
                <tr>
                    <th>Waktu</th>
                    <th>Pengguna</th>
                    <th>Aktivitas</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->activities as $activity)
                    <tr wire:key="activity-{{ $activity->id }}">
                        <td class="whitespace-nowrap text-sm">{{ $activity->created_at->translatedFormat('j M Y H:i') }}</td>
                        <td class="text-sm">
                            {{ $activity->user?->name ?? 'Sistem' }}
                            @if ($activity->user)
                                <br><span class="text-base-content/60">{{ $activity->user->role->label() }}</span>
                            @endif
                        </td>
                        <td>{{ $activity->description }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="py-8 text-center text-base-content/60">Belum ada aktivitas.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->activities->links() }}</div>
</div>
