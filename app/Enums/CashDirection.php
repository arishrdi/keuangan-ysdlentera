<?php

namespace App\Enums;

enum CashDirection: string
{
    case In = 'masuk';
    case Out = 'keluar';

    public function label(): string
    {
        return match ($this) {
            self::In => 'Masuk',
            self::Out => 'Keluar',
        };
    }

    public function opposite(): self
    {
        return $this === self::In ? self::Out : self::In;
    }

    /**
     * +1 for money in, -1 for money out.
     */
    public function sign(): int
    {
        return $this === self::In ? 1 : -1;
    }
}
