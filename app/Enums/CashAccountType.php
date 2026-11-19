<?php

namespace App\Enums;

enum CashAccountType: string
{
    case Cash = 'tunai';
    case Bank = 'bank';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Kas tunai',
            self::Bank => 'Rekening bank',
        };
    }
}
