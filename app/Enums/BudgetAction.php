<?php

namespace App\Enums;

enum BudgetAction: string
{
    case Submitted = 'diajukan';
    case Approved = 'disetujui';
    case Rejected = 'ditolak';
    case RevisionRequested = 'minta_revisi';
    case DisbursementStarted = 'pencairan';
    case Completed = 'selesai';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Diajukan',
            self::Approved => 'Disetujui',
            self::Rejected => 'Ditolak',
            self::RevisionRequested => 'Diminta revisi',
            self::DisbursementStarted => 'Mulai pencairan',
            self::Completed => 'Diselesaikan',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Submitted => 'badge-ghost',
            self::Approved, self::DisbursementStarted => 'badge-success',
            self::Rejected => 'badge-error',
            self::RevisionRequested => 'badge-info',
            self::Completed => 'badge-neutral',
        };
    }
}
