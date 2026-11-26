<?php

namespace App\Actions\Budgets;

use App\Enums\BudgetAction;
use App\Enums\BudgetStatus;
use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\BudgetProposal;
use App\Models\User;
use App\Notifications\BudgetProposalUpdated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;

/**
 * Record the principal's (stage 1) or foundation's (stage 2) decision on a proposal.
 */
class DecideBudgetProposal
{
    public function handle(BudgetProposal $proposal, User $actor, BudgetAction $decision, ?string $notes = null): BudgetProposal
    {
        if (! in_array($decision, [BudgetAction::Approved, BudgetAction::Rejected, BudgetAction::RevisionRequested], true)) {
            throw ValidationException::withMessages(['decision' => 'Keputusan tidak dikenal.']);
        }

        $awaitingRole = $proposal->status->awaitingRole();

        if ($awaitingRole === null || ! $actor->hasRole($awaitingRole) || ! $actor->canAccessLevel($proposal->level_id)) {
            throw ValidationException::withMessages(['decision' => 'Pengajuan ini tidak sedang menunggu keputusan Anda.']);
        }

        if ($decision !== BudgetAction::Approved && blank($notes)) {
            throw ValidationException::withMessages(['notes' => 'Catatan wajib diisi saat menolak atau meminta revisi.']);
        }

        $isPrincipal = $awaitingRole === Role::Principal;

        $status = match ($decision) {
            BudgetAction::Approved => $isPrincipal ? BudgetStatus::AwaitingFoundation : BudgetStatus::Approved,
            BudgetAction::Rejected => $isPrincipal ? BudgetStatus::RejectedByPrincipal : BudgetStatus::RejectedByFoundation,
            default => BudgetStatus::NeedsRevision,
        };

        DB::transaction(function () use ($proposal, $actor, $decision, $notes, $isPrincipal, $status): void {
            $proposal->update([
                'status' => $status,
                'approved_at' => $status === BudgetStatus::Approved ? now() : $proposal->approved_at,
            ]);

            $proposal->approvals()->create([
                'user_id' => $actor->id,
                'role' => $actor->role,
                'action' => $decision,
                'stage' => $isPrincipal ? 1 : 2,
                'notes' => $notes,
                'total_amount' => $proposal->total_amount,
            ]);

            ActivityLog::record('budget.decided', $decision->label().' anggaran '.$proposal->number.' oleh '.$actor->role->label(), $proposal, ['notes' => $notes]);
        });

        $this->notify($proposal, $actor, $status);

        return $proposal;
    }

    private function notify(BudgetProposal $proposal, User $actor, BudgetStatus $status): void
    {
        $summary = $proposal->number.' '.$proposal->title.' ('.Number::rupiah($proposal->total_amount).')';

        if ($status === BudgetStatus::AwaitingFoundation) {
            Notification::send(
                User::query()->where('role', Role::Foundation)->where('is_active', true)->get(),
                new BudgetProposalUpdated($proposal, 'Anggaran menunggu persetujuan Yayasan', $summary.' telah disetujui Kepala Sekolah.'),
            );
        }

        Notification::send(
            User::query()->where('role', Role::Treasurer)->where('is_active', true)->withAccessToLevel($proposal->level_id)->get(),
            new BudgetProposalUpdated($proposal, 'Status anggaran: '.$status->label(), $summary.' oleh '.$actor->name.'.'),
        );
    }
}
