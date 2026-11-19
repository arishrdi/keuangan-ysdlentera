<?php

namespace App\Actions\Payments;

use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\PaymentRejected;
use Illuminate\Validation\ValidationException;

/**
 * Refuse a submitted payment, e.g. when the proof does not match the bank statement.
 * The bills become payable again.
 */
class RejectPayment
{
    public function handle(Payment $payment, User $verifier, string $reason): Payment
    {
        if ($payment->status !== PaymentStatus::Pending) {
            throw ValidationException::withMessages(['rejectionReason' => 'Hanya pembayaran yang menunggu verifikasi yang bisa ditolak.']);
        }

        $payment->update([
            'status' => PaymentStatus::Rejected,
            'verified_by' => $verifier->id,
            'verified_at' => now(),
            'rejection_reason' => $reason,
        ]);

        ActivityLog::record('payment.rejected', 'Menolak pembayaran '.$payment->student->name.' sebesar Rp'.number_format($payment->amount, 0, ',', '.'), $payment, [
            'reason' => $reason,
        ]);

        $submitter = $payment->submitter;

        if ($submitter !== null && $submitter->hasRole(Role::Guardian)) {
            $submitter->notify(new PaymentRejected($payment));
        }

        return $payment;
    }
}
