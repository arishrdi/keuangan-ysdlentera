<?php

namespace App\Actions\Reports;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Number;

/**
 * Base class for every report shown under Laporan.
 */
abstract class Report
{
    /**
     * URL key of the report, e.g. "pemasukan".
     */
    abstract public static function key(): string;

    abstract public static function title(): string;

    abstract public static function description(): string;

    /**
     * Filters shown above the report: period, academic_year, category, classroom, account.
     *
     * @return array<int, string>
     */
    abstract public static function filters(): array;

    abstract public function build(ReportFilters $filters): ReportTable;

    /**
     * @return array<int, Role>
     */
    public static function roles(): array
    {
        return [Role::Treasurer, Role::Principal, Role::Foundation];
    }

    public static function allows(User $user): bool
    {
        return $user->hasRole(...static::roles());
    }

    /**
     * @return array{key: string, label: string, type: string}
     */
    protected static function column(string $key, string $label, string $type = 'text'): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type];
    }

    protected static function percent(int $part, int $whole): float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : 0.0;
    }

    protected static function rupiah(int $amount): string
    {
        return Number::rupiah($amount);
    }
}
