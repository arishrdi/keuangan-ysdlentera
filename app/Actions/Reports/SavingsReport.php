<?php

namespace App\Actions\Reports;

use App\Enums\CashDirection;
use App\Enums\StudentStatus;
use App\Models\Level;
use App\Models\SavingsTransaction;
use App\Models\Student;

/**
 * Savings per student: balance before the period, deposits, withdrawals and closing balance.
 */
class SavingsReport extends Report
{
    public static function key(): string
    {
        return 'tabungan';
    }

    public static function title(): string
    {
        return 'Laporan Tabungan Siswa';
    }

    public static function description(): string
    {
        return 'Saldo awal, setoran, penarikan, dan saldo akhir tabungan per siswa.';
    }

    public static function filters(): array
    {
        return ['period', 'classroom'];
    }

    public function build(ReportFilters $filters): ReportTable
    {
        $students = Student::query()
            ->with(['level', 'currentEnrollment.classroom'])
            ->when($filters->level, fn ($query, Level $level) => $query->whereBelongsTo($level))
            ->where(fn ($query) => $query->where('status', StudentStatus::Active)->orWhereHas('savingsTransactions'))
            ->when($filters->classroomId, fn ($query, int $classroomId) => $query->whereHas('enrollments', fn ($query) => $query->where('classroom_id', $classroomId)))
            ->get()
            ->keyBy('id');

        $movements = SavingsTransaction::query()
            ->whereIn('student_id', $students->keys())
            ->whereDate('transacted_on', '<=', $filters->until->toDateString())
            ->groupBy('student_id')
            ->select('student_id')
            ->selectRaw('sum(case when transacted_on < ? then (case when direction = ? then amount else -amount end) else 0 end) as opening', [$filters->from->toDateString(), CashDirection::In->value])
            ->selectRaw('sum(case when transacted_on >= ? and direction = ? then amount else 0 end) as deposits', [$filters->from->toDateString(), CashDirection::In->value])
            ->selectRaw('sum(case when transacted_on >= ? and direction = ? then amount else 0 end) as withdrawals', [$filters->from->toDateString(), CashDirection::Out->value])
            ->toBase()
            ->get()
            ->keyBy('student_id');

        $rows = $students
            ->map(function (Student $student) use ($movements) {
                $movement = $movements->get($student->id);
                $opening = (int) ($movement->opening ?? 0);
                $deposits = (int) ($movement->deposits ?? 0);
                $withdrawals = (int) ($movement->withdrawals ?? 0);

                return [
                    'level' => $student->level->code,
                    'classroom' => $student->currentEnrollment?->classroom->name,
                    'nis' => $student->nis,
                    'student' => $student->name,
                    'opening' => $opening,
                    'deposits' => $deposits,
                    'withdrawals' => $withdrawals,
                    'closing' => $opening + $deposits - $withdrawals,
                ];
            })
            ->filter(fn (array $row) => $row['opening'] !== 0 || $row['deposits'] !== 0 || $row['withdrawals'] !== 0)
            ->sortBy([['level', 'asc'], ['classroom', 'asc'], ['student', 'asc']])
            ->values()
            ->all();

        $total = fn (string $key): int => (int) array_sum(array_column($rows, $key));

        return new ReportTable(
            title: static::title(),
            subtitle: $filters->levelLabel().' · '.$filters->periodLabel(),
            columns: [
                self::column('level', 'Jenjang'),
                self::column('classroom', 'Kelas'),
                self::column('nis', 'NIS'),
                self::column('student', 'Siswa'),
                self::column('opening', 'Saldo awal', 'money'),
                self::column('deposits', 'Setoran', 'money'),
                self::column('withdrawals', 'Penarikan', 'money'),
                self::column('closing', 'Saldo akhir', 'money'),
            ],
            rows: $rows,
            totals: [
                'student' => count($rows).' penabung',
                'opening' => $total('opening'),
                'deposits' => $total('deposits'),
                'withdrawals' => $total('withdrawals'),
                'closing' => $total('closing'),
            ],
        );
    }
}
