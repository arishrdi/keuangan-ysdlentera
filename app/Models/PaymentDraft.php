<?php

namespace App\Models;

use App\Enums\BillStatus;
use Database\Factories\PaymentDraftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A payment a guardian saved to finish later: the chosen bills and amounts, without proof yet.
 *
 * Drafts never hold bills back. Bills paid or submitted elsewhere drop out of the draft the next time it is read.
 *
 * @property int $id
 * @property int $user_id
 * @property int $student_id
 * @property int|null $payment_channel_id
 * @property array<int, int> $allocations Amounts keyed by bill id.
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'student_id', 'payment_channel_id', 'allocations', 'notes'])]
class PaymentDraft extends Model
{
    /** @use HasFactory<PaymentDraftFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allocations' => 'array',
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
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<PaymentChannel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(PaymentChannel::class, 'payment_channel_id');
    }

    public function total(): int
    {
        return array_sum($this->allocations);
    }

    /**
     * Drop bills that can no longer be paid and lower amounts above what is still payable.
     * Deletes the draft when nothing is left and returns whether it still exists.
     */
    public function syncWithBills(): bool
    {
        $bills = Bill::query()
            ->with('paymentCategory')
            ->withPendingAmount()
            ->whereKey(array_keys($this->allocations))
            ->where('student_id', $this->student_id)
            ->get()
            ->keyBy('id');

        $allocations = [];

        foreach ($this->allocations as $billId => $amount) {
            $bill = $bills->get((int) $billId);
            $payable = $bill?->payableAmount() ?? 0;

            if ($bill === null || $bill->status === BillStatus::Cancelled || $payable <= 0) {
                continue;
            }

            $allocations[(int) $billId] = $bill->isInstallable() ? min((int) $amount, $payable) : $payable;
        }

        if ($allocations === []) {
            $this->delete();

            return false;
        }

        if ($allocations !== array_map('intval', $this->allocations)) {
            $this->update(['allocations' => $allocations]);
        }

        return true;
    }
}
