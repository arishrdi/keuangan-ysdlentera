<?php

namespace App\Enums;

enum BillingCycle: string
{
    case Monthly = 'bulanan';
    case OneTime = 'sekali';
    case Itemized = 'per_item';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Bulanan',
            self::OneTime => 'Sekali bayar',
            self::Itemized => 'Per item',
        };
    }
}
