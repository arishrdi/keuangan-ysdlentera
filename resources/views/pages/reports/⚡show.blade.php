<?php

use App\Actions\Reports\Report;
use App\Actions\Reports\ReportExporter;
use App\Actions\Reports\ReportFilters;
use App\Actions\Reports\ReportRegistry;
use App\Actions\Reports\ReportTable;
use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\Classroom;
use App\Models\Level;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public string $report = '';

    #[Url(as: 'dari')]
    public string $from = '';

    #[Url(as: 'sampai')]
    public string $until = '';

    #[Url(as: 'tahun')]
    public ?int $academicYearId = null;

    #[Url(as: 'kategori')]
    public ?int $categoryId = null;

    #[Url(as: 'kelas')]
    public ?int $classroomId = null;

    #[Url(as: 'akun')]
    public ?int $accountId = null;

    public function mount(string $report): void
    {
        $reportClass = ReportRegistry::find($report);

        abort_if($reportClass === null, 404);
        abort_unless($reportClass::allows(auth()->user()), 403);

        $this->report = $report;
        $this->from = $this->from ?: now()->startOfMonth()->toDateString();
        $this->until = $this->until ?: now()->endOfMonth()->toDateString();
        $this->academicYearId ??= AcademicYear::current()?->id;
    }

    /**
     * @return class-string<Report>
     */
    #[Computed]
    public function reportClass(): string
    {
        return ReportRegistry::find($this->report);
    }

    #[Computed]
    public function filters(): ReportFilters
    {
        return ReportFilters::fromArray([
            'dari' => $this->from,
            'sampai' => $this->until,
            'tahun' => $this->academicYearId,
            'kategori' => $this->categoryId,
            'kelas' => $this->classroomId,
            'akun' => $this->accountId,
        ], Level::active());
    }

    #[Computed]
    public function table(): ReportTable
    {
        return (new ($this->reportClass))->build($this->filters);
    }

    public function supports(string $filter): bool
    {
        return in_array($filter, $this->reportClass::filters(), true);
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function categoryOptions(): array
    {
        return method_exists($this->reportClass, 'categoryOptions') ? $this->reportClass::categoryOptions() : [];
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
            ->where('academic_year_id', $this->academicYearId ?? AcademicYear::current()?->id)
            ->when(Level::active(), fn ($query, Level $level) => $query->whereBelongsTo($level))
            ->orderBy('grade')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, CashAccount>
     */
    #[Computed]
    public function accounts(): Collection
    {
        return CashAccount::query()
            ->with('level')
            ->when(Level::active(), fn ($query, Level $level) => $query->whereBelongsTo($level))
            ->orderBy('level_id')
            ->orderBy('name')
            ->get();
    }

    public function exportUrl(string $format): string
    {
        return route('reports.export', ['report' => $this->report, 'format' => $format, ...$this->filters->toQuery()]);
    }

    public function render(): mixed
    {
        return $this->view()->title($this->reportClass::title());
    }
}; ?>

<div>
    <a href="{{ route('reports.index') }}" class="btn btn-ghost btn-sm mb-4" wire:navigate>
        <x-icon name="arrow-left" class="size-4" /> Semua laporan
    </a>

    <x-page-header :title="$this->table->title" :description="$this->table->subtitle">
        <x-slot:actions>
            <a href="{{ $this->exportUrl('excel') }}" class="btn btn-outline btn-primary">
                <x-icon name="arrow-down-tray" class="size-4" /> Excel
            </a>
            <a href="{{ $this->exportUrl('pdf') }}" target="_blank" class="btn btn-primary">
                <x-icon name="document-text" class="size-4" /> PDF / Cetak
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 flex flex-wrap items-end gap-3 rounded-box border border-base-300 bg-base-100 p-3">
        @if ($this->supports('period'))
            <label class="fieldset p-0">
                <span class="fieldset-legend pt-0 text-xs">Dari</span>
                <input type="date" class="input input-sm w-40" wire:model.live="from" />
            </label>
            <label class="fieldset p-0">
                <span class="fieldset-legend pt-0 text-xs">Sampai</span>
                <input type="date" class="input input-sm w-40" wire:model.live="until" />
            </label>
        @elseif ($this->supports('until'))
            <label class="fieldset p-0">
                <span class="fieldset-legend pt-0 text-xs">Jatuh tempo s.d.</span>
                <input type="date" class="input input-sm w-40" wire:model.live="until" />
            </label>
        @endif
        @if ($this->supports('academic_year'))
            <label class="fieldset p-0">
                <span class="fieldset-legend pt-0 text-xs">Tahun ajaran</span>
                <select class="select select-sm w-36" wire:model.live="academicYearId">
                    @foreach ($this->academicYears as $academicYear)
                        <option value="{{ $academicYear->id }}" wire:key="year-{{ $academicYear->id }}">{{ $academicYear->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if ($this->supports('category'))
            <label class="fieldset p-0">
                <span class="fieldset-legend pt-0 text-xs">Kategori</span>
                <select class="select select-sm w-48" wire:model.live="categoryId">
                    <option value="">Semua kategori</option>
                    @foreach ($this->categoryOptions as $value => $label)
                        <option value="{{ $value }}" wire:key="category-{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if ($this->supports('classroom'))
            <label class="fieldset p-0">
                <span class="fieldset-legend pt-0 text-xs">Kelas</span>
                <select class="select select-sm w-40" wire:model.live="classroomId">
                    <option value="">Semua kelas</option>
                    @foreach ($this->classrooms as $classroom)
                        <option value="{{ $classroom->id }}" wire:key="classroom-{{ $classroom->id }}">{{ $classroom->name }} ({{ $classroom->level->code }})</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if ($this->supports('account'))
            <label class="fieldset p-0">
                <span class="fieldset-legend pt-0 text-xs">Akun kas</span>
                <select class="select select-sm w-48" wire:model.live="accountId">
                    <option value="">Semua akun</option>
                    @foreach ($this->accounts as $account)
                        <option value="{{ $account->id }}" wire:key="account-{{ $account->id }}">{{ $account->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        <div wire:loading class="loading loading-spinner loading-sm text-primary"></div>
    </div>

    @if ($this->table->summary !== [])
        <div class="mb-4 flex flex-wrap gap-2">
            @foreach ($this->table->summary as $item)
                <div class="rounded-box border border-base-300 px-3 py-2" wire:key="summary-{{ $loop->index }}">
                    <div class="text-xs text-base-content/60">{{ $item['label'] }}</div>
                    <div class="font-mono font-semibold">{{ $item['value'] }}</div>
                </div>
            @endforeach
        </div>
    @endif

    <div class="overflow-x-auto rounded-box border border-base-300">
        <table class="table table-sm">
            <thead>
                <tr>
                    @foreach ($this->table->columns as $column)
                        <th @class(['text-end' => in_array($column['type'], ['money', 'number', 'percent'], true)]) wire:key="head-{{ $column['key'] }}">{{ $column['label'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($this->table->rows as $index => $row)
                    <tr wire:key="row-{{ $index }}">
                        @foreach ($this->table->columns as $column)
                            <td @class(['text-end font-mono' => in_array($column['type'], ['money', 'number', 'percent'], true), 'whitespace-nowrap' => $column['type'] === 'date'])>
                                {{ ReportExporter::display($row[$column['key']] ?? null, $column['type']) }}
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ count($this->table->columns) }}" class="py-8 text-center text-base-content/60">Tidak ada data untuk filter ini.</td></tr>
                @endforelse
            </tbody>
            @if ($this->table->totals)
                <tfoot>
                    <tr class="bg-base-200 font-semibold text-base-content">
                        @foreach ($this->table->columns as $column)
                            <td @class(['text-end font-mono' => in_array($column['type'], ['money', 'number', 'percent'], true)])>{{ ReportExporter::display($this->table->totals[$column['key']] ?? null, $column['type']) }}</td>
                        @endforeach
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>
