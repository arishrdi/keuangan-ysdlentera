<?php

use App\Actions\Billing\ApplyBillDiscount;
use App\Actions\Billing\CancelBill;
use App\Enums\BillStatus;
use App\Enums\PaymentStatus;
use App\Models\ActivityLog;
use App\Models\Bill;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Validation\ValidationException;

it('reduces what is owed and records the reason', function () {
    $this->actingAs(User::factory()->treasurer()->create());
    $bill = Bill::factory()->create(['amount' => 300_000]);

    app(ApplyBillDiscount::class)->handle($bill, 100_000, 'Beasiswa yatim');

    expect($bill->refresh()->netAmount())->toBe(200_000)
        ->and($bill->remainingAmount())->toBe(200_000)
        ->and($bill->status)->toBe(BillStatus::Unpaid)
        ->and(ActivityLog::query()->where('action', 'bill.discounted')->sole()->properties)
        ->toMatchArray(['from' => 0, 'to' => 100_000, 'reason' => 'Beasiswa yatim']);
});

it('marks a bill as paid when the discount covers the rest', function () {
    $bill = Bill::factory()->create(['amount' => 300_000, 'paid_amount' => 100_000, 'status' => BillStatus::Partial]);
    Payment::factory()->for($bill->student)->create(['status' => PaymentStatus::Verified, 'amount' => 100_000])
        ->allocations()->create(['bill_id' => $bill->id, 'amount' => 100_000]);

    app(ApplyBillDiscount::class)->handle($bill, 200_000, 'Pembebasan');

    expect($bill->refresh()->status)->toBe(BillStatus::Paid);
});

it('rejects a discount larger than the unpaid part', function () {
    $bill = Bill::factory()->create(['amount' => 300_000, 'paid_amount' => 100_000, 'status' => BillStatus::Partial]);

    app(ApplyBillDiscount::class)->handle($bill, 250_000, 'Terlalu besar');
})->throws(ValidationException::class, 'Potongan maksimal sebesar sisa tagihan yang belum dibayar.');

it('cancels an unpaid bill with a reason', function () {
    $bill = Bill::factory()->create();

    app(CancelBill::class)->handle($bill, 'Salah terbit');

    expect($bill->refresh()->status)->toBe(BillStatus::Cancelled)
        ->and($bill->cancellation_reason)->toBe('Salah terbit')
        ->and($bill->remainingAmount())->toBe(0);
});

it('refuses to cancel a bill that has a payment waiting for verification', function () {
    $bill = Bill::factory()->create();
    Payment::factory()->for($bill->student)->create(['status' => PaymentStatus::Pending])
        ->allocations()->create(['bill_id' => $bill->id, 'amount' => 10_000]);

    app(CancelBill::class)->handle($bill, 'Salah terbit');
})->throws(ValidationException::class);
