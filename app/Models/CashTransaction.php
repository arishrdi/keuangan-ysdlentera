<?php

namespace App\Models;

use App\Enums\CashDirection;
use App\Enums\CashTransactionType;
use Database\Factories\CashTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One movement of money in or out of a cash account. Entries are never edited or deleted;
 * a cancellation adds an opposite entry that points back with `reversal_of_id`.
 *
 * @property int $id
 * @property string $number
 * @property int $level_id
 * @property int $cash_account_id
 * @property CashTransactionType $type
 * @property CashDirection $direction
 * @property int $amount
 * @property Carbon $transacted_on
 * @property string $description
 * @property string|null $counterparty
 * @property int|null $transaction_category_id
 * @property int|null $payment_id
 * @property int|null $related_transaction_id
 * @property int|null $reversal_of_id
 * @property string|null $proof_path
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by
 * @property string|null $cancellation_reason
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'number', 'level_id', 'cash_account_id', 'type', 'direction', 'amount', 'transacted_on', 'description', 'counterparty',
    'transaction_category_id', 'payment_id', 'related_transaction_id', 'reversal_of_id', 'proof_path',
    'cancelled_at', 'cancelled_by', 'cancellation_reason', 'created_by',
])]
class CashTransaction extends Model
{
    /** @use HasFactory<CashTransactionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CashTransactionType::class,
            'direction' => CashDirection::class,
            'amount' => 'integer',
            'transacted_on' => 'date',
            'cancelled_at' => 'datetime',
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
     * @return BelongsTo<CashAccount, $this>
     */
    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    /**
     * @return BelongsTo<TransactionCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(TransactionCategory::class, 'transaction_category_id');
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * The other leg of a transfer between accounts.
     *
     * @return BelongsTo<self, $this>
     */
    public function relatedTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'related_transaction_id');
    }

    /**
     * The opposite entry created when this transaction was cancelled.
     *
     * @return HasOne<self, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Original entries only, without the opposite entries added by cancellations.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOriginal(Builder $query): void
    {
        $query->whereNull('reversal_of_id');
    }

    /**
     * Original entries that were not cancelled.
     *
     * @param  Builder<self>  $query
     */
    public function scopeEffective(Builder $query): void
    {
        $query->whereNull('reversal_of_id')->whereNull('cancelled_at');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function isReversal(): bool
    {
        return $this->reversal_of_id !== null;
    }

    /**
     * The amount with its sign: positive for money in, negative for money out.
     */
    public function signedAmount(): int
    {
        return $this->direction->sign() * $this->amount;
    }
}
