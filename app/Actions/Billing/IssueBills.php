<?php

namespace App\Actions\Billing;

use App\Enums\BillingCycle;
use App\Enums\BillStatus;
use App\Models\AcademicYear;
use App\Models\ActivityLog;
use App\Models\Bill;
use App\Models\FeeRate;
use App\Models\Level;
use App\Models\PaymentCategory;
use App\Models\Student;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Issue monthly or one-time bills of a category to many students using the level's fee rates.
 */
class IssueBills
{
    /**
     * @param  Collection<int, Student>  $students
     * @param  array<int, CarbonInterface>  $periods  First day of each billed month; only used for monthly categories.
     * @return array{created: int, existing: int, without_rate: int}
     */
    public function handle(
        PaymentCategory $category,
        Level $level,
        AcademicYear $academicYear,
        Collection $students,
        array $periods = [],
        int $dueDay = 10,
        ?CarbonInterface $dueDate = null,
        ?int $amountOverride = null,
    ): array {
        if ($category->billing_cycle === BillingCycle::Itemized) {
            throw new InvalidArgumentException('Itemized categories are billed per student.');
        }

        $isMonthly = $category->billing_cycle === BillingCycle::Monthly;

        if ($isMonthly && $periods === []) {
            throw new InvalidArgumentException('Monthly bills need at least one period.');
        }

        $result = ['created' => 0, 'existing' => 0, 'without_rate' => 0];

        DB::transaction(function () use ($category, $level, $academicYear, $students, $periods, $dueDay, $dueDate, $amountOverride, $isMonthly, &$result): void {
            foreach ($students as $student) {
                if ($student->level_id !== $level->id) {
                    continue;
                }

                $amount = $amountOverride ?? FeeRate::amountFor($category, $level, $academicYear, $student->gradeIn($academicYear));

                if ($amount === null || $amount <= 0) {
                    $result['without_rate']++;

                    continue;
                }

                foreach ($isMonthly ? $periods : [null] as $period) {
                    if ($this->alreadyBilled($student, $category, $academicYear, $period)) {
                        $result['existing']++;

                        continue;
                    }

                    Bill::query()->create([
                        'student_id' => $student->id,
                        'level_id' => $level->id,
                        'academic_year_id' => $academicYear->id,
                        'payment_category_id' => $category->id,
                        'period' => $period?->toDateString(),
                        'description' => $period
                            ? $category->name.' '.$period->translatedFormat('F Y')
                            : $category->name.' '.$academicYear->name,
                        'amount' => $amount,
                        'due_date' => $period
                            ? $period->copy()->day(min($dueDay, $period->daysInMonth))->toDateString()
                            : $dueDate?->toDateString(),
                        'status' => BillStatus::Unpaid,
                        'created_by' => Auth::id(),
                    ]);

                    $result['created']++;
                }
            }

            ActivityLog::record('bill.issued', 'Menerbitkan '.$result['created'].' tagihan '.$category->name.' jenjang '.$level->name, $category, [
                ...$result,
                'level_id' => $level->id,
                'academic_year_id' => $academicYear->id,
                'periods' => array_map(fn (CarbonInterface $period) => $period->format('Y-m'), $periods),
            ]);
        });

        return $result;
    }

    /**
     * Monthly bills are unique per month; one-time bills are unique per academic year.
     */
    private function alreadyBilled(Student $student, PaymentCategory $category, AcademicYear $academicYear, ?CarbonInterface $period): bool
    {
        return Bill::query()
            ->where('student_id', $student->id)
            ->where('payment_category_id', $category->id)
            ->where('status', '!=', BillStatus::Cancelled)
            ->when(
                $period,
                fn ($query, CarbonInterface $period) => $query->whereDate('period', $period->toDateString()),
                fn ($query) => $query->where('academic_year_id', $academicYear->id),
            )
            ->exists();
    }
}
