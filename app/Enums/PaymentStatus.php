<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'menunggu';
    case Verified = 'terverifikasi';
    case Rejected = 'ditolak';
    case Cancelled = 'dibatalkan';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu verifikasi',
            self::Verified => 'Terverifikasi',
            self::Rejected => 'Ditolak',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending => 'badge-warning',
            self::Verified => 'badge-success',
            self::Rejected => 'badge-error',
            self::Cancelled => 'badge-ghost',
        };
    }
}
