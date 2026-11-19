<?php

namespace App\Models;

use App\Enums\CashDirection;
use App\Enums\SavingsTransactionType;
use Database\Factories\SavingsTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One deposit, withdrawal or opening balance in a student's savings. Savings are money held in trust
 * for the student and are kept apart from the school's cash book.
 *
 * @property int $id
 * @property string $number
 * @property int $student_id
 * @property int $level_id
 * @property SavingsTransactionType $type
 * @property CashDirection $direction
 * @property int $amount
 * @property Carbon $transacted_on
 * @property string|null $notes
 * @property int|null $reversal_of_id
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by
 * @property string|null $cancellation_reason
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'number', 'student_id', 'level_id', 'type', 'direction', 'amount', 'transacted_on', 'notes',
    'reversal_of_id', 'cancelled_at', 'cancelled_by', 'cancellation_reason', 'created_by',
])]
class SavingsTransaction extends Model
{
    /** @use HasFactory<SavingsTransactionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SavingsTransactionType::class,
            'direction' => CashDirection::class,
            'amount' => 'integer',
            'transacted_on' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasOne<self, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function isReversal(): bool
    {
        return $this->reversal_of_id !== null;
    }

    public function signedAmount(): int
    {
        return $this->direction->sign() * $this->amount;
    }
}
