<?php

namespace App\Actions\Reports;

use App\Enums\BillStatus;
use App\Enums\BudgetStatus;
use App\Enums\CashDirection;
use App\Enums\CashTransactionType;
use App\Enums\Role;
use App\Enums\StudentStatus;
use App\Models\Bill;
use App\Models\BudgetProposal;
use App\Models\CashTransaction;
use App\Models\Level;
use App\Models\SavingsTransaction;
use App\Models\Student;

/**
 * One row per level plus a combined total: the foundation's overview of every school.
 */
class ConsolidatedReport extends Report
{
    public static function key(): string
    {
        return 'konsolidasi';
    }

    public static function title(): string
    {
        return 'Laporan Konsolidasi Yayasan';
    }

    public static function description(): string
    {
        return 'Perbandingan PAUD, SD, dan SMP serta gabungan seluruh jenjang.';
    }

    public static function filters(): array
    {
        return ['period', 'academic_year'];
    }

    public static function roles(): array
    {
        return [Role::Foundation];
    }

    public function build(ReportFilters $filters): ReportTable
    {
        $rows = Level::query()->ordered()->get()->map(fn (Level $level) => $this->rowFor($level, $filters))->all();
        $total = fn (string $key): int => (int) array_sum(array_column($rows, $key));

        return new ReportTable(
            title: static::title(),
            subtitle: 'Semua jenjang, '.$filters->periodLabel().', tagihan tahun ajaran '.($filters->academicYear->name ?? '-'),
            columns: [
                self::column('level', 'Jenjang'),
                self::column('students', 'Siswa aktif', 'number'),
                self::column('billed', 'Tagihan', 'money'),
                self::column('received', 'Diterima', 'money'),
                self::column('outstanding', 'Tunggakan', 'money'),
                self::column('income', 'Pemasukan periode', 'money'),
                self::column('expense', 'Pengeluaran periode', 'money'),
                self::column('cash', 'Saldo kas', 'money'),
                self::column('savings', 'Tabungan siswa', 'money'),
                self::column('budget_approved', 'Anggaran disetujui', 'money'),
                self::column('budget_realized', 'Realisasi anggaran', 'money'),
            ],
            rows: $rows,
            totals: [
                'level' => 'Gabungan',
                'students' => $total('students'),
                'billed' => $total('billed'),
                'received' => $total('received'),
                'outstanding' => $total('outstanding'),
                'income' => $total('income'),
                'expense' => $total('expense'),
                'cash' => $total('cash'),
                'savings' => $total('savings'),
                'budget_approved' => $total('budget_approved'),
                'budget_realized' => $total('budget_realized'),
            ],
        );
    }

    /**
     * @return array<string, int|string>
     */
    public function rowFor(Level $level, ReportFilters $filters): array
    {
        $bills = Bill::query()
            ->whereBelongsTo($level)
            ->where('academic_year_id', $filters->academicYear?->id)
            ->where('status', '!=', BillStatus::Cancelled)
            ->selectRaw('coalesce(sum(amount - discount_amount), 0) as billed, coalesce(sum(paid_amount), 0) as received')
            ->toBase()
            ->first();

        $period = CashTransaction::query()
            ->effective()
            ->whereBelongsTo($level)
            ->whereBetween('transacted_on', [$filters->from->toDateString(), $filters->until->toDateString()]);

        $budgets = BudgetProposal::query()
            ->whereBelongsTo($level)
            ->whereIn('status', [BudgetStatus::Approved, BudgetStatus::Disbursing, BudgetStatus::Completed])
            ->withRealizedAmount()
            ->get();

        $signedSum = 'coalesce(sum(case when direction = ? then amount else -amount end), 0) as balance';

        return [
            'level' => $level->name,
            'students' => Student::query()->whereBelongsTo($level)->where('status', StudentStatus::Active)->count(),
            'billed' => (int) $bills->billed,
            'received' => (int) $bills->received,
            'outstanding' => (int) $bills->billed - (int) $bills->received,
            'income' => (int) (clone $period)->whereIn('type', [CashTransactionType::StudentPayment, CashTransactionType::Income])->sum('amount'),
            'expense' => (int) (clone $period)->where('type', CashTransactionType::Expense)->sum('amount'),
            'cash' => (int) CashTransaction::query()->whereBelongsTo($level)->whereDate('transacted_on', '<=', $filters->until->toDateString())->selectRaw($signedSum, [CashDirection::In->value])->value('balance'),
            'savings' => (int) SavingsTransaction::query()->whereIn('student_id', Student::query()->whereBelongsTo($level)->select('id'))->whereDate('transacted_on', '<=', $filters->until->toDateString())->selectRaw($signedSum, [CashDirection::In->value])->value('balance'),
            'budget_approved' => (int) $budgets->sum('total_amount'),
            'budget_realized' => (int) $budgets->sum('realized_amount'),
        ];
    }
}
