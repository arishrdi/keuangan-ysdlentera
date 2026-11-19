<?php

use App\Actions\Payments\SubmitPayment;
use App\Enums\BillStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Bill;
use App\Models\Level;
use App\Models\PaymentCategory;
use App\Models\PaymentChannel;
use App\Models\Student;
use App\Models\User;
use App\Notifications\PaymentSubmitted;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
});

it('stores a guardian transfer as waiting for verification and notifies treasurers', function () {
    $level = Level::factory()->sd()->create();
    $student = Student::factory()->for($level)->create();
    $guardian = User::factory()->guardian()->withStudents($student)->create();
    $treasurer = User::factory()->treasurer()->create();
    $channel = PaymentChannel::factory()->for($level)->create();
    $bill = Bill::factory()->forStudent($student)->for(PaymentCategory::factory()->spp())->create(['amount' => 250_000]);

    $payment = app(SubmitPayment::class)->handle(
        $guardian, $student, [$bill->id => 250_000], PaymentMethod::Transfer, CarbonImmutable::today(),
        $channel, UploadedFile::fake()->image('bukti.jpg'),
    );

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->receipt_number)->toBeNull()
        ->and($bill->refresh()->status)->toBe(BillStatus::Unpaid)
        ->and($bill->payableAmount())->toBe(0);

    Storage::disk('local')->assertExists($payment->proof_path);
    Notification::assertSentTo($treasurer, PaymentSubmitted::class);
});

it('verifies a cash payment recorded by the treasurer at once and numbers its receipt', function () {
    $this->travelTo('2026-11-19 10:00');
    $level = Level::factory()->sd()->create();
    $student = Student::factory()->for($level)->create();
    $bill = Bill::factory()->forStudent($student)->for(PaymentCategory::factory()->spp())->create(['amount' => 250_000]);

    $payment = app(SubmitPayment::class)->handle(
        User::factory()->treasurer()->create(), $student, [$bill->id => 250_000], PaymentMethod::Cash, CarbonImmutable::today(),
    );

    expect($payment->status)->toBe(PaymentStatus::Verified)
        ->and($payment->receipt_number)->toBe('KWT/SD/2026/0001')
        ->and($bill->refresh()->status)->toBe(BillStatus::Paid)
        ->and($bill->paid_amount)->toBe(250_000);
});

it('accepts a partial payment of at least Rp10.000 on an installable bill', function () {
    $student = Student::factory()->create();
    $bill = Bill::factory()->forStudent($student)->for(PaymentCategory::factory()->dpp())->create(['amount' => 3_000_000]);

    app(SubmitPayment::class)->handle(
        User::factory()->treasurer()->create(), $student, [$bill->id => 500_000], PaymentMethod::Cash, CarbonImmutable::today(),
    );

    expect($bill->refresh()->status)->toBe(BillStatus::Partial)
        ->and($bill->remainingAmount())->toBe(2_500_000);
});

it('rejects invalid amounts', function (string $categoryState, int $billAmount, int $paid, string $message) {
    $student = Student::factory()->create();
    $bill = Bill::factory()->forStudent($student)->for(PaymentCategory::factory()->{$categoryState}())->create(['amount' => $billAmount]);

    expect(fn () => app(SubmitPayment::class)->handle(
        User::factory()->treasurer()->create(), $student, [$bill->id => $paid], PaymentMethod::Cash, CarbonImmutable::today(),
    ))->toThrow(ValidationException::class, $message);
})->with([
    'partial payment of a non-installable bill' => ['spp', 250_000, 100_000, 'harus dibayar lunas'],
    'more than what is owed' => ['dpp', 3_000_000, 3_500_000, 'melebihi sisa tagihan'],
    'installment below the minimum' => ['dpp', 3_000_000, 5_000, 'Cicilan minimal Rp10.000.'],
]);

it('does not let an amount already waiting for verification be paid twice', function () {
    $level = Level::factory()->sd()->create();
    $student = Student::factory()->for($level)->create();
    $guardian = User::factory()->guardian()->withStudents($student)->create();
    $channel = PaymentChannel::factory()->for($level)->create();
    $bill = Bill::factory()->forStudent($student)->for(PaymentCategory::factory()->spp())->create(['amount' => 250_000]);
    $submit = fn () => app(SubmitPayment::class)->handle(
        $guardian, $student, [$bill->id => 250_000], PaymentMethod::Transfer, CarbonImmutable::today(), $channel, UploadedFile::fake()->image('bukti.jpg'),
    );

    $submit();

    expect($submit)->toThrow(ValidationException::class, 'sudah lunas atau sedang menunggu verifikasi');
});

it('refuses payments from a guardian who is not linked to the student', function () {
    $level = Level::factory()->sd()->create();
    $student = Student::factory()->for($level)->create();
    $bill = Bill::factory()->forStudent($student)->create();

    app(SubmitPayment::class)->handle(
        User::factory()->guardian()->create(), $student, [$bill->id => $bill->amount], PaymentMethod::Transfer, CarbonImmutable::today(),
        PaymentChannel::factory()->for($level)->create(), UploadedFile::fake()->image('bukti.jpg'),
    );
})->throws(ValidationException::class, 'Anda tidak berhak membayar tagihan siswa ini.');

it('refuses cash payments from guardians', function () {
    $student = Student::factory()->create();
    $guardian = User::factory()->guardian()->withStudents($student)->create();
    $bill = Bill::factory()->forStudent($student)->create();

    app(SubmitPayment::class)->handle($guardian, $student, [$bill->id => $bill->amount], PaymentMethod::Cash, CarbonImmutable::today());
})->throws(ValidationException::class, 'Pembayaran tunai dicatat oleh bendahara di sekolah.');

it('requires a channel of the student level and proof from guardians', function (bool $otherLevelChannel, bool $withProof, string $message) {
    $level = Level::factory()->sd()->create();
    $student = Student::factory()->for($level)->create();
    $guardian = User::factory()->guardian()->withStudents($student)->create();
    $bill = Bill::factory()->forStudent($student)->create();
    $channel = PaymentChannel::factory()->for($otherLevelChannel ? Level::factory()->smp()->create() : $level)->create();

    expect(fn () => app(SubmitPayment::class)->handle(
        $guardian, $student, [$bill->id => $bill->amount], PaymentMethod::Transfer, CarbonImmutable::today(),
        $channel, $withProof ? UploadedFile::fake()->image('bukti.jpg') : null,
    ))->toThrow(ValidationException::class, $message);
})->with([
    'channel of another level' => [true, true, 'Pilih rekening atau QRIS tujuan yang sesuai jenjang siswa.'],
    'missing proof' => [false, false, 'Unggah bukti pembayaran.'],
]);

it('refuses bills of another student', function () {
    $student = Student::factory()->create();
    $otherBill = Bill::factory()->create();

    app(SubmitPayment::class)->handle(
        User::factory()->treasurer()->create(), $student, [$otherBill->id => $otherBill->amount], PaymentMethod::Cash, CarbonImmutable::today(),
    );
})->throws(ValidationException::class, 'Pilih minimal satu tagihan milik siswa ini.');
