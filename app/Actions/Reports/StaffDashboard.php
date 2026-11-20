<?php

namespace App\Actions\Reports;

use App\Enums\BillStatus;
use App\Enums\BudgetStatus;
use App\Enums\CashTransactionType;
use App\Enums\PaymentStatus;
use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\BudgetProposal;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Classroom;
use App\Models\Level;
use App\Models\Payment;
use App\Models\Student;
use App\Models\TransactionCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Figures for the treasurer, principal and foundation dashboard, scoped to the active
 * level (or every level) and the active academic year.
 */
class StaffDashboard
{
    private const INCOME_TYPES = [CashTransactionType::StudentPayment, CashTransactionType::Income];

    private readonly CarbonImmutable $month;

    /**
     * @param  CarbonImmutable|null  $month  the month the monthly figures describe; defaults to the current month.
     */
    public function __construct(
        private readonly User $user,
        private readonly ?Level $level,
        private readonly ?AcademicYear $academicYear,
        ?CarbonImmutable $month = null,
    ) {
        $this->month = ($month ?? CarbonImmutable::now())->startOfMonth();
    }

    public function month(): CarbonImmutable
    {
        return $this->month;
    }

    /**
     * Months that can be chosen: from the start of the academic year (or six months back)
     * up to the current month, at most twelve, newest first.
     *
     * @return list<CarbonImmutable>
     */
    public function availableMonths(): array
    {
        [$start, $end] = $this->monthRange();
        $months = [];

        for ($month = $end; $month->greaterThanOrEqualTo($start); $month = $month->subMonth()) {
            $months[] = $month;
        }

        return $months;
    }

    /**
     * Resolve a `YYYY-MM` value from the URL to one of the available months, or null.
     */
    public static function parseMonth(?string $value): ?CarbonImmutable
    {
        if ($value === null || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) !== 1) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m', $value);
    }

    /**
     * A compact rupiah figure for tiles and chart labels: Rp 227,7 jt, Rp 1,2 M, Rp 850 rb.
     */
    public static function shortRupiah(int $amount): string
    {
        $sign = $amount < 0 ? '-' : '';
        $absolute = abs($amount);

        [$value, $unit] = match (true) {
            $absolute >= 1_000_000_000 => [$absolute / 1_000_000_000, ' M'],
            $absolute >= 1_000_000 => [$absolute / 1_000_000, ' jt'],
            $absolute >= 1_000 => [$absolute / 1_000, ' rb'],
            default => [$absolute, ''],
        };

        $formatted = number_format($value, $value >= 100 || floor($value) == $value ? 0 : 1, ',', '.');

        return $sign.'Rp '.$formatted.$unit;
    }

    public function studentCount(): int
    {
        return Student::query()->active()->when($this->level, fn (Builder $query) => $query->whereBelongsTo($this->level))->count();
    }

    public function classroomCount(): int
    {
        return Classroom::query()
            ->when($this->academicYear, fn (Builder $query) => $query->whereBelongsTo($this->academicYear))
            ->when($this->level, fn (Builder $query) => $query->whereBelongsTo($this->level))
            ->count();
    }

    public function cashBalance(): int
    {
        return (int) CashAccount::query()
            ->when($this->level, fn (Builder $query) => $query->availableTo($this->level))
            ->withBalance($this->level)
            ->get()
            ->sum('balance');
    }

    public function savingsBalance(): int
    {
        return (int) Student::query()
            ->when($this->level, fn (Builder $query) => $query->whereBelongsTo($this->level))
            ->withSavingsBalance()
            ->get(['students.id'])
            ->sum('savings_balance');
    }

    public function monthIncome(): int
    {
        return $this->monthTotal(self::INCOME_TYPES);
    }

    public function monthExpense(): int
    {
        return $this->monthTotal([CashTransactionType::Expense]);
    }

    /**
     * @return array{billed: int, received: int, outstanding: int, outstanding_students: int, percent: float}
     */
    public function bills(): array
    {
        $query = $this->billQuery();
        $billed = (int) (clone $query)->sum(DB::raw('amount - discount_amount'));
        $received = (int) (clone $query)->sum('paid_amount');

        return [
            'billed' => $billed,
            'received' => $received,
            'outstanding' => $billed - $received,
            'outstanding_students' => (clone $query)
                ->whereRaw('paid_amount < amount - discount_amount')
                ->distinct()
                ->count('student_id'),
            'percent' => $billed > 0 ? floor($received / $billed * 1000) / 10 : 0.0,
        ];
    }

    public function pendingPayments(): int
    {
        return Payment::query()
            ->where('status', PaymentStatus::Pending)
            ->when($this->level, fn (Builder $query) => $query->whereBelongsTo($this->level))
            ->count();
    }

    /**
     * The status this user decides on, if any (Kepsek at stage 1, Yayasan at stage 2).
     */
    public function awaitingStatus(): ?BudgetStatus
    {
        return collect(BudgetStatus::cases())->first(fn (BudgetStatus $status) => $status->awaitingRole() === $this->user->role);
    }

    /**
     * Proposals waiting for this user's decision, or for any decision when the user decides nothing.
     *
     * @return Collection<int, BudgetProposal>
     */
    public function approvalQueue(): Collection
    {
        $statuses = $this->awaitingStatus() !== null
            ? [$this->awaitingStatus()]
            : [BudgetStatus::AwaitingPrincipal, BudgetStatus::AwaitingFoundation];

        return BudgetProposal::query()
            ->with(['category', 'creator', 'level'])
            ->whereIn('status', $statuses)
            ->when($this->level, fn (Builder $query) => $query->whereBelongsTo($this->level))
            ->orderBy('submitted_at')
            ->get();
    }

    /**
     * @return array{proposed: int, approved: int, realized: int, percent: int}
     */
    public function budgets(): array
    {
        $budgets = BudgetProposal::query()
            ->where('status', '!=', BudgetStatus::Draft)
            ->when($this->level, fn (Builder $query) => $query->whereBelongsTo($this->level))
            ->withRealizedAmount()
            ->get();
        $approved = $budgets->filter(fn (BudgetProposal $budget) => $budget->status->acceptsExpenses() || $budget->status === BudgetStatus::Completed);
        $approvedTotal = (int) $approved->sum('total_amount');
        $realized = (int) $approved->sum('realized_amount');

        return [
            'proposed' => (int) $budgets->sum('total_amount'),
            'approved' => $approvedTotal,
            'realized' => $realized,
            'percent' => $approvedTotal > 0 ? (int) floor($realized / $approvedTotal * 100) : 0,
        ];
    }

    /**
     * Income and expense per month, from the start of the academic year (or the last six
     * months) up to the current month.
     *
     * @return list<array{month: CarbonImmutable, income: int, expense: int}>
     */
    public function monthlyFlow(): array
    {
        [$start, $end] = $this->monthRange();

        $entries = $this->cashQuery()
            ->whereIn('type', [...self::INCOME_TYPES, CashTransactionType::Expense])
            ->whereBetween('transacted_on', [$start->toDateString(), $end->endOfMonth()->toDateString()])
            ->get(['type', 'amount', 'transacted_on']);

        $months = [];

        for ($month = $start; $month->lessThanOrEqualTo($end); $month = $month->addMonth()) {
            $inMonth = $entries->filter(fn (CashTransaction $entry) => $entry->transacted_on->isSameMonth($month));

            $months[] = [
                'month' => $month,
                'income' => (int) $inMonth->filter(fn (CashTransaction $entry) => in_array($entry->type, self::INCOME_TYPES, true))->sum('amount'),
                'expense' => (int) $inMonth->where('type', CashTransactionType::Expense)->sum('amount'),
            ];
        }

        return $months;
    }

    /**
     * The chosen month's expenses by transaction category, largest first.
     *
     * @return list<array{name: string, amount: int, percent: float}>
     */
    public function expenseByCategory(): array
    {
        $rows = $this->monthQuery([CashTransactionType::Expense])
            ->selectRaw('transaction_category_id, sum(amount) as total')
            ->groupBy('transaction_category_id')
            ->orderByDesc('total')
            ->get();
        $total = (int) $rows->sum('total');
        $names = TransactionCategory::query()->whereIn('id', $rows->pluck('transaction_category_id')->filter())->pluck('name', 'id');

        return $rows->map(fn ($row) => [
            'name' => $names[$row->transaction_category_id] ?? 'Tanpa kategori',
            'amount' => (int) $row->total,
            'percent' => $total > 0 ? round($row->total / $total * 100, 1) : 0.0,
        ])->values()->all();
    }

    /**
     * Billing progress per classroom for the active academic year.
     *
     * @return Collection<int, object{classroom: string, level_code: string, students: int, billed: int, paid: int}>
     */
    public function billsByClassroom(): Collection
    {
        if ($this->academicYear === null) {
            return collect();
        }

        return $this->billQuery()
            ->join('enrollments', function ($join): void {
                $join->on('enrollments.student_id', '=', 'bills.student_id')
                    ->on('enrollments.academic_year_id', '=', 'bills.academic_year_id');
            })
            ->join('classrooms', 'classrooms.id', '=', 'enrollments.classroom_id')
            ->join('levels', 'levels.id', '=', 'classrooms.level_id')
            ->groupBy('classrooms.id', 'classrooms.name', 'levels.code', 'levels.sort_order')
            ->orderBy('levels.sort_order')
            ->orderBy('classrooms.name')
            ->get([
                'classrooms.name as classroom',
                'levels.code as level_code',
                DB::raw('count(distinct bills.student_id) as students'),
                DB::raw('sum(bills.amount - bills.discount_amount) as billed'),
                DB::raw('sum(bills.paid_amount) as paid'),
            ])
            ->toBase()
            ->map(fn ($row) => (object) [
                'classroom' => $row->classroom,
                'level_code' => $row->level_code,
                'students' => (int) $row->students,
                'billed' => (int) $row->billed,
                'paid' => (int) $row->paid,
            ]);
    }

    /**
     * @return Builder<Bill>
     */
    private function billQuery(): Builder
    {
        return Bill::query()
            ->where('bills.status', '!=', BillStatus::Cancelled)
            ->when($this->academicYear, fn (Builder $query) => $query->where('bills.academic_year_id', $this->academicYear->id))
            ->when($this->level, fn (Builder $query) => $query->where('bills.level_id', $this->level->id));
    }

    /**
     * @return Builder<CashTransaction>
     */
    private function cashQuery(): Builder
    {
        return CashTransaction::query()
            ->effective()
            ->when($this->level, fn (Builder $query) => $query->whereBelongsTo($this->level));
    }

    /**
     * @param  list<CashTransactionType>  $types
     * @return Builder<CashTransaction>
     */
    private function monthQuery(array $types): Builder
    {
        return $this->cashQuery()
            ->whereIn('type', $types)
            ->whereBetween('transacted_on', [$this->month->toDateString(), $this->month->endOfMonth()->toDateString()]);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function monthRange(): array
    {
        $end = CarbonImmutable::now()->startOfMonth();
        $start = $this->academicYear?->starts_on
            ? CarbonImmutable::parse($this->academicYear->starts_on)->startOfMonth()
            : $end->subMonths(5);

        if ($start->greaterThan($end)) {
            $start = $end;
        }

        return [$start->max($end->subMonths(11)), $end];
    }

    /**
     * @param  list<CashTransactionType>  $types
     */
    private function monthTotal(array $types): int
    {
        return (int) $this->monthQuery($types)->sum('amount');
    }
}
