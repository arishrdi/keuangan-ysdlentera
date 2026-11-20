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
        <div class="flex items-center justify-between px-2 py-1">
            <span class="font-semibold">Notifikasi</span>
            @if ($this->unreadCount > 0)
                <button type="button" class="link text-xs" wire:click="markAllAsRead">Tandai semua dibaca</button>
            @endif
        </div>
        <ul class="menu w-full p-0">
            @forelse ($this->notifications as $notification)
                <li wire:key="notification-{{ $notification->id }}">
                    <button type="button" wire:click="open('{{ $notification->id }}')" @class(['flex-col items-start gap-0', 'bg-primary/5' => $notification->unread()])>
                        <span class="font-medium">{{ $notification->data['title'] ?? 'Notifikasi' }}</span>
                        <span class="text-xs text-base-content/70">{{ $notification->data['message'] ?? '' }}</span>
                        <span class="text-xs text-base-content/50">{{ $notification->created_at->diffForHumans() }}</span>
                    </button>
                </li>
            @empty
                <li class="px-2 py-4 text-center text-sm text-base-content/60">Belum ada notifikasi.</li>
            @endforelse
        </ul>
    </div>
</details>
