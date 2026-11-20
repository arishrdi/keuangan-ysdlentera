<?php

use App\Actions\Billing\ApplyBillDiscount;
use App\Actions\Billing\CancelBill;
use App\Actions\Billing\UpdateBill;
use App\Enums\BillStatus;
use App\Enums\PaymentStatus;
use App\Models\ActivityLog;
use App\Models\Bill;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Carbon;
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

it('corrects an unpaid bill and logs what changed', function () {
    $this->actingAs(User::factory()->treasurer()->create());
    $bill = Bill::factory()->create(['description' => 'SPP Juli', 'amount' => 150_000, 'due_date' => '2026-07-10']);

    app(UpdateBill::class)->handle($bill, 'SPP Juli 2026', 175_000, Carbon::parse('2026-07-15'));

    $bill->refresh();

    expect($bill->description)->toBe('SPP Juli 2026')
        ->and($bill->amount)->toBe(175_000)
        ->and($bill->due_date->toDateString())->toBe('2026-07-15')
        ->and(ActivityLog::query()->where('action', 'bill.updated')->sole()->properties)
        ->toMatchArray([
            'from' => ['description' => 'SPP Juli', 'amount' => 150_000, 'due_date' => '2026-07-10'],
            'to' => ['description' => 'SPP Juli 2026', 'amount' => 175_000, 'due_date' => '2026-07-15'],
        ]);
});

it('refuses to change a bill that has a payment waiting for verification', function () {
    $bill = Bill::factory()->create(['amount' => 150_000]);
    Payment::factory()->for($bill->student)->create(['status' => PaymentStatus::Pending])
        ->allocations()->create(['bill_id' => $bill->id, 'amount' => 10_000]);

    app(UpdateBill::class)->handle($bill, $bill->description, 200_000, null);
})->throws(ValidationException::class, 'Hanya tagihan yang belum ada pembayaran yang bisa diubah.');

it('refuses an amount below the existing discount', function () {
    $bill = Bill::factory()->create(['amount' => 300_000, 'discount_amount' => 100_000, 'discount_reason' => 'Yatim']);

    app(UpdateBill::class)->handle($bill, $bill->description, 50_000, null);
})->throws(ValidationException::class, 'Nominal tagihan tidak boleh lebih kecil dari potongan yang sudah diberikan.');

it('keeps the amount of an itemized uniform bill', function () {
    $bill = Bill::factory()->create(['amount' => 120_000]);
    $bill->items()->create(['name' => 'Kemeja', 'size' => 'M', 'quantity' => 1, 'unit_price' => 120_000, 'subtotal' => 120_000]);

    app(UpdateBill::class)->handle($bill, $bill->description, 150_000, null);
})->throws(ValidationException::class, 'Nominal tagihan seragam mengikuti item dan tidak bisa diubah.');
