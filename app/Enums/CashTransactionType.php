<?php

namespace App\Enums;

enum CashTransactionType: string
{
    case StudentPayment = 'pembayaran_siswa';
    case Income = 'pemasukan';
    case Expense = 'pengeluaran';
    case Transfer = 'transfer';
    case OpeningBalance = 'saldo_awal';

    public function label(): string
    {
        return match ($this) {
            self::StudentPayment => 'Pembayaran siswa',
            self::Income => 'Pemasukan lain',
            self::Expense => 'Pengeluaran',
            self::Transfer => 'Transfer antar akun',
            self::OpeningBalance => 'Saldo awal',
        };
    }
}
