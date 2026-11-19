<?php

use App\Actions\Billing\CreateBill;
use App\Actions\Billing\IssueBills;
use App\Enums\BillingCycle;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Level;
use App\Models\PaymentCategory;
use App\Models\Student;
use App\Models\UniformItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Terbitkan Tagihan')] class extends Component {
    #[Url(as: 'mode')]
    public string $mode = 'massal';

    public ?int $categoryId = null;

    public string $target = 'level';

    public ?int $classroomId = null;

    /**
     * @var array<int, string>
     */
    public array $months = [];

    public int $dueDay = 10;

    public string $dueDate = '';

    public ?int $amountOverride = null;

    #[Url(as: 'siswa')]
    public ?int $studentId = null;

    public string $studentSearch = '';

    public string $description = '';

    public ?int $amount = null;

    /**
     * @var array<int, int|string|null>
     */
    public array $uniformQuantities = [];

    public function mount(): void
    {
        $this->months = array_keys($this->monthOptions());
    }

    #[Computed]
    public function activeLevel(): ?Level
    {
        return Level::active();
    }

    #[Computed]
    public function academicYear(): ?AcademicYear
    {
        return AcademicYear::current();
    }

    /**
     * @return Collection<int, PaymentCategory>
     */
    #[Computed]
    public function categories(): Collection
    {
        if ($this->activeLevel === null) {
            return new Collection;
        }

        return PaymentCategory::query()->activeFor($this->activeLevel)->ordered()->get();
    }

    #[Computed]
    public function category(): ?PaymentCategory
    {
        return $this->categories->firstWhere('id', $this->categoryId);
    }

    /**
     * @return Collection<int, Classroom>
     */
    #[Computed]
    public function classrooms(): Collection
    {
        if ($this->activeLevel === null || $this->academicYear === null) {
            return new Collection;
        }

        return Classroom::query()->whereBelongsTo($this->activeLevel)->whereBelongsTo($this->academicYear)->orderBy('grade')->orderBy('name')->get();
    }

    /**
     * The twelve months of the active academic year, keyed by "Y-m".
     *
     * @return array<string, string>
     */
    public function monthOptions(): array
    {
        $academicYear = AcademicYear::current();

        if ($academicYear === null) {
            return [];
        }

        $options = [];
        $month = $academicYear->starts_on->copy()->startOfMonth();

        while ($month->lte($academicYear->ends_on)) {
            $options[$month->format('Y-m')] = $month->translatedFormat('M Y');
            $month = $month->addMonth();
        }

        return $options;
    }

    /**
     * @return Collection<int, Student>
     */
    #[Computed]
    public function studentCandidates(): Collection
    {
        if ($this->activeLevel === null || mb_strlen(trim($this->studentSearch)) < 2) {
            return new Collection;
        }

        return Student::query()
            ->active()
            ->whereBelongsTo($this->activeLevel)
            ->with('currentEnrollment.classroom')
            ->where(fn ($query) => $query->where('name', 'like', '%'.$this->studentSearch.'%')->orWhere('nis', 'like', '%'.$this->studentSearch.'%'))
            ->orderBy('name')
            ->limit(8)
            ->get();
    }

    #[Computed]
    public function student(): ?Student
    {
        return $this->studentId ? Student::query()->with('currentEnrollment.classroom')->find($this->studentId) : null;
    }

    /**
     * @return Collection<int, UniformItem>
     */
    #[Computed]
    public function uniformItems(): Collection
    {
        if ($this->activeLevel === null) {
            return new Collection;
        }

        return UniformItem::query()->whereBelongsTo($this->activeLevel)->where('is_active', true)->orderBy('name')->orderBy('size')->get();
    }

    public function updatedCategoryId(): void
    {
        $this->resetValidation();

        if ($this->mode === 'perorangan' && $this->category && ! $this->category->isUniform()) {
            $this->description = $this->category->name.' ';
        }
    }

    public function selectStudent(int $studentId): void
    {
        $this->studentId = $studentId;
        $this->studentSearch = '';
    }

    public function issue(IssueBills $issueBills): void
    {
        $this->authorize('manage-billing');
        abort_if($this->activeLevel === null || $this->academicYear === null, 422);

        $this->validate([
            'categoryId' => [
                'required', 'integer',
                Rule::in($this->categories->reject->isUniform()->modelKeys()),
            ],
            'target' => ['required', Rule::in(['level', 'classroom'])],
            'classroomId' => [Rule::requiredIf($this->target === 'classroom'), 'nullable', Rule::in($this->classrooms->modelKeys())],
            'months' => [Rule::requiredIf($this->category?->billing_cycle === BillingCycle::Monthly), 'array'],
            'months.*' => [Rule::in(array_keys($this->monthOptions()))],
            'dueDay' => ['required', 'integer', 'min:1', 'max:31'],
            'dueDate' => ['nullable', 'date'],
            'amountOverride' => ['nullable', 'integer', 'min:1000'],
        ], [
            'categoryId.in' => 'Pilih kategori yang aktif untuk jenjang ini.',
            'months.required' => 'Pilih minimal satu bulan.',
        ], [
            'categoryId' => 'kategori',
            'classroomId' => 'kelas',
            'dueDay' => 'tanggal jatuh tempo',
            'dueDate' => 'tanggal jatuh tempo',
            'amountOverride' => 'nominal',
        ]);

        $students = Student::query()
            ->active()
            ->whereBelongsTo($this->activeLevel)
            ->when($this->target === 'classroom', fn ($query) => $query->whereHas(
                'enrollments',
                fn ($query) => $query->where('classroom_id', $this->classroomId),
            ))
            ->get();

        $result = $issueBills->handle(
            category: $this->category,
            level: $this->activeLevel,
            academicYear: $this->academicYear,
            students: $students,
            periods: array_map(fn (string $month) => Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay(), $this->months),
            dueDay: $this->dueDay,
            dueDate: $this->dueDate !== '' ? Carbon::parse($this->dueDate) : null,
            amountOverride: $this->amountOverride,
        );

        $message = $result['created'].' tagihan diterbitkan.';
        $message .= $result['existing'] > 0 ? ' '.$result['existing'].' sudah ada sebelumnya.' : '';
        $message .= $result['without_rate'] > 0 ? ' '.$result['without_rate'].' siswa dilewati karena belum ada tarif.' : '';

        $this->dispatch('toast', variant: $result['without_rate'] > 0 ? 'warning' : 'success', text: $message, duration: 8000);
    }

    public function createSingle(CreateBill $createBill): void
    {
        $this->authorize('manage-billing');
        abort_if($this->activeLevel === null || $this->academicYear === null, 422);

        $isUniform = (bool) $this->category?->isUniform();

        $this->validate([
            'studentId' => ['required', 'integer', Rule::exists(Student::class, 'id')->where('level_id', $this->activeLevel->id)],
            'categoryId' => ['required', 'integer', Rule::in($this->categories->modelKeys())],
            'description' => ['required', 'string', 'max:255'],
            'amount' => [Rule::requiredIf(! $isUniform), 'nullable', 'integer', 'min:1000'],
            'dueDate' => ['nullable', 'date'],
            'uniformQuantities.*' => ['nullable', 'integer', 'min:0', 'max:50'],
        ], [
            'studentId.required' => 'Pilih siswa terlebih dahulu.',
        ], [
            'studentId' => 'siswa',
            'categoryId' => 'kategori',
            'description' => 'keterangan',
            'amount' => 'nominal',
            'dueDate' => 'tanggal jatuh tempo',
        ]);

        $bill = $createBill->handle(
            student: $this->student,
            category: $this->category,
            academicYear: $this->academicYear,
            description: $this->description,
            amount: $isUniform ? null : $this->amount,
            dueDate: $this->dueDate !== '' ? Carbon::parse($this->dueDate) : null,
            uniformQuantities: array_map('intval', array_filter($this->uniformQuantities, fn ($quantity) => (int) $quantity > 0)),
        );

        $this->dispatch('toast', variant: 'success', text: 'Tagihan '.$bill->description.' untuk '.$this->student->name.' dibuat.');
        $this->reset('amount', 'uniformQuantities', 'description');
    }
}; ?>

<div>
    <a href="{{ route('billing.index') }}" class="btn btn-ghost btn-sm mb-4" wire:navigate>
        <x-icon name="arrow-left" class="size-4" /> Kembali ke daftar tagihan
    </a>

    <x-page-header title="Terbitkan Tagihan" :description="'Jenjang '.($this->activeLevel?->name ?? '-').' · Tahun ajaran '.($this->academicYear?->name ?? '-')" />

    @if ($this->activeLevel === null)
        <div role="alert" class="alert alert-info">
            <span>Pilih jenjang tertentu melalui tombol <strong>Jenjang</strong> di kanan atas untuk menerbitkan tagihan.</span>
        </div>
    @elseif ($this->academicYear === null)
        <div role="alert" class="alert alert-warning"><span>Belum ada tahun ajaran aktif. Hubungi admin.</span></div>
    @else
        <div role="tablist" class="tabs tabs-box mb-6 w-fit">
            <button type="button" role="tab" @class(['tab', 'tab-active' => $mode === 'massal']) wire:click="$set('mode', 'massal')">Massal (per jenjang/kelas)</button>
            <button type="button" role="tab" @class(['tab', 'tab-active' => $mode === 'perorangan']) wire:click="$set('mode', 'perorangan')">Perorangan / seragam</button>
        </div>

        @if ($mode === 'massal')
            <form wire:submit="issue" class="card max-w-3xl border border-base-300">
                <div class="card-body gap-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-select wire:model.live="categoryId" label="Kategori" placeholder="Pilih kategori" required>
                            @foreach ($this->categories->reject->isUniform() as $category)
                                <option value="{{ $category->id }}" wire:key="category-{{ $category->id }}">{{ $category->name }} ({{ $category->billing_cycle->label() }})</option>
                            @endforeach
                        </x-select>
                        <x-select wire:model.live="target" label="Ditagihkan kepada">
                            <option value="level">Semua siswa aktif {{ $this->activeLevel->name }}</option>
                            <option value="classroom">Siswa di kelas tertentu</option>
                        </x-select>
                        @if ($target === 'classroom')
                            <x-select wire:model="classroomId" label="Kelas" placeholder="Pilih kelas">
                                @foreach ($this->classrooms as $classroom)
                                    <option value="{{ $classroom->id }}" wire:key="classroom-{{ $classroom->id }}">{{ $classroom->name }}</option>
                                @endforeach
                            </x-select>
                        @endif
                        <x-input wire:model="amountOverride" label="Nominal khusus (kosongkan untuk pakai tarif)" type="number" min="1000" step="500" />
                    </div>

                    @if ($this->category?->billing_cycle === \App\Enums\BillingCycle::Monthly)
                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend pt-0">Bulan yang ditagihkan</legend>
                            <div class="grid grid-cols-3 gap-2 sm:grid-cols-6">
                                @foreach ($this->monthOptions() as $value => $label)
                                    <label class="label cursor-pointer gap-2 text-base-content" wire:key="month-{{ $value }}">
                                        <input type="checkbox" class="checkbox checkbox-sm checkbox-primary" value="{{ $value }}" wire:model="months" />
                                        <span class="text-sm">{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                            @error('months') <p class="label text-error">{{ $message }}</p> @enderror
                        </fieldset>
                        <x-input wire:model="dueDay" label="Jatuh tempo setiap tanggal" type="number" min="1" max="31" class="max-w-32" />
                    @elseif ($this->category)
                        <x-input wire:model="dueDate" label="Tanggal jatuh tempo (opsional)" type="date" class="max-w-xs" />
                    @endif

                    <p class="text-sm text-base-content/70">Tagihan yang sudah pernah diterbitkan untuk siswa dan periode yang sama akan dilewati.</p>

                    <div class="card-actions justify-end">
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:confirm="Terbitkan tagihan sekarang?">
                            Terbitkan tagihan
                        </button>
                    </div>
                </div>
            </form>
        @else
            <form wire:submit="createSingle" class="card max-w-3xl border border-base-300">
                <div class="card-body gap-4">
                    <fieldset class="fieldset p-0">
                        <legend class="fieldset-legend pt-0">Siswa</legend>
                        @if ($this->student)
                            <div class="flex items-center justify-between rounded-box border border-base-300 p-3">
                                <div>
                                    <div class="font-medium">{{ $this->student->name }}</div>
                                    <div class="text-sm text-base-content/60">NIS {{ $this->student->nis ?? '-' }} · Kelas {{ $this->student->currentEnrollment?->classroom->name ?? '-' }}</div>
                                </div>
                                <button type="button" class="btn btn-sm btn-ghost" wire:click="$set('studentId', null)">Ganti</button>
                            </div>
                        @else
                            <label class="input w-full">
                                <x-icon name="magnifying-glass" class="size-4 opacity-60" />
                                <input type="search" wire:model.live.debounce.300ms="studentSearch" placeholder="Cari nama atau NIS siswa" />
                            </label>
                            @if ($this->studentCandidates->isNotEmpty())
                                <ul class="menu mt-1 w-full rounded-box border border-base-300">
                                    @foreach ($this->studentCandidates as $candidate)
                                        <li wire:key="candidate-{{ $candidate->id }}">
                                            <button type="button" wire:click="selectStudent({{ $candidate->id }})">
                                                {{ $candidate->name }} <span class="text-base-content/60">· {{ $candidate->currentEnrollment?->classroom->name ?? '-' }}</span>
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            @error('studentId') <p class="label text-error">{{ $message }}</p> @enderror
                        @endif
                    </fieldset>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-select wire:model.live="categoryId" label="Kategori" placeholder="Pilih kategori" required>
                            @foreach ($this->categories as $category)
                                <option value="{{ $category->id }}" wire:key="single-category-{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </x-select>
                        <x-input wire:model="dueDate" label="Tanggal jatuh tempo (opsional)" type="date" />
                    </div>
                    <x-input wire:model="description" label="Keterangan" placeholder="contoh: Tunggakan SPP 2025/2026" required />

                    @if ($this->category?->isUniform())
                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend pt-0">Item seragam</legend>
                            @error('items') <p class="label text-error">{{ $message }}</p> @enderror
                            <div class="overflow-x-auto rounded-box border border-base-300">
                                <table class="table table-sm">
                                    <thead><tr><th>Item</th><th class="text-end">Harga</th><th class="w-28">Jumlah</th></tr></thead>
                                    <tbody>
                                        @forelse ($this->uniformItems as $item)
                                            <tr wire:key="uniform-{{ $item->id }}">
                                                <td>{{ $item->label() }}</td>
                                                <td class="text-end font-mono">{{ \Illuminate\Support\Number::rupiah($item->price) }}</td>
                                                <td><input type="number" min="0" max="50" class="input input-sm w-20" wire:model="uniformQuantities.{{ $item->id }}" aria-label="Jumlah {{ $item->label() }}" /></td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="3" class="text-base-content/60">Katalog seragam jenjang ini masih kosong. Hubungi admin.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </fieldset>
                    @else
                        <x-input wire:model="amount" label="Nominal (Rp)" type="number" min="1000" step="500" class="max-w-xs" />
                    @endif

                    <div class="card-actions justify-end">
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Buat tagihan</button>
                    </div>
                </div>
            </form>
        @endif
    @endif
</div>
