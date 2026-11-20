<?php

namespace App\Actions\Billing;

use App\Enums\BillStatus;
use App\Models\ActivityLog;
use App\Models\Bill;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Correct the description, amount or due date of a bill that has no payments yet.
 * The amount of an itemized (uniform) bill follows its items and cannot be changed here.
 */
class UpdateBill
{
    public function handle(Bill $bill, string $description, int $amount, ?CarbonInterface $dueDate): Bill
    {
        if ($bill->status === BillStatus::Cancelled || $bill->hasPayments()) {
            throw ValidationException::withMessages([
                'editAmount' => 'Hanya tagihan yang belum ada pembayaran yang bisa diubah.',
            ]);
        }

        if ($bill->items()->exists() && $amount !== $bill->amount) {
            throw ValidationException::withMessages([
                'editAmount' => 'Nominal tagihan seragam mengikuti item dan tidak bisa diubah.',
            ]);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages(['editAmount' => 'Nominal tagihan harus lebih dari nol.']);
        }

        if ($amount < $bill->discount_amount) {
            throw ValidationException::withMessages([
                'editAmount' => 'Nominal tagihan tidak boleh lebih kecil dari potongan yang sudah diberikan.',
            ]);
        }

        return DB::transaction(function () use ($bill, $description, $amount, $dueDate): Bill {
            $before = [
                'description' => $bill->description,
                'amount' => $bill->amount,
                'due_date' => $bill->due_date?->toDateString(),
            ];

            $bill->update([
                'description' => $description,
                'amount' => $amount,
                'due_date' => $dueDate?->toDateString(),
            ]);
            $bill->refreshPaymentStatus();

            $after = [
                'description' => $bill->description,
                'amount' => $bill->amount,
                'due_date' => $bill->due_date?->toDateString(),
            ];

            $changedFields = array_keys(array_diff_assoc($after, $before));

            if ($changedFields !== []) {
                ActivityLog::record('bill.updated', 'Mengubah tagihan '.$bill->description.' ('.$bill->student->name.')', $bill, [
                    'from' => array_intersect_key($before, array_flip($changedFields)),
                    'to' => array_intersect_key($after, array_flip($changedFields)),
                ]);
            }

            return $bill;
        });
    }
}
