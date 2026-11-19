<?php

namespace App\Models;

use App\Enums\BudgetStatus;
use App\Enums\CashTransactionType;
use Database\Factories\BudgetProposalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A budget request (pengajuan anggaran) from the treasurer, approved by the principal and then the foundation.
 *
 * @property int $id
 * @property string|null $number
 * @property int $level_id
 * @property int $transaction_category_id
 * @property string $title
 * @property string $reason
 * @property int $total_amount
 * @property BudgetStatus $status
 * @property Carbon|null $submitted_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $completed_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int|null $realized_amount
 */
#[Fillable([
    'number', 'level_id', 'transaction_category_id', 'title', 'reason', 'total_amount', 'status',
    'submitted_at', 'approved_at', 'completed_at', 'created_by',
])]
class BudgetProposal extends Model
{
    /** @use HasFactory<BudgetProposalFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BudgetStatus::class,
            'total_amount' => 'integer',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Level, $this>
     */
    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    /**
     * @return BelongsTo<TransactionCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(TransactionCategory::class, 'transaction_category_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<BudgetItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(BudgetItem::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<BudgetAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(BudgetAttachment::class);
    }

    /**
     * The audit trail of submissions and decisions, oldest first.
     *
     * @return HasMany<BudgetApproval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(BudgetApproval::class)->orderBy('id');
    }

    /**
     * Expenses paid from this budget.
     *
     * @return HasMany<CashTransaction, $this>
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(CashTransaction::class)->where('type', CashTransactionType::Expense)->whereNull('reversal_of_id');
    }

    /**
     * Add the realized amount (sum of expenses that were not cancelled) as `realized_amount`.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithRealizedAmount(Builder $query): void
    {
        $query->withSum(['expenses as realized_amount' => fn (Builder $query) => $query->whereNull('cancelled_at')], 'amount');
    }

    public function realizedAmount(): int
    {
        return $this->realized_amount ?? (int) $this->expenses()->whereNull('cancelled_at')->sum('amount');
    }

    public function remainingAmount(): int
    {
        return max(0, $this->total_amount - $this->realizedAmount());
    }

    /**
     * Recalculate the total from the items.
     */
    public function refreshTotal(): void
    {
        $this->update(['total_amount' => (int) $this->items()->sum('subtotal')]);
    }
}
