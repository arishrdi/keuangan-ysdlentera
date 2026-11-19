<?php

use App\Actions\Payments\VerifyPayment;
use App\Enums\PaymentStatus;
use App\Models\Bill;
use App\Models\Level;
use App\Models\Payment;
use App\Models\PaymentChannel;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * A verified payment for a student with a linked guardian.
 *
 * @return array{payment: Payment, guardian: User}
 */
function verifiedPaymentWithGuardian(): array
{
    Notification::fake();
    $student = Student::factory()->for(Level::factory()->sd())->create();
    $guardian = User::factory()->guardian()->withStudents($student)->create();
    $bill = Bill::factory()->forStudent($student)->create(['amount' => 100_000]);
    $payment = Payment::factory()->for($student)->create(['amount' => 100_000, 'status' => PaymentStatus::Pending, 'submitted_by' => $guardian->id]);
    $payment->allocations()->create(['bill_id' => $bill->id, 'amount' => 100_000]);

    return ['payment' => app(VerifyPayment::class)->handle($payment, User::factory()->treasurer()->create()), 'guardian' => $guardian];
}

it('lets the linked guardian download the receipt as a PDF', function () {
    ['payment' => $payment, 'guardian' => $guardian] = verifiedPaymentWithGuardian();

    $this->actingAs($guardian)
        ->get(route('payments.receipt', $payment))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('lets finance staff open receipts', function (string $state) {
    ['payment' => $payment] = verifiedPaymentWithGuardian();

    $this->actingAs(User::factory()->{$state}()->create())
        ->get(route('payments.receipt', $payment))
        ->assertOk();
})->with(['treasurer', 'principal', 'foundation']);

it('forbids receipts of other families', function () {
    ['payment' => $payment] = verifiedPaymentWithGuardian();

    $this->actingAs(User::factory()->guardian()->create())
        ->get(route('payments.receipt', $payment))
        ->assertForbidden();
});

it('has no receipt before verification', function () {
    $student = Student::factory()->create();
    $payment = Payment::factory()->for($student)->create(['status' => PaymentStatus::Pending]);

    $this->actingAs(User::factory()->treasurer()->create())
        ->get(route('payments.receipt', $payment))
        ->assertNotFound();
});

it('shows the uploaded proof only to staff and the linked guardian', function () {
    Storage::fake('local');
    $student = Student::factory()->create();
    $guardian = User::factory()->guardian()->withStudents($student)->create();
    Storage::disk('local')->put('payment-proofs/bukti.jpg', 'gambar');
    $payment = Payment::factory()->for($student)->create(['proof_path' => 'payment-proofs/bukti.jpg']);

    $this->actingAs($guardian)->get(route('payments.proof', $payment))->assertOk();
    $this->actingAs(User::factory()->treasurer()->create())->get(route('payments.proof', $payment))->assertOk();
    $this->actingAs(User::factory()->guardian()->create())->get(route('payments.proof', $payment))->assertForbidden();
});

it('serves the QRIS image of a payment channel', function () {
    Storage::fake('local');
    Storage::disk('local')->put('qris/sd.png', 'qris');
    $channel = PaymentChannel::factory()->qris()->create(['qris_image_path' => 'qris/sd.png']);

    $this->actingAs(User::factory()->guardian()->create())
        ->get(route('payment-channels.qris', $channel))
        ->assertOk();
});
