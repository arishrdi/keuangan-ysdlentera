<?php

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    #[Computed]
    public function unreadCount(): int
    {
        return auth()->user()->unreadNotifications()->count();
    }

    /**
     * @return Collection<int, DatabaseNotification>
     */
    #[Computed]
    public function notifications(): Collection
    {
        return auth()->user()->notifications()->latest()->limit(10)->get();
    }

    public function open(string $notificationId): void
    {
        $notification = auth()->user()->notifications()->findOrFail($notificationId);
        $notification->markAsRead();

        $this->redirect($notification->data['url'] ?? route('dashboard'), navigate: true);
    }

    public function markAllAsRead(): void
    {
        auth()->user()->unreadNotifications->markAsRead();

        unset($this->unreadCount, $this->notifications);
    }
}; ?>

<details
    x-data
    x-on:click.outside="$el.removeAttribute('open')"
    x-on:keydown.escape="$el.removeAttribute('open')"
    class="dropdown dropdown-end"
    wire:poll.60s
>
    <summary class="btn btn-ghost btn-square btn-sm" aria-label="Notifikasi">
        <div class="indicator">
            <x-icon name="bell" />
            @if ($this->unreadCount > 0)
                <span class="indicator-item badge badge-xs badge-error border-0 px-1 font-semibold">{{ $this->unreadCount > 9 ? '9+' : $this->unreadCount }}</span>
            @endif
        </div>
    </summary>

    <div class="dropdown-content z-30 mt-2 w-80 rounded-box bg-base-100 p-2 text-base-content shadow-lg ring-1 ring-base-300 max-sm:fixed max-sm:inset-x-3 max-sm:top-16 max-sm:w-auto">
        <div class="mb-1 flex items-center justify-between border-b border-base-200 px-2 pb-2 pt-1">
            <span class="font-semibold">Notifikasi</span>
            @if ($this->unreadCount > 0)
                <button type="button" class="link text-xs" wire:click="markAllAsRead">Tandai semua dibaca</button>
            @endif
        </div>
        <ul class="max-h-96 space-y-1 overflow-y-auto">
            @forelse ($this->notifications as $notification)
                <li wire:key="notification-{{ $notification->id }}">
                    <button
                        type="button"
                        wire:click="open('{{ $notification->id }}')"
                        @class([
                            'flex w-full gap-3 rounded-field px-3 py-2.5 text-left transition-colors hover:bg-base-200',
                            'bg-primary/5' => $notification->unread(),
                        ])
                    >
                        <span @class([
                            'mt-1.5 size-2 shrink-0 rounded-full',
                            'bg-primary' => $notification->unread(),
                            'bg-transparent' => ! $notification->unread(),
                        ])></span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-medium leading-snug">{{ $notification->data['title'] ?? 'Notifikasi' }}</span>
                            @if (filled($notification->data['message'] ?? null))
                                <span class="mt-0.5 line-clamp-2 block text-xs leading-relaxed text-base-content/70">{{ $notification->data['message'] }}</span>
                            @endif
                            <span class="mt-1 block text-[11px] text-base-content/50">{{ $notification->created_at->diffForHumans() }}</span>
                        </span>
                    </button>
                </li>
            @empty
                <li class="px-2 py-4 text-center text-sm text-base-content/60">Belum ada notifikasi.</li>
            @endforelse
        </ul>
    </div>
</details>
