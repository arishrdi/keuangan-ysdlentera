<?php

namespace App\Actions\Reports;

use App\Models\Bill;
use App\Models\Level;
use App\Models\PaymentCategory;
use App\Models\Student;

/**
 * Students with unpaid bills, from every academic year, as of the end of the period.
 */
class ArrearsReport extends Report
{
    public static function key(): string
    {
        return 'tunggakan';
    }

    public static function title(): string
    {
        return 'Laporan Tunggakan Siswa';
    }

    public static function description(): string
    {
        return 'Siswa dengan tagihan belum lunas yang sudah jatuh tempo, per kelas.';
    }

    public static function filters(): array
    {
        return ['until', 'category', 'classroom'];
    }

    public function build(ReportFilters $filters): ReportTable
    {
        $bills = Bill::query()
            ->outstanding()
            ->with(['student.currentEnrollment.classroom', 'student.guardians', 'level', 'paymentCategory'])
            ->when($filters->level, fn ($query, Level $level) => $query->whereBelongsTo($level))
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', $filters->until->toDateString())
            ->when($filters->categoryId, fn ($query, int $categoryId) => $query->where('payment_category_id', $categoryId))
            ->when($filters->classroomId, fn ($query, int $classroomId) => $query->whereHas('student.enrollments', fn ($query) => $query->where('classroom_id', $classroomId)))
            ->get();

        $rows = $bills->groupBy('student_id')
            ->map(function ($studentBills) {
                /** @var Student $student */
                $student = $studentBills->first()->student;

                return [
                    'level' => $studentBills->first()->level->code,
                    'classroom' => $student->currentEnrollment?->classroom->name,
                    'nis' => $student->nis,
                    'student' => $student->name,
                    'guardian' => $student->guardians->map(fn ($guardian) => $guardian->name.' ('.$guardian->phone.')')->join(', '),
                    'categories' => $studentBills->map(fn (Bill $bill) => $bill->paymentCategory->name)->unique()->join(', '),
                    'bills' => $studentBills->count(),
                    'oldest_due' => $studentBills->sortBy(fn (Bill $bill) => $bill->due_date?->getTimestamp())->first()->due_date,
                    'outstanding' => (int) $studentBills->sum(fn (Bill $bill) => $bill->remainingAmount()),
                ];
            })
            ->sortBy([['level', 'asc'], ['classroom', 'asc'], ['student', 'asc']])
            ->values()
            ->all();

        $category = $filters->categoryId ? PaymentCategory::query()->find($filters->categoryId) : null;

        return new ReportTable(
            title: static::title(),
            subtitle: $filters->levelLabel().($category ? ', '.$category->name : '').', jatuh tempo s.d. '.$filters->until->translatedFormat('j F Y'),
            columns: [
                self::column('level', 'Jenjang'),
                self::column('classroom', 'Kelas'),
                self::column('nis', 'NIS'),
                self::column('student', 'Siswa'),
                self::column('guardian', 'Wali'),
                self::column('categories', 'Kategori'),
                self::column('bills', 'Tagihan', 'number'),
                self::column('oldest_due', 'Jatuh tempo terlama', 'date'),
                self::column('outstanding', 'Total tunggakan', 'money'),
            ],
            rows: $rows,
            totals: ['student' => count($rows).' siswa', 'bills' => array_sum(array_column($rows, 'bills')), 'outstanding' => array_sum(array_column($rows, 'outstanding'))],
        );
    }

    /**
     * @return array<int, string>
     */
    public static function categoryOptions(): array
    {
        return PaymentCategory::query()->ordered()->pluck('name', 'id')->all();
    }
}
