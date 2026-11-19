<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Money received for one or more bills of a single student.
 *
 * @property int $id
 * @property int $student_id
 * @property int $level_id
 * @property string|null $receipt_number
 * @property PaymentMethod $method
 * @property int|null $payment_channel_id
 * @property int $amount
 * @property Carbon $paid_on
 * @property string|null $proof_path
 * @property string|null $notes
 * @property PaymentStatus $status
 * @property int|null $submitted_by
 * @property int|null $verified_by
 * @property Carbon|null $verified_at
 * @property string|null $rejection_reason
 * @property int|null $cancelled_by
 * @property Carbon|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'student_id', 'level_id', 'receipt_number', 'method', 'payment_channel_id', 'amount', 'paid_on', 'proof_path', 'notes',
    'status', 'submitted_by', 'verified_by', 'verified_at', 'rejection_reason', 'cancelled_by', 'cancelled_at', 'cancellation_reason',
])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'amount' => 'integer',
            'paid_on' => 'date',
            'verified_at' => 'datetime',
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
     * @return BelongsTo<Level, $this>
     */
    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    /**
     * @return BelongsTo<PaymentChannel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(PaymentChannel::class, 'payment_channel_id');
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * @return BelongsToMany<Bill, $this>
     */
    public function bills(): BelongsToMany
    {
        return $this->belongsToMany(Bill::class, 'payment_allocations')->withPivot('amount')->withTimestamps();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function hasReceipt(): bool
    {
        return $this->receipt_number !== null;
    }
}
