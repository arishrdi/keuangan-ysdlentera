<?php

use App\Enums\BillingCycle;
use App\Models\AcademicYear;
use App\Models\ActivityLog;
use App\Models\FeeRate;
use App\Models\Level;
use App\Models\PaymentCategory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Kategori & Tarif')] class extends Component {
    #[Url(as: 'tahun')]
    public ?int $academicYearId = null;

    public ?int $editingId = null;

    public ?int $level_id = null;

    public ?int $payment_category_id = null;

    public ?int $grade = null;

    public ?int $amount = null;

    public function mount(): void
    {
        $this->academicYearId ??= AcademicYear::current()?->id;
    }

    /**
     * @return Collection<int, PaymentCategory>
     */
    #[Computed]
    public function categories(): Collection
    {
        return PaymentCategory::query()->with('levels')->ordered()->get();
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
     * @return Collection<int, AcademicYear>
     */
    #[Computed]
    public function academicYears(): Collection
    {
        return AcademicYear::query()->orderByDesc('starts_on')->get();
    }

    #[Computed]
    public function activeLevel(): ?Level
    {
        return Level::active();
    }

    /**
     * @return Collection<int, FeeRate>
     */
    #[Computed]
    public function feeRates(): Collection
    {
        return FeeRate::query()
            ->with(['level', 'paymentCategory'])
            ->where('academic_year_id', $this->academicYearId)
            ->when($this->activeLevel, fn ($query, Level $level) => $query->whereBelongsTo($level))
            ->orderBy(Level::query()->select('sort_order')->whereColumn('levels.id', 'fee_rates.level_id'))
            ->orderBy(PaymentCategory::query()->select('sort_order')->whereColumn('payment_categories.id', 'fee_rates.payment_category_id'))
            ->orderByRaw('grade is not null')
            ->orderBy('grade')
            ->get();
    }

    public function isActiveFor(PaymentCategory $category, Level $level): bool
    {
        return (bool) $category->levels->firstWhere('id', $level->id)?->pivot->is_active;
    }

    public function toggleActive(PaymentCategory $category, Level $level): void
    {
        $this->authorize('manage-payment-settings');

        $isActive = ! $this->isActiveFor($category->load('levels'), $level);

        $category->levels()->syncWithoutDetaching([$level->id => ['is_active' => $isActive]]);

        ActivityLog::record(
            'payment_category.toggled',
            ($isActive ? 'Mengaktifkan' : 'Menonaktifkan').' kategori '.$category->name.' untuk jenjang '.$level->name,
            $category,
            ['level_id' => $level->id, 'is_active' => $isActive],
        );

        unset($this->categories);
    }

    public function toggleInstallable(PaymentCategory $category): void
    {
        $this->authorize('manage-payment-settings');

        $category->update(['is_installable' => ! $category->is_installable]);

        ActivityLog::record(
            'payment_category.installable_changed',
            'Mengubah kategori '.$category->name.' menjadi '.($category->is_installable ? 'boleh dicicil' : 'tidak boleh dicicil'),
            $category,
        );

        unset($this->categories);
    }

    public function create(): void
    {
        $this->authorize('manage-payment-settings');

        $this->resetValidation();
        $this->reset('editingId', 'payment_category_id', 'grade', 'amount');
        $this->level_id = $this->activeLevel?->id;

        $this->dispatch('open-modal', name: 'fee-rate-form');
    }

    public function edit(FeeRate $feeRate): void
    {
        $this->authorize('manage-payment-settings');

        $this->resetValidation();
        $this->editingId = $feeRate->id;
        $this->level_id = $feeRate->level_id;
        $this->payment_category_id = $feeRate->payment_category_id;
        $this->grade = $feeRate->grade;
        $this->amount = $feeRate->amount;

        $this->dispatch('open-modal', name: 'fee-rate-form');
    }

    public function save(): void
    {
        $this->authorize('manage-payment-settings');

        $academicYearId = $this->editingId ? FeeRate::query()->findOrFail($this->editingId)->academic_year_id : $this->academicYearId;

        $validated = $this->validate([
            'level_id' => ['required', 'integer', Rule::exists(Level::class, 'id')],
            'payment_category_id' => [
                'required', 'integer',
                Rule::exists(PaymentCategory::class, 'id')->whereNot('billing_cycle', BillingCycle::Itemized->value),
            ],
            'grade' => ['nullable', 'integer', 'min:0', 'max:12'],
            'amount' => ['required', 'integer', 'min:1000'],
        ], [
            'payment_category_id.exists' => 'Tarif seragam diatur di katalog seragam.',
        ], [
            'level_id' => 'jenjang',
            'payment_category_id' => 'kategori',
            'grade' => 'tingkat',
            'amount' => 'nominal',
        ]);

        $isDuplicate = FeeRate::query()
            ->where('level_id', $validated['level_id'])
            ->where('payment_category_id', $validated['payment_category_id'])
            ->where('academic_year_id', $academicYearId)
            ->where(fn ($query) => $validated['grade'] === null ? $query->whereNull('grade') : $query->where('grade', $validated['grade']))
            ->when($this->editingId, fn ($query, int $id) => $query->whereKeyNot($id))
            ->exists();

        if ($isDuplicate) {
            $this->addError('grade', 'Tarif untuk kategori, jenjang, dan tingkat ini sudah ada.');

            return;
        }

        $feeRate = FeeRate::query()->updateOrCreate(['id' => $this->editingId], [...$validated, 'academic_year_id' => $academicYearId]);
        $feeRate->load('paymentCategory', 'level');

        ActivityLog::record(
            $this->editingId ? 'fee_rate.updated' : 'fee_rate.created',
            'Mengatur tarif '.$feeRate->paymentCategory->name.' '.$feeRate->level->name.($feeRate->grade !== null ? ' tingkat '.$feeRate->grade : '').' menjadi Rp'.number_format($feeRate->amount, 0, ',', '.'),
            $feeRate,
        );

        $this->dispatch('close-modal', name: 'fee-rate-form');
        $this->dispatch('toast', variant: 'success', text: 'Tarif disimpan.');
        unset($this->feeRates);
    }

    public function delete(FeeRate $feeRate): void
    {
        $this->authorize('manage-payment-settings');

        $feeRate->delete();

        ActivityLog::record('fee_rate.deleted', 'Menghapus tarif '.$feeRate->paymentCategory->name.' '.$feeRate->level->name, properties: ['amount' => $feeRate->amount]);

        unset($this->feeRates);
    }
}; ?>

<div>
    <x-page-header title="Kategori & Tarif" description="Atur kategori pembayaran yang berlaku per jenjang dan nominal tarif per tahun ajaran." />

    <div class="card mb-8 border border-base-300">
        <div class="card-body">
            <h2 class="card-title text-base">Kategori per jenjang</h2>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Kategori</th>
                            <th>Siklus</th>
                            @foreach ($this->levels as $level)
                                <th class="text-center" wire:key="level-head-{{ $level->id }}">{{ $level->name }}</th>
                            @endforeach
                            <th class="text-center">Boleh dicicil</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->categories as $category)
                            <tr wire:key="category-{{ $category->id }}">
                                <td class="font-medium">{{ $category->name }}</td>
                                <td class="text-sm">{{ $category->billing_cycle->label() }}</td>
                                @foreach ($this->levels as $level)
                                    <td class="text-center" wire:key="category-{{ $category->id }}-level-{{ $level->id }}">
                                        <input
                                            type="checkbox"
                                            class="toggle toggle-primary toggle-sm"
                                            @checked($this->isActiveFor($category, $level))
                                            wire:click="toggleActive({{ $category->id }}, {{ $level->id }})"
                                            aria-label="{{ $category->name }} untuk {{ $level->name }}"
                                        />
                                    </td>
                                @endforeach
                                <td class="text-center">
                                    <input
                                        type="checkbox"
                                        class="toggle toggle-accent toggle-sm"
                                        @checked($category->is_installable)
                                        wire:click="toggleInstallable({{ $category->id }})"
                                        aria-label="{{ $category->name }} boleh dicicil"
                                    />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="mb-4 flex flex-wrap items-end justify-between gap-2">
        <div>
            <h2 class="font-semibold">Tarif {{ $this->activeLevel ? 'jenjang '.$this->activeLevel->name : 'semua jenjang' }}</h2>
            <p class="text-sm text-base-content/70">Tarif tanpa tingkat berlaku untuk semua tingkat. Tarif per tingkat diutamakan bila ada. Tarif seragam diatur di menu Katalog Seragam.</p>
        </div>
        <div class="flex gap-2">
            <select class="select select-sm w-40" wire:model.live="academicYearId" aria-label="Tahun ajaran">
                @foreach ($this->academicYears as $academicYear)
                    <option value="{{ $academicYear->id }}" wire:key="year-{{ $academicYear->id }}">{{ $academicYear->name }}{{ $academicYear->is_active ? ' (aktif)' : '' }}</option>
                @endforeach
            </select>
            <button type="button" class="btn btn-primary btn-sm" wire:click="create" @disabled($academicYearId === null)>
                <x-icon name="plus" class="size-4" /> Tambah tarif
            </button>
        </div>
    </div>

    <div class="overflow-x-auto rounded-box border border-base-300">
        <table class="table">
            <thead>
                <tr>
                    <th>Jenjang</th>
                    <th>Kategori</th>
                    <th>Tingkat</th>
                    <th class="text-end">Nominal</th>
                    <th class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->feeRates as $feeRate)
                    <tr wire:key="fee-rate-{{ $feeRate->id }}">
                        <td><span class="badge badge-outline">{{ $feeRate->level->code }}</span></td>
                        <td class="font-medium">{{ $feeRate->paymentCategory->name }}</td>
                        <td>{{ $feeRate->grade ?? 'Semua tingkat' }}</td>
                        <td class="text-end font-mono">{{ \Illuminate\Support\Number::rupiah($feeRate->amount) }}</td>
                        <td class="text-end">
                            <div class="flex justify-end gap-1">
                                <button type="button" class="btn btn-sm btn-ghost btn-square" wire:click="edit({{ $feeRate->id }})" aria-label="Ubah">
                                    <x-icon name="pencil-square" class="size-4" />
                                </button>
                                <button type="button" class="btn btn-sm btn-ghost btn-square text-error" wire:click="delete({{ $feeRate->id }})" wire:confirm="Hapus tarif ini? Tagihan yang sudah terbit tidak berubah." aria-label="Hapus">
                                    <x-icon name="trash" class="size-4" />
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-8 text-center text-base-content/60">Belum ada tarif untuk tahun ajaran ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-modal name="fee-rate-form" :title="$editingId ? 'Ubah tarif' : 'Tambah tarif'">
        <form wire:submit="save" class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-select wire:model="level_id" label="Jenjang" placeholder="Pilih jenjang" required>
                    @foreach ($this->levels as $level)
                        <option value="{{ $level->id }}" wire:key="form-level-{{ $level->id }}">{{ $level->name }}</option>
                    @endforeach
                </x-select>
                <x-select wire:model="payment_category_id" label="Kategori" placeholder="Pilih kategori" required>
                    @foreach ($this->categories->reject->isUniform() as $category)
                        <option value="{{ $category->id }}" wire:key="form-category-{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </x-select>
                <x-input wire:model="grade" label="Tingkat (kosongkan untuk semua)" type="number" min="0" max="12" />
                <x-input wire:model="amount" label="Nominal (Rp)" type="number" min="1000" step="500" required />
            </div>

            <div class="modal-action">
                <button type="button" class="btn" x-on:click="$el.closest('dialog').close()">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </x-modal>
</div>
