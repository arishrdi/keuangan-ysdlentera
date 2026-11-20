<?php

namespace App\Actions\Reports;

use App\Enums\BillStatus;
use App\Models\Bill;
use App\Models\Level;
use App\Models\PaymentCategory;

/**
 * Billed, received and outstanding amounts of the five payment categories in an academic year.
 */
class PaymentRecapReport extends Report
{
    public static function key(): string
    {
        return 'rekap-pembayaran';
    }

    public static function title(): string
    {
        return 'Rekap Pembayaran per Kategori';
    }

    public static function description(): string
    {
        return 'Tagihan, penerimaan, dan tunggakan Infak, DPP, Bangunan, Seragam, dan SPP.';
    }

    public static function filters(): array
    {
        return ['academic_year', 'classroom'];
    }

    public function build(ReportFilters $filters): ReportTable
    {
        $groups = Bill::query()
            ->where('status', '!=', BillStatus::Cancelled)
            ->where('academic_year_id', $filters->academicYear?->id)
            ->when($filters->level, fn ($query, Level $level) => $query->whereBelongsTo($level))
            ->when($filters->classroomId, fn ($query, int $classroomId) => $query->whereHas('student.enrollments', fn ($query) => $query->where('classroom_id', $classroomId)))
            ->groupBy('level_id', 'payment_category_id')
            ->select('level_id', 'payment_category_id')
            ->selectRaw('count(*) as bills')
            ->selectRaw('count(distinct student_id) as students')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as paid_bills', [BillStatus::Paid->value])
            ->selectRaw('sum(amount) as gross')
            ->selectRaw('sum(discount_amount) as discount')
            ->selectRaw('sum(paid_amount) as received')
            ->toBase()
            ->get();

        $levels = Level::query()->ordered()->get()->keyBy('id');
        $categories = PaymentCategory::query()->ordered()->get();

        $rows = [];

        foreach ($levels as $level) {
            foreach ($categories as $category) {
                $group = $groups->first(fn ($row) => (int) $row->level_id === $level->id && (int) $row->payment_category_id === $category->id);

                if ($group === null) {
                    continue;
                }

                $net = (int) $group->gross - (int) $group->discount;

                $rows[] = [
                    'level' => $level->code,
                    'category' => $category->name,
                    'students' => (int) $group->students,
                    'bills' => (int) $group->bills,
                    'gross' => (int) $group->gross,
                    'discount' => (int) $group->discount,
                    'received' => (int) $group->received,
                    'outstanding' => $net - (int) $group->received,
                    'paid_rate' => self::percent((int) $group->received, $net),
                ];
            }
        }

        $total = fn (string $key): int => (int) array_sum(array_column($rows, $key));

        return new ReportTable(
            title: static::title(),
            subtitle: $filters->levelLabel().', tahun ajaran '.($filters->academicYear->name ?? '-'),
            columns: [
                self::column('level', 'Jenjang'),
                self::column('category', 'Kategori'),
                self::column('students', 'Siswa', 'number'),
                self::column('bills', 'Tagihan', 'number'),
                self::column('gross', 'Nominal', 'money'),
                self::column('discount', 'Potongan', 'money'),
                self::column('received', 'Diterima', 'money'),
                self::column('outstanding', 'Tunggakan', 'money'),
                self::column('paid_rate', '% terbayar', 'percent'),
            ],
            rows: $rows,
            totals: [
                'category' => 'Total',
                'bills' => $total('bills'),
                'gross' => $total('gross'),
                'discount' => $total('discount'),
                'received' => $total('received'),
                'outstanding' => $total('outstanding'),
                'paid_rate' => self::percent($total('received'), $total('gross') - $total('discount')),
            ],
        );
    }
}
