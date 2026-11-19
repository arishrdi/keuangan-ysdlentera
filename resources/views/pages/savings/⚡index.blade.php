<?php

use App\Actions\Savings\SavingsLedger;
use App\Enums\SavingsTransactionType;
use App\Enums\StudentStatus;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Level;
use App\Models\SavingsTransaction;
use App\Models\Student;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Tabungan')] class extends Component {
    use WithPagination;

    #[Url(as: 'cari')]
    public string $search = '';

    #[Url(as: 'kelas')]
    public ?int $classroomId = null;

    #[Url(as: 'bersaldo')]
    public bool $onlyWithBalance = false;

    public ?int $studentId = null;

    public string $type = 'setoran';

    public ?int $amount = null;

    public string $transactedOn = '';

    public string $notes = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'classroomId', 'onlyWithBalance'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('manage-savings');
    }

    #[Computed]
    public function activeLevel(): ?Level
    {
        return Level::active();
    }

    /**
     * @return array{balance: int, deposits: int, withdrawals: int, savers: int}
     */
    #[Computed]
    public function summary(): array
    {
        $month = SavingsTransaction::query()
            ->whereNull('reversal_of_id')
            ->whereNull('cancelled_at')
            ->when($this->activeLevel, fn ($query, Level $level) => $query->where('level_id', $level->id))
            ->whereBetween('transacted_on', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]);

        $balances = Student::query()
            ->when($this->activeLevel, fn ($query, Level $level) => $query->whereBelongsTo($level))
            ->withSavingsBalance()
            ->get(['students.id'])
            ->pluck('savings_balance');

        return [
            'balance' => (int) $balances->sum(),
            'deposits' => (int) (clone $month)->where('type', '!=', SavingsTransactionType::Withdrawal)->sum('amount'),
            'withdrawals' => (int) (clone $month)->where('type', SavingsTransactionType::Withdrawal)->sum('amount'),
            'savers' => $balances->filter(fn ($balance) => $balance > 0)->count(),
        ];
    }

    /**
     * @return Collection<int, Classroom>
     */
    #[Computed]
    public function classrooms(): Collection
    {
        return Classroom::query()
            ->with('level')
            ->where('academic_year_id', AcademicYear::current()?->id)
            ->when($this->activeLevel, fn ($query, Level $level) => $query->whereBelongsTo($level))
            ->orderBy('grade')
            ->orderBy('name')
            ->get();
    }

    /**
     * Active students, plus former students who still have savings to collect.
     *
     * @return LengthAwarePaginator<int, Student>
     */
    #[Computed]
    public function students(): LengthAwarePaginator
    {
        return Student::query()
            ->with(['level', 'currentEnrollment.classroom'])
            ->withSavingsBalance()
            ->withMax('savingsTransactions as last_transacted_on', 'transacted_on')
            ->when($this->activeLevel, fn ($query, Level $level) => $query->whereBelongsTo($level))
            ->where(fn ($query) => $query->where('status', StudentStatus::Active)->orWhereHas('savingsTransactions'))
            ->when($this->classroomId, fn ($query, int $classroomId) => $query->whereHas('enrollments', fn ($query) => $query->where('classroom_id', $classroomId)))
            ->when($this->search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', '%'.$this->search.'%')
                ->orWhere('nis', 'like', '%'.$this->search.'%')))
            ->when($this->onlyWithBalance, fn ($query) => $query->withPositiveSavings())
            ->orderBy('name')
            ->paginate(30);
    }

    #[Computed]
    public function student(): ?Student
    {
        return $this->studentId ? Student::query()->withSavingsBalance()->find($this->studentId) : null;
    }

    public function open(int $studentId, string $type): void
    {
        $this->authorize('manage-savings');
        $this->resetValidation();

        $this->studentId = $studentId;
        $this->type = SavingsTransactionType::from($type)->value;
        $this->reset('amount', 'notes');
        $this->transactedOn = now()->toDateString();

        $this->dispatch('open-modal', name: 'savings-entry');
    }

    public function save(SavingsLedger $ledger): void
    {
        $this->authorize('manage-savings');

        $this->validate([
            'type' => ['required', Rule::in([SavingsTransactionType::Deposit->value, SavingsTransactionType::Withdrawal->value])],
            'amount' => ['required', 'integer', 'min:1'],
            'transactedOn' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], ['amount' => 'nominal', 'transactedOn' => 'tanggal', 'notes' => 'catatan']);

        $student = $this->student;
        abort_if($student === null, 404);

        $entry = $this->type === SavingsTransactionType::Deposit->value
            ? $ledger->deposit($student, $this->amount, Carbon::parse($this->transactedOn), $this->notes ?: null)
            : $ledger->withdraw($student, $this->amount, Carbon::parse($this->transactedOn), $this->notes ?: null);

        $this->dispatch('close-modal', name: 'savings-entry');
        $this->dispatch('toast', variant: 'success', text: $entry->type->label().' '.$student->name.' dicatat ('.$entry->number.').');
        unset($this->students, $this->summary, $this->student);
    }
}; ?>

<div>
    <x-page-header title="Tabungan Siswa" :description="($this->activeLevel ? 'Jenjang '.$this->activeLevel->name : 'Semua jenjang').' · Dana titipan siswa, terpisah dari kas sekolah.'" />

    <div class="stats stats-vertical mb-6 w-full border border-base-300 md:stats-horizontal">
        <div class="stat">
            <div class="stat-title">Total saldo tabungan</div>
            <div class="stat-value text-2xl text-primary">{{ \Illuminate\Support\Number::rupiah($this->summary['balance']) }}</div>
            <div class="stat-desc">{{ $this->summary['savers'] }} siswa memiliki saldo</div>
        </div>
        <div class="stat">
            <div class="stat-title">Setoran {{ now()->translatedFormat('F') }}</div>
            <div class="stat-value text-2xl text-success">{{ \Illuminate\Support\Number::rupiah($this->summary['deposits']) }}</div>
        </div>
        <div class="stat">
            <div class="stat-title">Penarikan {{ now()->translatedFormat('F') }}</div>
            <div class="stat-value text-2xl text-error">{{ \Illuminate\Support\Number::rupiah($this->summary['withdrawals']) }}</div>
        </div>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <label class="input input-sm w-56">
            <x-icon name="magnifying-glass" class="size-4 opacity-60" />
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Cari nama / NIS" />
        </label>
        <select class="select select-sm w-44" wire:model.live="classroomId" aria-label="Kelas">
            <option value="">Semua kelas</option>
            @foreach ($this->classrooms as $classroom)
                <option value="{{ $classroom->id }}" wire:key="classroom-{{ $classroom->id }}">{{ $classroom->level->code }} · {{ $classroom->name }}</option>
            @endforeach
        </select>
        <label class="label cursor-pointer gap-2 text-base-content">
            <input type="checkbox" class="toggle toggle-sm" wire:model.live="onlyWithBalance" />
            <span class="text-sm">Hanya yang bersaldo</span>
        </label>
    </div>

    <div class="overflow-x-auto rounded-box border border-base-300">
        <table class="table table-sm">
            <thead>
                <tr>
                    <th>Siswa</th>
                    <th>Kelas</th>
                    <th>Transaksi terakhir</th>
                    <th class="text-end">Saldo</th>
                    <th class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->students as $student)
                    <tr wire:key="student-{{ $student->id }}">
                        <td>
                            <a href="{{ route('savings.student', $student) }}" class="link link-hover font-medium" wire:navigate>{{ $student->name }}</a>
                            @unless ($this->activeLevel)<span class="badge badge-outline badge-xs">{{ $student->level->code }}</span>@endunless
                            @if ($student->status !== \App\Enums\StudentStatus::Active)
                                <span class="badge badge-xs {{ $student->status->badgeClass() }}">{{ $student->status->label() }}</span>
                            @endif
                        </td>
                        <td>{{ $student->currentEnrollment?->classroom->name ?? '-' }}</td>
                        <td class="text-sm text-base-content/70">{{ $student->last_transacted_on ? \Illuminate\Support\Carbon::parse($student->last_transacted_on)->translatedFormat('j M Y') : '-' }}</td>
                        <td class="text-end font-mono">{{ \Illuminate\Support\Number::rupiah((int) $student->savings_balance) }}</td>
                        <td class="text-end">
                            @if ($this->canManage)
                                <div class="flex justify-end gap-1">
                                    @if ($student->status === \App\Enums\StudentStatus::Active)
                                        <button type="button" class="btn btn-xs btn-primary" wire:click="open({{ $student->id }}, 'setoran')">Setor</button>
                                    @endif
                                    @if ((int) $student->savings_balance > 0)
                                        <button type="button" class="btn btn-xs btn-outline" wire:click="open({{ $student->id }}, 'penarikan')">Tarik</button>
                                    @endif
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-8 text-center text-base-content/60">Tidak ada siswa.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->students->links() }}</div>

    @if ($this->canManage)
        <x-modal name="savings-entry" :title="($type === 'setoran' ? 'Setoran' : 'Penarikan').' tabungan'.($this->student ? ' — '.$this->student->name : '')">
            <form wire:submit="save" class="space-y-4">
                @if ($this->student)
                    <div class="rounded-box bg-base-200 p-3 text-sm">Saldo saat ini: <strong>{{ \Illuminate\Support\Number::rupiah((int) $this->student->savings_balance) }}</strong></div>
                @endif
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-input wire:model="amount" label="Nominal (Rp)" type="number" min="1" required />
                    <x-input wire:model="transactedOn" label="Tanggal" type="date" required />
                </div>
                <x-input wire:model="notes" label="Catatan (opsional)" />
                <div class="modal-action">
                    <button type="button" class="btn" x-on:click="$el.closest('dialog').close()">Batal</button>
                    <button type="submit" @class(['btn', 'btn-primary' => $type === 'setoran', 'btn-warning' => $type !== 'setoran']) wire:loading.attr="disabled">Simpan {{ $type === 'setoran' ? 'setoran' : 'penarikan' }}</button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
