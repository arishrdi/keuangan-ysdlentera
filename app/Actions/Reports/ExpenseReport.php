<?php

namespace App\Actions\Reports;

use App\Enums\CashTransactionType;
use App\Enums\TransactionCategoryType;
use App\Models\CashTransaction;
use App\Models\TransactionCategory;

/**
 * Every rupiah spent in the period, with the budget it was charged to.
 */
class ExpenseReport extends Report
{
    public static function key(): string
    {
        return 'pengeluaran';
    }

    public static function title(): string
    {
        return 'Laporan Pengeluaran';
    }

    public static function description(): string
    {
        return 'Pengeluaran per kategori beserta anggaran yang direalisasikan.';
    }

    public static function filters(): array
    {
        return ['period', 'category'];
    }

    public function build(ReportFilters $filters): ReportTable
    {
        $entries = CashTransaction::query()
            ->effective()
            ->with(['level', 'category', 'cashAccount', 'budgetProposal'])
            ->where('type', CashTransactionType::Expense)
            ->when($filters->level, fn ($query, $level) => $query->whereBelongsTo($level))
            ->whereBetween('transacted_on', [$filters->from->toDateString(), $filters->until->toDateString()])
            ->when($filters->categoryId, fn ($query, int $categoryId) => $query->where('transaction_category_id', $categoryId))
            ->orderBy('transacted_on')
            ->orderBy('id')
            ->get();

        return new ReportTable(
            title: static::title(),
            subtitle: $filters->levelLabel().', '.$filters->periodLabel(),
            columns: [
                self::column('date', 'Tanggal', 'date'),
                self::column('number', 'No. transaksi'),
                self::column('level', 'Jenjang'),
                self::column('category', 'Kategori'),
                self::column('description', 'Keterangan'),
                self::column('counterparty', 'Kepada'),
                self::column('budget', 'Anggaran'),
                self::column('account', 'Akun'),
                self::column('amount', 'Jumlah', 'money'),
            ],
            rows: $entries->map(fn (CashTransaction $entry) => [
                'date' => $entry->transacted_on,
                'number' => $entry->number,
                'level' => $entry->level->code,
                'category' => $entry->category?->name,
                'description' => $entry->description,
                'counterparty' => $entry->counterparty,
                'budget' => $entry->budgetProposal?->number,
                'account' => $entry->cashAccount->name,
                'amount' => $entry->amount,
            ])->all(),
            totals: ['description' => 'Total pengeluaran', 'amount' => (int) $entries->sum('amount')],
            summary: $entries->groupBy(fn (CashTransaction $entry) => $entry->category->name ?? '-')
                ->map(fn ($group, string $name) => ['label' => $name, 'value' => self::rupiah((int) $group->sum('amount'))])
                ->values()
                ->all(),
        );
    }

    /**
     * @return array<int, string>
     */
    public static function categoryOptions(): array
    {
        return TransactionCategory::query()->ofType(TransactionCategoryType::Expense)->orderBy('name')->pluck('name', 'id')->all();
    }
}
