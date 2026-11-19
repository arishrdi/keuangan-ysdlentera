<?php

namespace App\Enums;

enum StudentStatus: string
{
    case Active = 'aktif';
    case Graduated = 'lulus';
    case Transferred = 'pindah';
    case DroppedOut = 'keluar';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktif',
            self::Graduated => 'Lulus',
            self::Transferred => 'Pindah',
            self::DroppedOut => 'Keluar',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Active => 'badge-success',
            self::Graduated => 'badge-info',
            self::Transferred => 'badge-warning',
            self::DroppedOut => 'badge-neutral',
        };
    }
}
