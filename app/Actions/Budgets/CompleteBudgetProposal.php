<?php

namespace App\Actions\Budgets;

use App\Enums\BudgetAction;
use App\Enums\BudgetStatus;
use App\Models\ActivityLog;
use App\Models\BudgetProposal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;

/**
 * Close an approved budget. Its remaining amount stays recorded and no more expenses can be linked.
 */
class CompleteBudgetProposal
{
    public function handle(BudgetProposal $proposal, User $actor, ?string $notes = null): BudgetProposal
    {
        if (! $proposal->status->acceptsExpenses()) {
            throw ValidationException::withMessages(['proposal' => 'Hanya anggaran yang sudah disetujui yang bisa diselesaikan.']);
        }

        DB::transaction(function () use ($proposal, $actor, $notes): void {
            $realized = $proposal->realizedAmount();

            $proposal->update(['status' => BudgetStatus::Completed, 'completed_at' => now()]);

            $proposal->approvals()->create([
                'user_id' => $actor->id,
                'role' => $actor->role,
                'action' => BudgetAction::Completed,
                'notes' => trim('Realisasi '.Number::rupiah($realized).', sisa '.Number::rupiah($proposal->total_amount - $realized).'. '.$notes),
                'total_amount' => $proposal->total_amount,
            ]);

            ActivityLog::record('budget.completed', 'Menyelesaikan anggaran '.$proposal->number, $proposal, ['realized' => $realized]);
        });

        return $proposal;
    }
}
