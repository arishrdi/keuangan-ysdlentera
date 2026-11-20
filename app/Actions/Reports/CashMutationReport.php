<?php

namespace App\Actions\Reports;

use App\Enums\CashDirection;
use App\Models\CashAccount;
use App\Models\CashTransaction;

/**
 * Cash book of one account or all accounts: opening balance, every movement, running balance.
 */
class CashMutationReport extends Report
{
    public static function key(): string
    {
        return 'mutasi-kas';
    }

    public static function title(): string
    {
        return 'Laporan Mutasi Kas';
    }

    public static function description(): string
    {
        return 'Saldo awal, mutasi masuk/keluar, dan saldo akhir per akun kas.';
    }

    public static function filters(): array
    {
        return ['period', 'account'];
    }

    public function build(ReportFilters $filters): ReportTable
    {
        $accountIds = CashAccount::query()
            ->when($filters->level, fn ($query, $level) => $query->whereBelongsTo($level))
            ->when($filters->accountId, fn ($query, int $accountId) => $query->whereKey($accountId))
            ->pluck('id');

        $opening = (int) CashTransaction::query()
            ->whereIn('cash_account_id', $accountIds)
            ->whereDate('transacted_on', '<', $filters->from->toDateString())
            ->selectRaw('coalesce(sum(case when direction = ? then amount else -amount end), 0) as balance', [CashDirection::In->value])
            ->value('balance');

        $mutations = CashTransaction::query()
            ->with(['cashAccount', 'category'])
            ->whereIn('cash_account_id', $accountIds)
            ->whereBetween('transacted_on', [$filters->from->toDateString(), $filters->until->toDateString()])
            ->orderBy('transacted_on')
            ->orderBy('id')
            ->get();

        $balance = $opening;
        $rows = [[
            'date' => null,
            'number' => null,
            'type' => null,
            'description' => 'Saldo sebelum '.$filters->from->translatedFormat('j F Y'),
            'account' => null,
            'in' => null,
            'out' => null,
            'balance' => $opening,
        ]];

        foreach ($mutations as $mutation) {
            $balance += $mutation->signedAmount();
            $rows[] = [
                'date' => $mutation->transacted_on,
                'number' => $mutation->number,
                'type' => $mutation->isReversal() ? 'Pembatalan' : ($mutation->category->name ?? $mutation->type->label()),
                'description' => $mutation->description,
                'account' => $mutation->cashAccount->name,
                'in' => $mutation->direction === CashDirection::In ? $mutation->amount : null,
                'out' => $mutation->direction === CashDirection::Out ? $mutation->amount : null,
                'balance' => $balance,
            ];
        }

        $totalIn = (int) $mutations->where('direction', CashDirection::In)->sum('amount');
        $totalOut = (int) $mutations->where('direction', CashDirection::Out)->sum('amount');
        $account = $filters->accountId ? CashAccount::query()->find($filters->accountId) : null;

        return new ReportTable(
            title: static::title(),
            subtitle: $filters->levelLabel().' · '.($account->name ?? 'Semua akun').' · '.$filters->periodLabel(),
            columns: [
                self::column('date', 'Tanggal', 'date'),
                self::column('number', 'No. transaksi'),
                self::column('type', 'Jenis'),
                self::column('description', 'Keterangan'),
                self::column('account', 'Akun'),
                self::column('in', 'Masuk', 'money'),
                self::column('out', 'Keluar', 'money'),
                self::column('balance', 'Saldo', 'money'),
            ],
            rows: $rows,
            totals: ['description' => 'Total periode', 'in' => $totalIn, 'out' => $totalOut, 'balance' => $balance],
            summary: [
                ['label' => 'Saldo awal', 'value' => self::rupiah($opening)],
                ['label' => 'Total masuk', 'value' => self::rupiah($totalIn)],
                ['label' => 'Total keluar', 'value' => self::rupiah($totalOut)],
                ['label' => 'Saldo akhir', 'value' => self::rupiah($balance)],
            ],
        );
    }
}
