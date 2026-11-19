<?php

namespace App\Enums;

enum BillStatus: string
{
    case Unpaid = 'belum_bayar';
    case Partial = 'sebagian';
    case Paid = 'lunas';
    case Cancelled = 'dibatalkan';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Belum bayar',
            self::Partial => 'Sebagian',
            self::Paid => 'Lunas',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Unpaid => 'badge-error',
            self::Partial => 'badge-warning',
            self::Paid => 'badge-success',
            self::Cancelled => 'badge-ghost',
        };
    }

    /**
     * @return array<int, self>
     */
    public static function outstanding(): array
    {
        return [self::Unpaid, self::Partial];
    }
}
