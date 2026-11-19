<?php

namespace App\Models;

use App\Enums\BudgetAction;
use App\Enums\Role;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One step in a budget proposal's audit trail: who did what, at which stage, with which note.
 * Rows are never updated or removed.
 *
 * @property int $id
 * @property int $budget_proposal_id
 * @property int|null $user_id
 * @property Role|null $role
 * @property BudgetAction $action
 * @property int|null $stage
 * @property string|null $notes
 * @property int $total_amount
 * @property Carbon $created_at
 */
#[Fillable(['budget_proposal_id', 'user_id', 'role', 'action', 'stage', 'notes', 'total_amount'])]
class BudgetApproval extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'action' => BudgetAction::class,
            'stage' => 'integer',
            'total_amount' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<BudgetProposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(BudgetProposal::class, 'budget_proposal_id');
    }
}
