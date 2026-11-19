<?php

namespace App\Models;

use App\Enums\BillStatus;
use App\Enums\PaymentStatus;
use Database\Factories\BillFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A charge (tagihan) owed by one student.
 *
 * @property int $id
 * @property int $student_id
 * @property int $level_id
 * @property int $academic_year_id
 * @property int $payment_category_id
 * @property Carbon|null $period
 * @property string $description
 * @property int $amount
 * @property int $discount_amount
 * @property string|null $discount_reason
 * @property int $paid_amount
 * @property Carbon|null $due_date
 * @property BillStatus $status
 * @property string|null $cancellation_reason
 * @property Carbon|null $cancelled_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int|null $pending_amount
 */
#[Fillable([
    'student_id', 'level_id', 'academic_year_id', 'payment_category_id', 'period', 'description', 'amount',
    'discount_amount', 'discount_reason', 'paid_amount', 'due_date', 'status', 'cancellation_reason', 'cancelled_at', 'created_by',
])]
class Bill extends Model
{
    /** @use HasFactory<BillFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period' => 'date',
            'due_date' => 'date',
            'cancelled_at' => 'datetime',
            'amount' => 'integer',
            'discount_amount' => 'integer',
            'paid_amount' => 'integer',
            'status' => BillStatus::class,
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
     * @return BelongsTo<Level, $this>
     */
    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    /**
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * @return BelongsTo<PaymentCategory, $this>
     */
    public function paymentCategory(): BelongsTo
    {
        return $this->belongsTo(PaymentCategory::class);
    }

    /**
     * @return HasMany<BillItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(BillItem::class);
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOutstanding(Builder $query): void
    {
        $query->whereIn('status', BillStatus::outstanding());
    }

    /**
     * Add the sum of allocations from payments still awaiting verification as `pending_amount`.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithPendingAmount(Builder $query): void
    {
        $query->withSum([
            'allocations as pending_amount' => fn (Builder $query) => $query->whereHas(
                'payment',
                fn (Builder $query) => $query->where('status', PaymentStatus::Pending),
            ),
        ], 'amount');
    }

    /**
     * The amount owed after discount.
     */
    public function netAmount(): int
    {
        return max(0, $this->amount - $this->discount_amount);
    }

    /**
     * The amount still unpaid, ignoring payments awaiting verification.
     */
    public function remainingAmount(): int
    {
        return $this->status === BillStatus::Cancelled ? 0 : max(0, $this->netAmount() - $this->paid_amount);
    }

    /**
     * The amount that can still be submitted, after subtracting payments awaiting verification.
     */
    public function payableAmount(): int
    {
        $pending = $this->pending_amount ?? (int) $this->allocations()
            ->whereHas('payment', fn (Builder $query) => $query->where('status', PaymentStatus::Pending))
            ->sum('amount');

        return max(0, $this->remainingAmount() - (int) $pending);
    }

    public function isInstallable(): bool
    {
        return $this->paymentCategory->is_installable;
    }

    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && $this->due_date->isPast()
            && in_array($this->status, BillStatus::outstanding(), true);
    }

    /**
     * Recalculate the paid amount and status from verified payments.
     */
    public function refreshPaymentStatus(): void
    {
        if ($this->status === BillStatus::Cancelled) {
            return;
        }

        $paid = (int) $this->allocations()
            ->whereHas('payment', fn (Builder $query) => $query->where('status', PaymentStatus::Verified))
            ->sum('amount');

        $this->update([
            'paid_amount' => $paid,
            'status' => match (true) {
                $paid >= $this->netAmount() => BillStatus::Paid,
                $paid > 0 => BillStatus::Partial,
                default => BillStatus::Unpaid,
            },
        ]);
    }
}
