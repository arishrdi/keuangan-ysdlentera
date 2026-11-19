<?php

namespace App\Actions\Billing;

use App\Enums\BillStatus;
use App\Enums\PaymentStatus;
use App\Models\ActivityLog;
use App\Models\Bill;
use Illuminate\Validation\ValidationException;

/**
 * Cancel a bill that was issued by mistake. Bills with payments cannot be cancelled.
 */
class CancelBill
{
    public function handle(Bill $bill, string $reason): Bill
    {
        $hasPayments = $bill->allocations()
            ->whereHas('payment', fn ($query) => $query->whereIn('status', [PaymentStatus::Pending, PaymentStatus::Verified]))
            ->exists();

        if ($hasPayments || $bill->status === BillStatus::Cancelled) {
            throw ValidationException::withMessages([
                'cancellationReason' => 'Tagihan yang sudah memiliki pembayaran atau sudah dibatalkan tidak bisa dibatalkan.',
            ]);
        }

        $bill->update([
            'status' => BillStatus::Cancelled,
            'cancellation_reason' => $reason,
            'cancelled_at' => now(),
        ]);

        ActivityLog::record('bill.cancelled', 'Membatalkan tagihan '.$bill->description.' ('.$bill->student->name.')', $bill, ['reason' => $reason]);

        return $bill;
    }
}
