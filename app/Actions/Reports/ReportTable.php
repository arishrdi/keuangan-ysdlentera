<?php

namespace App\Actions\Reports;

/**
 * A finished report: the same data is shown on screen, exported to Excel and printed to PDF.
 *
 * Column types: text, money, number, date, percent.
 */
final readonly class ReportTable
{
    /**
     * @param  array<int, array{key: string, label: string, type: string}>  $columns
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $totals
     * @param  array<int, array{label: string, value: string}>  $summary
     */
    public function __construct(
        public string $title,
        public string $subtitle,
        public array $columns,
        public array $rows,
        public ?array $totals = null,
        public array $summary = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
