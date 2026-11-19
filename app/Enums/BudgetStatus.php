<?php

namespace App\Enums;

enum BudgetStatus: string
{
    case Draft = 'draft';
    case AwaitingPrincipal = 'menunggu_kepsek';
    case RejectedByPrincipal = 'ditolak_kepsek';
    case NeedsRevision = 'perlu_revisi';
    case AwaitingFoundation = 'menunggu_yayasan';
    case RejectedByFoundation = 'ditolak_yayasan';
    case Approved = 'disetujui';
    case Disbursing = 'dalam_pencairan';
    case Completed = 'selesai';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::AwaitingPrincipal => 'Menunggu Kepala Sekolah',
            self::RejectedByPrincipal => 'Ditolak Kepala Sekolah',
            self::NeedsRevision => 'Perlu Revisi',
            self::AwaitingFoundation => 'Menunggu Yayasan',
            self::RejectedByFoundation => 'Ditolak Yayasan',
            self::Approved => 'Disetujui',
            self::Disbursing => 'Dalam Proses Pencairan',
            self::Completed => 'Selesai',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'badge-ghost',
            self::AwaitingPrincipal, self::AwaitingFoundation => 'badge-warning',
            self::RejectedByPrincipal, self::RejectedByFoundation => 'badge-error',
            self::NeedsRevision => 'badge-info',
            self::Approved, self::Disbursing => 'badge-success',
            self::Completed => 'badge-neutral',
        };
    }

    /**
     * Whether the treasurer may still change the proposal.
     */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::NeedsRevision], true);
    }

    /**
     * Whether expenses may still be linked to the proposal.
     */
    public function acceptsExpenses(): bool
    {
        return in_array($this, [self::Approved, self::Disbursing], true);
    }

    /**
     * The role that must decide on a proposal in this status, if any.
     */
    public function awaitingRole(): ?Role
    {
        return match ($this) {
            self::AwaitingPrincipal => Role::Principal,
            self::AwaitingFoundation => Role::Foundation,
            default => null,
        };
    }
}
