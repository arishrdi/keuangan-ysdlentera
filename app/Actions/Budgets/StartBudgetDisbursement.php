<?php

namespace App\Actions\Budgets;

use App\Enums\BudgetAction;
use App\Enums\BudgetStatus;
use App\Models\ActivityLog;
use App\Models\BudgetProposal;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Mark an approved budget as being disbursed, before or while money is spent from it.
 */
class StartBudgetDisbursement
{
    public function handle(BudgetProposal $proposal, User $actor): BudgetProposal
    {
        if ($proposal->status !== BudgetStatus::Approved) {
            throw ValidationException::withMessages(['proposal' => 'Hanya anggaran berstatus Disetujui yang bisa mulai dicairkan.']);
        }

        $proposal->update(['status' => BudgetStatus::Disbursing]);

        $proposal->approvals()->create([
            'user_id' => $actor->id,
            'role' => $actor->role,
            'action' => BudgetAction::DisbursementStarted,
            'total_amount' => $proposal->total_amount,
        ]);

        ActivityLog::record('budget.disbursing', 'Memulai pencairan anggaran '.$proposal->number, $proposal);

        return $proposal;
    }
}
