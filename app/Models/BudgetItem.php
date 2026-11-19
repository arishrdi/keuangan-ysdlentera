<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of a budget's cost breakdown (RAB).
 *
 * @property int $id
 * @property int $budget_proposal_id
 * @property string $name
 * @property int $quantity
 * @property string $unit
 * @property int $unit_price
 * @property int $subtotal
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['budget_proposal_id', 'name', 'quantity', 'unit', 'unit_price', 'subtotal', 'sort_order'])]
class BudgetItem extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'subtotal' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<BudgetProposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(BudgetProposal::class, 'budget_proposal_id');
    }
}
