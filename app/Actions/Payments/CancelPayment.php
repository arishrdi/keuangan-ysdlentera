<?php

namespace App\Actions\Payments;

use App\Actions\Cash\CashLedger;
use App\Enums\PaymentStatus;
use App\Models\ActivityLog;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Void a verified payment that was recorded by mistake. The record and its receipt number are kept,
 * the receipt is marked as cancelled and the bills become unpaid again.
 */
class CancelPayment
{
    public function __construct(private CashLedger $cashLedger) {}

    public function handle(Payment $payment, User $actor, string $reason): Payment
    {
        if ($payment->status !== PaymentStatus::Verified) {
            throw ValidationException::withMessages(['cancellationReason' => 'Hanya pembayaran terverifikasi yang bisa dibatalkan.']);
        }

        return DB::transaction(function () use ($payment, $actor, $reason): Payment {
            $payment->update([
                'status' => PaymentStatus::Cancelled,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            $payment->bills->each->refreshPaymentStatus();

            if ($payment->cashTransaction !== null) {
                $this->cashLedger->cancel($payment->cashTransaction, $actor, 'Pembayaran '.$payment->receipt_number.' dibatalkan: '.$reason, fromPayment: true);
            }

            ActivityLog::record('payment.cancelled', 'Membatalkan pembayaran '.$payment->receipt_number, $payment, ['reason' => $reason]);

            return $payment;
        });
    }
}
