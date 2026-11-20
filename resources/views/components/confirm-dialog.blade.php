{{--
    One confirmation dialog for the whole app, opened by <x-confirm-button> (or any
    `$dispatch('confirm-action', { title, message, confirmLabel, danger, run })`).
    It replaces the browser's confirm() so every confirmation looks like the app.
--}}
<dialog
    class="modal"
    aria-labelledby="confirm-dialog-title"
    x-data="{
        title: '',
        message: '',
        confirmLabel: 'Lanjutkan',
        danger: false,
        run: null,
        show(detail) {
            this.title = detail.title ?? 'Konfirmasi';
            this.message = detail.message ?? '';
            this.confirmLabel = detail.confirmLabel ?? 'Lanjutkan';
            this.danger = Boolean(detail.danger);
            this.run = detail.run ?? null;
            this.$root.showModal();
            this.$nextTick(() => this.$refs.cancel.focus());
        },
        proceed() {
            const run = this.run;
            this.run = null;
            this.$root.close();
            if (run) run();
        },
    }"
    x-on:confirm-action.window="show($event.detail)"
>
    <div class="modal-box max-w-sm">
        <h3 id="confirm-dialog-title" class="text-lg font-bold tracking-tight" x-text="title"></h3>
        <p class="mt-2 text-sm text-base-content/75" x-text="message"></p>

        <div class="modal-action">
            <button type="button" class="btn" x-ref="cancel" x-on:click="$root.close()">Batal</button>
            <button type="button" class="btn" x-bind:class="danger ? 'btn-error' : 'btn-primary'" x-on:click="proceed()" x-text="confirmLabel"></button>
        </div>
    </div>

    <form method="dialog" class="modal-backdrop">
        <button>Tutup</button>
    </form>
</dialog>
