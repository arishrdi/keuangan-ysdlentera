<?php

namespace App\Enums;

enum TransactionCategoryType: string
{
    case Income = 'pemasukan';
    case Expense = 'pengeluaran';
    case Budget = 'anggaran';

    public function label(): string
    {
        return match ($this) {
            self::Income => 'Pemasukan',
            self::Expense => 'Pengeluaran',
            self::Budget => 'Anggaran',
        };
    }
}
