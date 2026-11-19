<?php

namespace App\Enums;

enum SavingsTransactionType: string
{
    case Deposit = 'setoran';
    case Withdrawal = 'penarikan';
    case OpeningBalance = 'saldo_awal';

    public function label(): string
    {
        return match ($this) {
            self::Deposit => 'Setoran',
            self::Withdrawal => 'Penarikan',
            self::OpeningBalance => 'Saldo awal',
        };
    }

    public function direction(): CashDirection
    {
        return $this === self::Withdrawal ? CashDirection::Out : CashDirection::In;
    }
}
