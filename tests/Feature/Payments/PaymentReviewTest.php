<?php

use App\Actions\Payments\CancelPayment;
use App\Actions\Payments\RejectPayment;
use App\Actions\Payments\VerifyPayment;
use App\Enums\BillStatus;
use App\Enums\PaymentStatus;
use App\Models\Bill;
use App\Models\Level;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Notifications\PaymentRejected;
use App\Notifications\PaymentVerified;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * A guardian's pending payment covering the whole bill.
 */
function pendingGuardianPayment(?Level $level = null, int $amount = 250_000): Payment
{
    $student = Student::factory()->for($level ?? Level::factory()->sd()->create())->create();
    $guardian = User::factory()->guardian()->withStudents($student)->create();
    $bill = Bill::factory()->forStudent($student)->create(['amount' => $amount]);
    $payment = Payment::factory()->for($student)->create(['amount' => $amount, 'submitted_by' => $guardian->id, 'status' => PaymentStatus::Pending]);
    $payment->allocations()->create(['bill_id' => $bill->id, 'amount' => $amount]);

    return $payment;
}

beforeEach(fn () => Notification::fake());

it('verifies a pending payment, marks the bill paid and notifies the guardian', function () {
    $payment = pendingGuardianPayment();
    $treasurer = User::factory()->treasurer()->create();

    app(VerifyPayment::class)->handle($payment, $treasurer);

    $payment->refresh();

    expect($payment->status)->toBe(PaymentStatus::Verified)
        ->and($payment->verified_by)->toBe($treasurer->id)
        ->and($payment->bills()->sole()->status)->toBe(BillStatus::Paid);

    Notification::assertSentTo($payment->submitter, PaymentVerified::class);
});

it('numbers receipts per level and restarts every year', function () {
    $sd = Level::factory()->sd()->create();
    $smp = Level::factory()->smp()->create();
    $treasurer = User::factory()->treasurer()->create();

    $this->travelTo('2026-12-31 10:00');
    $first = app(VerifyPayment::class)->handle(pendingGuardianPayment($sd), $treasurer);
    $second = app(VerifyPayment::class)->handle(pendingGuardianPayment($sd), $treasurer);
    $otherLevel = app(VerifyPayment::class)->handle(pendingGuardianPayment($smp), $treasurer);

    $this->travelTo('2027-01-02 10:00');
    $nextYear = app(VerifyPayment::class)->handle(pendingGuardianPayment($sd), $treasurer);

    expect([$first->receipt_number, $second->receipt_number, $otherLevel->receipt_number, $nextYear->receipt_number])
        ->toBe(['KWT/SD/2026/0001', 'KWT/SD/2026/0002', 'KWT/SMP/2026/0001', 'KWT/SD/2027/0001']);
});

it('refuses to verify a payment twice', function () {
    $payment = pendingGuardianPayment();
    $treasurer = User::factory()->treasurer()->create();
    app(VerifyPayment::class)->handle($payment, $treasurer);

    app(VerifyPayment::class)->handle($payment->refresh(), $treasurer);
})->throws(ValidationException::class);

it('refuses to verify a payment that now exceeds the remaining amount', function () {
    $payment = pendingGuardianPayment();
    $payment->bills()->sole()->update(['discount_amount' => 100_000]);

    app(VerifyPayment::class)->handle($payment, User::factory()->treasurer()->create());
})->throws(ValidationException::class, 'melebihi sisa tagihan');

it('rejects a payment with a reason so the bill can be paid again', function () {
    $payment = pendingGuardianPayment();

    app(RejectPayment::class)->handle($payment, User::factory()->treasurer()->create(), 'Dana belum masuk');

    $bill = $payment->bills()->sole();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Rejected)
        ->and($payment->rejection_reason)->toBe('Dana belum masuk')
        ->and($bill->payableAmount())->toBe(250_000);

    Notification::assertSentTo($payment->submitter, PaymentRejected::class);
});

it('cancels a verified payment, keeps its receipt number and reopens the bill', function () {
    $payment = pendingGuardianPayment();
    $treasurer = User::factory()->treasurer()->create();
    app(VerifyPayment::class)->handle($payment, $treasurer);

    app(CancelPayment::class)->handle($payment->refresh(), $treasurer, 'Salah catat');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Cancelled)
        ->and($payment->receipt_number)->not->toBeNull()
        ->and($payment->bills()->sole()->status)->toBe(BillStatus::Unpaid);
});

it('only cancels verified payments', function () {
    app(CancelPayment::class)->handle(pendingGuardianPayment(), User::factory()->treasurer()->create(), 'Salah');
})->throws(ValidationException::class);
