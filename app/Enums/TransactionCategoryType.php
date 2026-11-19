<?php

namespace App\Enums;

enum TransactionCategoryType: string
{
    case Income = 'pemasukan';
    case Expense = 'pengeluaran';

    public function label(): string
    {
        return match ($this) {
            self::Income => 'Pemasukan',
            self::Expense => 'Pengeluaran',
        };
    }
}
