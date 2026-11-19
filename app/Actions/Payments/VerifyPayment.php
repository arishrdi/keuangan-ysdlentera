<?php

namespace App\Actions\Payments;

use App\Actions\Cash\CashLedger;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Bill;
use App\Models\DocumentSequence;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\PaymentVerified;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Accept a payment: assign its receipt number and update the paid bills.
 */
class VerifyPayment
{
    public function __construct(private CashLedger $cashLedger) {}

    public function handle(Payment $payment, User $verifier): Payment
    {
        $payment = DB::transaction(function () use ($payment, $verifier): Payment {
            $payment = Payment::query()->with('allocations', 'level')->lockForUpdate()->findOrFail($payment->id);

            if ($payment->status !== PaymentStatus::Pending) {
                throw ValidationException::withMessages(['payment' => 'Hanya pembayaran yang menunggu verifikasi yang bisa diverifikasi.']);
            }

            $bills = Bill::query()->whereKey($payment->allocations->pluck('bill_id'))->lockForUpdate()->get()->keyBy('id');

            foreach ($payment->allocations as $allocation) {
                if ($allocation->amount > $bills[$allocation->bill_id]->remainingAmount()) {
                    throw ValidationException::withMessages([
                        'payment' => 'Nominal pembayaran melebihi sisa tagihan '.$bills[$allocation->bill_id]->description.'. Tolak pembayaran ini.',
                    ]);
                }
            }

            $verifiedAt = now();

            $payment->update([
                'status' => PaymentStatus::Verified,
                'verified_by' => $verifier->id,
                'verified_at' => $verifiedAt,
                'receipt_number' => DocumentSequence::next(DocumentSequence::RECEIPT, $payment->level, $verifiedAt->year),
            ]);

            $bills->each->refreshPaymentStatus();

            $this->cashLedger->recordStudentPayment($payment);

            ActivityLog::record('payment.verified', 'Memverifikasi pembayaran '.$payment->receipt_number, $payment, ['amount' => $payment->amount]);

            return $payment;
        });

        $submitter = $payment->submitter;

        if ($submitter !== null && $submitter->hasRole(Role::Guardian)) {
            $submitter->notify(new PaymentVerified($payment));
        }

        return $payment;
    }
}
