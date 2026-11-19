{{-- Listens for the Livewire `toast` event: $this->dispatch('toast', variant: 'success', text: '...') --}}
<div
    x-data="{
        toasts: [],
        show(detail) {
            const id = Date.now() + Math.random();

            this.toasts.push({ id, variant: detail.variant ?? 'info', text: detail.text ?? '' });

            setTimeout(() => this.toasts = this.toasts.filter((toast) => toast.id !== id), detail.duration ?? 5000);
        },
    }"
    x-on:toast.window="show($event.detail)"
    class="toast toast-end z-50"
    role="status"
    aria-live="polite"
>
    <template x-for="toast in toasts" :key="toast.id">
        <div
            class="alert"
            :class="{
                'alert-success': toast.variant === 'success',
                'alert-error': toast.variant === 'danger' || toast.variant === 'error',
                'alert-warning': toast.variant === 'warning',
                'alert-info': toast.variant === 'info',
            }"
        >
            <span x-text="toast.text"></span>
        </div>
    </template>
</div>
