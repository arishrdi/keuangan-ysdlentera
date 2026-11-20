<?php

namespace App\Actions\Reports;

use App\Models\User;

/**
 * Every available report, in the order shown on the Laporan page.
 */
final class ReportRegistry
{
    /**
     * @var array<int, class-string<Report>>
     */
    public const array REPORTS = [
        IncomeReport::class,
        ExpenseReport::class,
        CashMutationReport::class,
        PaymentRecapReport::class,
        ArrearsReport::class,
        SavingsReport::class,
        BudgetRealizationReport::class,
        ConsolidatedReport::class,
    ];

    /**
     * @return class-string<Report>|null
     */
    public static function find(string $key): ?string
    {
        foreach (self::REPORTS as $report) {
            if ($report::key() === $key) {
                return $report;
            }
        }

        return null;
    }

    /**
     * @return array<int, class-string<Report>>
     */
    public static function availableTo(User $user): array
    {
        return array_values(array_filter(self::REPORTS, fn (string $report) => $report::allows($user)));
    }
}
