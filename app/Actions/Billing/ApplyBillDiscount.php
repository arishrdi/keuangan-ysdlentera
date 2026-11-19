<?php

namespace App\Actions\Billing;

use App\Enums\BillStatus;
use App\Models\ActivityLog;
use App\Models\Bill;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Set the discount (keringanan) on a bill, e.g. for a scholarship or orphan exemption.
 */
class ApplyBillDiscount
{
    public function handle(Bill $bill, int $discountAmount, string $reason): Bill
    {
        if ($bill->status === BillStatus::Cancelled) {
            throw ValidationException::withMessages(['discountAmount' => 'Tagihan yang dibatalkan tidak bisa diberi potongan.']);
        }

        if ($discountAmount < 0 || $discountAmount > $bill->amount - $bill->paid_amount) {
            throw ValidationException::withMessages([
                'discountAmount' => 'Potongan maksimal sebesar sisa tagihan yang belum dibayar.',
            ]);
        }

        return DB::transaction(function () use ($bill, $discountAmount, $reason): Bill {
            $previousDiscount = $bill->discount_amount;

            $bill->update(['discount_amount' => $discountAmount, 'discount_reason' => $reason]);
            $bill->refreshPaymentStatus();

            ActivityLog::record('bill.discounted', 'Memberi potongan tagihan '.$bill->description.' ('.$bill->student->name.')', $bill, [
                'from' => $previousDiscount,
                'to' => $discountAmount,
                'reason' => $reason,
            ]);

            return $bill;
        });
    }
}
