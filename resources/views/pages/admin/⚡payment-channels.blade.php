<?php

use App\Enums\CashAccountType;
use App\Enums\PaymentChannelType;
use App\Models\ActivityLog;
use App\Models\CashAccount;
use App\Models\Level;
use App\Models\PaymentChannel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Rekening & QRIS')] class extends Component {
    use WithFileUploads;

    public ?int $editingId = null;

    public ?int $level_id = null;

    public ?int $cash_account_id = null;

    public string $type = 'rekening';

    public string $name = '';

    public string $bank_name = '';

    public string $account_number = '';

    public string $account_holder = '';

    public ?UploadedFile $qrisImage = null;

    public bool $is_active = true;

    #[Computed]
    public function activeLevel(): ?Level
    {
        return Level::active();
    }

    /**
     * @return Collection<int, Level>
     */
    #[Computed]
    public function levels(): Collection
    {
        return Level::query()->ordered()->get();
    }

    /**
     * Bank accounts of the level chosen in the form.
     *
     * @return Collection<int, CashAccount>
     */
    #[Computed]
    public function bankAccounts(): Collection
    {
        return CashAccount::query()
            ->where('level_id', $this->level_id)
            ->where('type', CashAccountType::Bank)
            ->active()
            ->orderBy('name')
            ->get();
    }

    public function updatedLevelId(): void
    {
        $this->cash_account_id = null;
    }

    /**
     * @return Collection<int, PaymentChannel>
     */
    #[Computed]
    public function channels(): Collection
    {
        return PaymentChannel::query()
            ->with('level', 'cashAccount')
            ->when($this->activeLevel, fn ($query, Level $level) => $query->whereBelongsTo($level))
            ->orderBy(Level::query()->select('sort_order')->whereColumn('levels.id', 'payment_channels.level_id'))
            ->orderBy('type')
            ->orderBy('name')
            ->get();
    }

    public function create(): void
    {
        $this->authorize('manage-payment-settings');

        $this->resetValidation();
        $this->reset('editingId', 'name', 'bank_name', 'account_number', 'account_holder', 'qrisImage', 'cash_account_id');
        $this->type = PaymentChannelType::BankAccount->value;
        $this->is_active = true;
        $this->level_id = $this->activeLevel?->id;

        $this->dispatch('open-modal', name: 'channel-form');
    }

    public function edit(PaymentChannel $channel): void
    {
        $this->authorize('manage-payment-settings');

        $this->resetValidation();
        $this->reset('qrisImage');
        $this->editingId = $channel->id;
        $this->level_id = $channel->level_id;
        $this->cash_account_id = $channel->cash_account_id;
        $this->type = $channel->type->value;
        $this->name = $channel->name;
        $this->bank_name = (string) $channel->bank_name;
        $this->account_number = (string) $channel->account_number;
        $this->account_holder = (string) $channel->account_holder;
        $this->is_active = $channel->is_active;

        $this->dispatch('open-modal', name: 'channel-form');
    }

    public function save(): void
    {
        $this->authorize('manage-payment-settings');

        $isBankAccount = $this->type === PaymentChannelType::BankAccount->value;
        $existing = $this->editingId ? PaymentChannel::query()->findOrFail($this->editingId) : null;

        $validated = $this->validate([
            'level_id' => ['required', 'integer', Rule::exists(Level::class, 'id')],
            'cash_account_id' => [
                'required', 'integer',
                Rule::exists(CashAccount::class, 'id')->where('level_id', $this->level_id)->where('type', CashAccountType::Bank->value),
            ],
            'type' => ['required', Rule::enum(PaymentChannelType::class)],
            'name' => ['required', 'string', 'max:100'],
            'bank_name' => [Rule::requiredIf($isBankAccount), 'nullable', 'string', 'max:100'],
            'account_number' => [Rule::requiredIf($isBankAccount), 'nullable', 'string', 'max:50'],
            'account_holder' => [Rule::requiredIf($isBankAccount), 'nullable', 'string', 'max:100'],
            'qrisImage' => [Rule::requiredIf(! $isBankAccount && $existing?->qris_image_path === null), 'nullable', 'image', 'max:2048'],
            'is_active' => ['boolean'],
        ], [], [
            'level_id' => 'jenjang',
            'cash_account_id' => 'akun kas penampung',
            'type' => 'jenis',
            'name' => 'nama',
            'bank_name' => 'nama bank',
            'account_number' => 'nomor rekening',
            'account_holder' => 'atas nama',
            'qrisImage' => 'gambar QRIS',
        ]);

        $attributes = [
            'level_id' => $validated['level_id'],
            'cash_account_id' => $validated['cash_account_id'],
            'type' => $validated['type'],
            'name' => $validated['name'],
            'bank_name' => $isBankAccount ? $validated['bank_name'] : null,
            'account_number' => $isBankAccount ? $validated['account_number'] : null,
            'account_holder' => $isBankAccount ? $validated['account_holder'] : null,
            'is_active' => $validated['is_active'],
        ];

        if ($this->qrisImage !== null && ! $isBankAccount) {
            $attributes['qris_image_path'] = $this->qrisImage->store('qris', 'local');
        }

        $channel = PaymentChannel::query()->updateOrCreate(['id' => $this->editingId], $attributes);

        ActivityLog::record(
            $existing ? 'payment_channel.updated' : 'payment_channel.created',
            ($existing ? 'Mengubah' : 'Menambah').' '.$channel->type->label().' '.$channel->name,
            $channel,
        );

        $this->dispatch('close-modal', name: 'channel-form');
        $this->dispatch('toast', variant: 'success', text: 'Tujuan pembayaran disimpan.');
        $this->reset('qrisImage');
        unset($this->channels);
    }
}; ?>

<div>
    <x-page-header title="Rekening & QRIS" description="Tujuan pembayaran yang ditampilkan kepada wali murid. Setiap jenjang dapat memiliki beberapa rekening dan QRIS.">
        <x-slot:actions>
            <button type="button" class="btn btn-primary" wire:click="create">
                <x-icon name="plus" class="size-4" /> Tambah tujuan
            </button>
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($this->channels as $channel)
            <div class="card border border-base-300" wire:key="channel-{{ $channel->id }}">
                <div class="card-body">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="badge badge-outline">{{ $channel->level->code }}</span>
                                <span class="badge {{ $channel->type === \App\Enums\PaymentChannelType::Qris ? 'badge-accent' : 'badge-primary' }}">{{ $channel->type->label() }}</span>
                                @unless ($channel->is_active)
                                    <span class="badge badge-ghost">Nonaktif</span>
                                @endunless
                            </div>
                            <h3 class="mt-2 font-semibold">{{ $channel->name }}</h3>
                            <p @class(['text-xs', 'text-error' => $channel->cashAccount === null, 'text-base-content/60' => $channel->cashAccount !== null])>
                                Masuk ke: {{ $channel->cashAccount?->name ?? 'belum dihubungkan ke akun kas' }}
                            </p>
                        </div>
                        <button type="button" class="btn btn-sm btn-ghost btn-square" wire:click="edit({{ $channel->id }})" aria-label="Ubah">
                            <x-icon name="pencil-square" class="size-4" />
                        </button>
                    </div>

                    @if ($channel->type === \App\Enums\PaymentChannelType::Qris)
                        <img src="{{ route('payment-channels.qris', $channel) }}" alt="QRIS {{ $channel->name }}" class="mx-auto mt-2 max-h-48 rounded-box border border-base-300 bg-white p-2" />
                    @else
                        <dl class="text-sm">
                            <div><dt class="inline text-base-content/60">Bank:</dt> <dd class="inline">{{ $channel->bank_name }}</dd></div>
                            <div><dt class="inline text-base-content/60">No. rekening:</dt> <dd class="inline font-mono">{{ $channel->account_number }}</dd></div>
                            <div><dt class="inline text-base-content/60">Atas nama:</dt> <dd class="inline">{{ $channel->account_holder }}</dd></div>
                        </dl>
                    @endif
                </div>
            </div>
        @empty
            <div class="rounded-box border border-dashed border-base-300 p-6 text-sm text-base-content/70 md:col-span-2 xl:col-span-3">
                Belum ada rekening atau QRIS. Wali murid tidak bisa membayar secara transfer sebelum tujuan pembayaran ditambahkan.
            </div>
        @endforelse
    </div>

    <x-modal name="channel-form" :title="$editingId ? 'Ubah tujuan pembayaran' : 'Tambah tujuan pembayaran'">
        <form wire:submit="save" class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-select wire:model.live="level_id" label="Jenjang" placeholder="Pilih jenjang" required>
                    @foreach ($this->levels as $level)
                        <option value="{{ $level->id }}" wire:key="form-level-{{ $level->id }}">{{ $level->name }}</option>
                    @endforeach
                </x-select>
                <x-select wire:model.live="type" label="Jenis" required>
                    @foreach (\App\Enums\PaymentChannelType::cases() as $channelType)
                        <option value="{{ $channelType->value }}" wire:key="type-{{ $channelType->value }}">{{ $channelType->label() }}</option>
                    @endforeach
                </x-select>
            </div>
            <x-input wire:model="name" label="Nama tampilan" placeholder="contoh: BSI SD Lentera" required />
            <x-select wire:model="cash_account_id" label="Uang masuk ke akun kas" placeholder="Pilih rekening bank di Akun Kas" required>
                @foreach ($this->bankAccounts as $bankAccount)
                    <option value="{{ $bankAccount->id }}" wire:key="bank-account-{{ $bankAccount->id }}">{{ $bankAccount->name }} · {{ $bankAccount->account_number }}</option>
                @endforeach
            </x-select>
            @if ($level_id && $this->bankAccounts->isEmpty())
                <p class="text-sm text-warning">Jenjang ini belum punya rekening bank di menu Akun Kas. Tambahkan terlebih dahulu.</p>
            @endif

            @if ($type === 'rekening')
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-input wire:model="bank_name" label="Nama bank" required />
                    <x-input wire:model="account_number" label="Nomor rekening" inputmode="numeric" required />
                </div>
                <x-input wire:model="account_holder" label="Atas nama" required />
            @else
                <fieldset class="fieldset p-0">
                    <label for="qris-image" class="fieldset-legend pt-0">Gambar QRIS (maks. 2 MB)</label>
                    <input id="qris-image" type="file" accept="image/*" class="file-input w-full" wire:model="qrisImage" />
                    <div wire:loading wire:target="qrisImage" class="label">Mengunggah…</div>
                    @error('qrisImage') <p class="label text-error">{{ $message }}</p> @enderror
                </fieldset>
            @endif

            <label class="label cursor-pointer gap-2 text-base-content">
                <input type="checkbox" class="toggle toggle-primary toggle-sm" wire:model="is_active" />
                <span class="text-sm">Tampilkan kepada wali murid</span>
            </label>

            <div class="modal-action">
                <button type="button" class="btn" x-on:click="$el.closest('dialog').close()">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </x-modal>
</div>
