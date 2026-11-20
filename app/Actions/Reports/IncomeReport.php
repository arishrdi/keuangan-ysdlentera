<?php

namespace App\Actions\Reports;

use App\Enums\CashTransactionType;
use App\Enums\TransactionCategoryType;
use App\Models\CashTransaction;
use App\Models\TransactionCategory;

/**
 * Every rupiah received in the period: verified student payments and other income.
 */
class IncomeReport extends Report
{
    public static function key(): string
    {
        return 'pemasukan';
    }

    public static function title(): string
    {
        return 'Laporan Pemasukan';
    }

    public static function description(): string
    {
        return 'Pembayaran siswa terverifikasi dan pemasukan lain per periode.';
    }

    public static function filters(): array
    {
        return ['period', 'category'];
    }

    public function build(ReportFilters $filters): ReportTable
    {
        $entries = CashTransaction::query()
            ->effective()
            ->with(['level', 'category', 'cashAccount'])
            ->whereIn('type', [CashTransactionType::StudentPayment, CashTransactionType::Income])
            ->when($filters->level, fn ($query, $level) => $query->whereBelongsTo($level))
            ->whereBetween('transacted_on', [$filters->from->toDateString(), $filters->until->toDateString()])
            ->when($filters->categoryId === -1, fn ($query) => $query->where('type', CashTransactionType::StudentPayment))
            ->when($filters->categoryId > 0, fn ($query) => $query->where('transaction_category_id', $filters->categoryId))
            ->orderBy('transacted_on')
            ->orderBy('id')
            ->get();

        $byCategory = $entries->groupBy(fn (CashTransaction $entry) => $entry->category->name ?? $entry->type->label())
            ->map(fn ($group, string $name) => ['label' => $name, 'value' => self::rupiah((int) $group->sum('amount'))])
            ->values()
            ->all();

        return new ReportTable(
            title: static::title(),
            subtitle: $filters->levelLabel().' · '.$filters->periodLabel(),
            columns: [
                self::column('date', 'Tanggal', 'date'),
                self::column('number', 'No. transaksi'),
                self::column('level', 'Jenjang'),
                self::column('category', 'Kategori'),
                self::column('description', 'Keterangan'),
                self::column('counterparty', 'Dari'),
                self::column('account', 'Akun'),
                self::column('amount', 'Jumlah', 'money'),
            ],
            rows: $entries->map(fn (CashTransaction $entry) => [
                'date' => $entry->transacted_on,
                'number' => $entry->number,
                'level' => $entry->level->code,
                'category' => $entry->category->name ?? $entry->type->label(),
                'description' => $entry->description,
                'counterparty' => $entry->counterparty,
                'account' => $entry->cashAccount->name,
                'amount' => $entry->amount,
            ])->all(),
            totals: ['description' => 'Total pemasukan', 'amount' => (int) $entries->sum('amount')],
            summary: $byCategory,
        );
    }

    /**
     * @return array<int, string>
     */
    public static function categoryOptions(): array
    {
        return [-1 => 'Pembayaran siswa'] + TransactionCategory::query()->ofType(TransactionCategoryType::Income)->orderBy('name')->pluck('name', 'id')->all();
    }
}
