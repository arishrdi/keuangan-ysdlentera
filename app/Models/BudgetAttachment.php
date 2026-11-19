<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A supporting document (RAB, quotation) stored privately.
 *
 * @property int $id
 * @property int $budget_proposal_id
 * @property string $path
 * @property string $original_name
 * @property int $size
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['budget_proposal_id', 'path', 'original_name', 'size'])]
class BudgetAttachment extends Model
{
    /**
     * @return BelongsTo<BudgetProposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(BudgetProposal::class, 'budget_proposal_id');
    }
}
