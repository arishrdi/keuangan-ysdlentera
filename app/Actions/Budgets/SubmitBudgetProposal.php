<?php

namespace App\Actions\Budgets;

use App\Enums\BudgetAction;
use App\Enums\BudgetStatus;
use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\BudgetProposal;
use App\Models\DocumentSequence;
use App\Models\User;
use App\Notifications\BudgetProposalUpdated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;

/**
 * Send a draft or revised proposal to the principal. The number is given on the first submission.
 */
class SubmitBudgetProposal
{
    public function handle(BudgetProposal $proposal, User $actor): BudgetProposal
    {
        if (! $proposal->status->isEditable()) {
            throw ValidationException::withMessages(['proposal' => 'Pengajuan ini sudah dikirim.']);
        }

        if (! $proposal->items()->exists()) {
            throw ValidationException::withMessages(['proposal' => 'Tambahkan minimal satu rincian kebutuhan sebelum mengirim.']);
        }

        DB::transaction(function () use ($proposal, $actor): void {
            $proposal->update([
                'number' => $proposal->number ?? DocumentSequence::next(DocumentSequence::BUDGET, $proposal->level, now()->year),
                'status' => BudgetStatus::AwaitingPrincipal,
                'submitted_at' => now(),
            ]);

            $proposal->approvals()->create([
                'user_id' => $actor->id,
                'role' => $actor->role,
                'action' => BudgetAction::Submitted,
                'total_amount' => $proposal->total_amount,
            ]);

            ActivityLog::record('budget.submitted', 'Mengajukan anggaran '.$proposal->number.' '.$proposal->title, $proposal);
        });

        Notification::send(
            User::query()->where('role', Role::Principal)->where('is_active', true)->withAccessToLevel($proposal->level_id)->get(),
            new BudgetProposalUpdated($proposal, 'Pengajuan anggaran baru', $proposal->number.' '.$proposal->title.' ('.$proposal->level->name.') sebesar '.Number::rupiah($proposal->total_amount).' menunggu persetujuan Anda.'),
        );

        return $proposal;
    }
}
