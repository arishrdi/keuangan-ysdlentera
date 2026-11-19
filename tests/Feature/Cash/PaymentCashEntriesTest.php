<?php

use App\Actions\Payments\CancelPayment;
use App\Actions\Payments\SubmitPayment;
use App\Actions\Payments\VerifyPayment;
use App\Enums\CashTransactionType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Bill;
use App\Models\CashAccount;
use App\Models\Level;
use App\Models\Payment;
use App\Models\PaymentChannel;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

beforeEach(fn () => Notification::fake());

it('puts a cash payment into the level cash box', function () {
    $level = Level::factory()->sd()->create();
    $student = Student::factory()->for($level)->create();
    $bill = Bill::factory()->forStudent($student)->create(['amount' => 250_000]);

    $payment = app(SubmitPayment::class)->handle(User::factory()->treasurer()->create(), $student, [$bill->id => 250_000], PaymentMethod::Cash, CarbonImmutable::parse('2026-11-10'));

    $entry = $payment->cashTransaction;

    expect($entry->type)->toBe(CashTransactionType::StudentPayment)
        ->and($entry->cashAccount->is(CashAccount::cashBoxFor($level)))->toBeTrue()
        ->and($entry->amount)->toBe(250_000)
        ->and($entry->transacted_on->toDateString())->toBe('2026-11-10');
});

it('puts a verified transfer into the bank account behind the channel', function () {
    $level = Level::factory()->sd()->create();
    $student = Student::factory()->for($level)->create();
    $channel = PaymentChannel::factory()->for($level)->create();
    $bill = Bill::factory()->forStudent($student)->create(['amount' => 100_000]);
    $payment = Payment::factory()->for($student)->create(['amount' => 100_000, 'method' => PaymentMethod::Transfer, 'payment_channel_id' => $channel->id]);
    $payment->allocations()->create(['bill_id' => $bill->id, 'amount' => 100_000]);

    app(VerifyPayment::class)->handle($payment, User::factory()->treasurer()->create());

    expect($channel->cashAccount->balance())->toBe(100_000);
});

it('refuses to verify a transfer whose channel has no cash account and keeps it pending', function () {
    $level = Level::factory()->sd()->create();
    $student = Student::factory()->for($level)->create();
    $channel = PaymentChannel::factory()->for($level)->create(['cash_account_id' => null]);
    $bill = Bill::factory()->forStudent($student)->create(['amount' => 100_000]);
    $payment = Payment::factory()->for($student)->create(['amount' => 100_000, 'method' => PaymentMethod::Transfer, 'payment_channel_id' => $channel->id]);
    $payment->allocations()->create(['bill_id' => $bill->id, 'amount' => 100_000]);

    expect(fn () => app(VerifyPayment::class)->handle($payment, User::factory()->treasurer()->create()))
        ->toThrow(ValidationException::class, 'belum dihubungkan ke akun kas');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending)
        ->and($payment->receipt_number)->toBeNull();
});

it('reverses the cash entry when a payment is cancelled', function () {
    $level = Level::factory()->sd()->create();
    $student = Student::factory()->for($level)->create();
    $bill = Bill::factory()->forStudent($student)->create(['amount' => 250_000]);
    $treasurer = User::factory()->treasurer()->create();
    $payment = app(SubmitPayment::class)->handle($treasurer, $student, [$bill->id => 250_000], PaymentMethod::Cash, CarbonImmutable::today());

    app(CancelPayment::class)->handle($payment, $treasurer, 'Salah siswa');

    expect(CashAccount::cashBoxFor($level)->balance())->toBe(0)
        ->and($payment->cashTransaction->refresh()->isCancelled())->toBeTrue();
});
