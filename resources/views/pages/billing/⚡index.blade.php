<?php

use App\Enums\BillStatus;
use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\Classroom;
use App\Models\Level;
use App\Models\PaymentCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Tagihan')] class extends Component {
    use WithPagination;

    #[Url(as: 'cari')]
    public string $search = '';

    #[Url(as: 'kategori')]
    public ?int $categoryId = null;

    #[Url]
    public string $status = '';

    #[Url(as: 'kelas')]
    public ?int $classroomId = null;

    #[Url(as: 'tahun')]
    public ?int $academicYearId = null;

    public function mount(): void
    {
        $this->academicYearId ??= AcademicYear::current()?->id;
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('manage-billing');
    }

    #[Computed]
    public function activeLevel(): ?Level
    {
        return Level::active();
    }

    /**
     * @return Collection<int, PaymentCategory>
     */
    #[Computed]
    public function categories(): Collection
    {
        return PaymentCategory::query()->ordered()->get();
    }

    /**
     * @return Collection<int, AcademicYear>
     */
    #[Computed]
    public function academicYears(): Collection
    {
        return AcademicYear::query()->orderByDesc('starts_on')->get();
    }

    /**
     * @return Collection<int, Classroom>
     */
    #[Computed]
    public function classrooms(): Collection
    {
        return Classroom::query()
            ->with('level')
            ->where('academic_year_id', $this->academicYearId)
            ->when($this->activeLevel, fn ($query, Level $level) => $query->whereBelongsTo($level))
            ->orderBy('grade')
            ->orderBy('name')
            ->get();
    }

    /**
     * Bills of the active level and academic year, excluding cancelled ones unless asked for.
     *
     * @return Builder<Bill>
     */
    private function scopedBills(): Builder
    {
        return Bill::query()
            ->where('academic_year_id', $this->academicYearId)
            ->when($this->activeLevel, fn ($query, Level $level) => $query->whereBelongsTo($level));
    }

    /**
     * Totals per category for the monitoring cards.
     *
     * @return SupportCollection<int, object{payment_category_id: int, total: int, received: int, students: int, outstanding_students: int}>
     */
    #[Computed]
    public function summary(): SupportCollection
    {
        return $this->scopedBills()
            ->where('status', '!=', BillStatus::Cancelled)
            ->groupBy('payment_category_id')
            ->select('payment_category_id')
            ->selectRaw('sum(amount - discount_amount) as total')
            ->selectRaw('sum(paid_amount) as received')
            ->selectRaw('count(distinct student_id) as students')
            ->selectRaw('count(distinct case when status in (?, ?) then student_id end) as outstanding_students', [BillStatus::Unpaid->value, BillStatus::Partial->value])
            ->toBase()
            ->get()
            ->keyBy('payment_category_id');
    }

    /**
     * @return LengthAwarePaginator<int, Bill>
     */
    #[Computed]
    public function bills(): LengthAwarePaginator
    {
        return $this->scopedBills()
            ->with(['student.currentEnrollment.classroom', 'paymentCategory', 'level'])
            ->withPendingAmount()
            ->when($this->categoryId, fn ($query, int $categoryId) => $query->where('payment_category_id', $categoryId))
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->when($this->classroomId, fn ($query, int $classroomId) => $query->whereHas(
                'student.enrollments',
                fn ($query) => $query->where('classroom_id', $classroomId),
            ))
            ->when($this->search !== '', fn ($query) => $query->whereHas('student', fn ($query) => $query
                ->where('name', 'like', '%'.$this->search.'%')
                ->orWhere('nis', 'like', '%'.$this->search.'%')))
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->orderBy('id')
            ->paginate(30);
    }
}; ?>

<div>
    <x-page-header
        title="Tagihan"
        :description="($this->activeLevel ? 'Jenjang '.$this->activeLevel->name : 'Semua jenjang').' · Pantau tagihan dan pembayaran lima kategori.'"
    >
        <x-slot:actions>
            <select class="select select-sm w-40" wire:model.live="academicYearId" aria-label="Tahun ajaran">
                @foreach ($this->academicYears as $academicYear)
                    <option value="{{ $academicYear->id }}" wire:key="year-{{ $academicYear->id }}">{{ $academicYear->name }}</option>
                @endforeach
            </select>
            @if ($this->canManage)
                <a href="{{ route('billing.issue') }}" class="btn btn-primary" wire:navigate>
                    <x-icon name="plus" class="size-4" /> Terbitkan tagihan
                </a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="mb-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        @foreach ($this->categories as $category)
            @php
                $row = $this->summary->get($category->id);
                $total = (int) ($row->total ?? 0);
                $received = (int) ($row->received ?? 0);
            @endphp
            <button
                type="button"
                wire:key="summary-{{ $category->id }}"
                wire:click="$set('categoryId', {{ $categoryId === $category->id ? 'null' : $category->id }})"
                @class(['card border text-start transition hover:border-primary', 'border-primary ring-1 ring-primary' => $categoryId === $category->id, 'border-base-300' => $categoryId !== $category->id])
            >
                <div class="card-body gap-1 p-4">
                    <div class="flex items-center justify-between">
                        <h3 class="font-semibold">{{ $category->name }}</h3>
                        <span class="text-xs text-base-content/60">{{ $row->students ?? 0 }} siswa</span>
                    </div>
                    <div class="text-xs text-base-content/60">Tagihan</div>
                    <div class="font-mono text-sm">{{ \Illuminate\Support\Number::rupiah($total) }}</div>
                    <div class="text-xs text-base-content/60">Diterima</div>
                    <div class="font-mono text-sm text-success">{{ \Illuminate\Support\Number::rupiah($received) }}</div>
                    <div class="text-xs text-base-content/60">Tunggakan · {{ $row->outstanding_students ?? 0 }} siswa</div>
                    <div class="font-mono text-sm text-error">{{ \Illuminate\Support\Number::rupiah($total - $received) }}</div>
                    <progress class="progress progress-primary mt-1" value="{{ $received }}" max="{{ max($total, 1) }}"></progress>
                </div>
            </button>
        @endforeach
    </div>

    <div class="mb-4 flex flex-wrap gap-2">
        <label class="input input-sm w-56">
            <x-icon name="magnifying-glass" class="size-4 opacity-60" />
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Cari siswa / NIS" />
        </label>
        <select class="select select-sm w-40" wire:model.live="categoryId" aria-label="Kategori">
            <option value="">Semua kategori</option>
            @foreach ($this->categories as $category)
                <option value="{{ $category->id }}" wire:key="filter-category-{{ $category->id }}">{{ $category->name }}</option>
            @endforeach
        </select>
        <select class="select select-sm w-40" wire:model.live="status" aria-label="Status">
            <option value="">Semua status</option>
            @foreach (\App\Enums\BillStatus::cases() as $statusOption)
                <option value="{{ $statusOption->value }}" wire:key="filter-status-{{ $statusOption->value }}">{{ $statusOption->label() }}</option>
            @endforeach
        </select>
        <select class="select select-sm w-44" wire:model.live="classroomId" aria-label="Kelas">
            <option value="">Semua kelas</option>
            @foreach ($this->classrooms as $classroom)
                <option value="{{ $classroom->id }}" wire:key="filter-classroom-{{ $classroom->id }}">{{ $classroom->level->code }} · {{ $classroom->name }}</option>
            @endforeach
        </select>
    </div>

    <div class="overflow-x-auto rounded-box border border-base-300">
        <table class="table table-sm">
            <thead>
                <tr>
                    <th>Siswa</th>
                    <th>Kelas</th>
                    <th>Tagihan</th>
                    <th class="text-end">Nominal</th>
                    <th class="text-end">Dibayar</th>
                    <th class="text-end">Sisa</th>
                    <th>Jatuh tempo</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->bills as $bill)
                    <tr wire:key="bill-{{ $bill->id }}">
                        <td>
                            <a href="{{ route('billing.student', $bill->student) }}" class="link link-hover font-medium" wire:navigate>{{ $bill->student->name }}</a>
                            @unless ($this->activeLevel)
                                <span class="badge badge-outline badge-xs">{{ $bill->level->code }}</span>
                            @endunless
                        </td>
                        <td>{{ $bill->student->currentEnrollment?->classroom->name ?? '-' }}</td>
                        <td>{{ $bill->description }}</td>
                        <td class="text-end font-mono">
                            {{ \Illuminate\Support\Number::rupiah($bill->netAmount()) }}
                            @if ($bill->discount_amount > 0)
                                <div class="text-xs text-base-content/60 line-through">{{ \Illuminate\Support\Number::rupiah($bill->amount) }}</div>
                            @endif
                        </td>
                        <td class="text-end font-mono">{{ \Illuminate\Support\Number::rupiah($bill->paid_amount) }}</td>
                        <td class="text-end font-mono">{{ \Illuminate\Support\Number::rupiah($bill->remainingAmount()) }}</td>
                        <td @class(['text-error font-medium' => $bill->isOverdue()])>{{ $bill->due_date?->translatedFormat('j M Y') ?? '-' }}</td>
                        <td>
                            <span class="badge badge-sm {{ $bill->status->badgeClass() }}">{{ $bill->status->label() }}</span>
                            @if ($bill->pending_amount > 0)
                                <span class="badge badge-sm badge-warning badge-outline">Menunggu verifikasi</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="py-8 text-center text-base-content/60">Tidak ada tagihan.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->bills->links() }}</div>
</div>
