<?php

namespace App\Actions\Reports;

use App\Enums\BudgetStatus;
use App\Models\BudgetProposal;
use App\Models\Level;

/**
 * Proposed, approved, used and remaining amounts of budgets submitted in the period.
 */
class BudgetRealizationReport extends Report
{
    public static function key(): string
    {
        return 'anggaran';
    }

    public static function title(): string
    {
        return 'Laporan Realisasi Anggaran';
    }

    public static function description(): string
    {
        return 'Pengajuan, persetujuan, penggunaan, dan sisa anggaran.';
    }

    public static function filters(): array
    {
        return ['period'];
    }

    public function build(ReportFilters $filters): ReportTable
    {
        $proposals = BudgetProposal::query()
            ->with(['level', 'category'])
            ->withRealizedAmount()
            ->where('status', '!=', BudgetStatus::Draft)
            ->when($filters->level, fn ($query, Level $level) => $query->whereBelongsTo($level))
            ->whereBetween('submitted_at', [$filters->from, $filters->until])
            ->orderBy('submitted_at')
            ->get();

        $rows = $proposals->map(function (BudgetProposal $proposal) {
            $isApproved = $proposal->status->acceptsExpenses() || $proposal->status === BudgetStatus::Completed;
            $approved = $isApproved ? $proposal->total_amount : 0;
            $realized = (int) $proposal->realized_amount;

            return [
                'submitted_at' => $proposal->submitted_at,
                'number' => $proposal->number,
                'level' => $proposal->level->code,
                'title' => $proposal->title,
                'category' => $proposal->category->name,
                'status' => $proposal->status->label(),
                'proposed' => $proposal->total_amount,
                'approved' => $approved,
                'realized' => $realized,
                'remaining' => $approved - $realized,
                'realization_rate' => self::percent($realized, $approved),
            ];
        })->all();

        $total = fn (string $key): int => (int) array_sum(array_column($rows, $key));

        return new ReportTable(
            title: static::title(),
            subtitle: $filters->levelLabel().', diajukan '.$filters->periodLabel(),
            columns: [
                self::column('submitted_at', 'Diajukan', 'date'),
                self::column('number', 'Nomor'),
                self::column('level', 'Jenjang'),
                self::column('title', 'Kegiatan'),
                self::column('category', 'Kategori'),
                self::column('status', 'Status'),
                self::column('proposed', 'Diajukan (Rp)', 'money'),
                self::column('approved', 'Disetujui', 'money'),
                self::column('realized', 'Realisasi', 'money'),
                self::column('remaining', 'Sisa', 'money'),
                self::column('realization_rate', '% realisasi', 'percent'),
            ],
            rows: $rows,
            totals: [
                'title' => count($rows).' pengajuan',
                'proposed' => $total('proposed'),
                'approved' => $total('approved'),
                'realized' => $total('realized'),
                'remaining' => $total('remaining'),
                'realization_rate' => self::percent($total('realized'), $total('approved')),
            ],
        );
    }
}
