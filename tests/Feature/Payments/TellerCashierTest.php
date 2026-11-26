<?php

use App\Actions\Payments\SubmitPayment;
use App\Enums\BillStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Bill;
use App\Models\Level;
use App\Models\Payment;
use App\Models\PaymentCategory;
use App\Models\PaymentChannel;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

it('opens the cashier page for tellers only', function () {
    $this->actingAs(User::factory()->teller()->create())->withSession([Level::SESSION_KEY => Level::ALL])->get(route('teller.index'))->assertOk();
});

it('forbids the cashier page for other roles', function (string $state) {
    $this->actingAs(User::factory()->{$state}()->create())->withSession([Level::SESSION_KEY => Level::ALL])->get(route('teller.index'))->assertForbidden();
})->with(['treasurer', 'admin', 'foundation', 'guardian']);

it('keeps tellers out of the treasurer billing page where discounts are given', function () {
    $this->actingAs(User::factory()->teller()->create())
        ->withSession([Level::SESSION_KEY => Level::ALL])
        ->get(route('billing.student', Student::factory()->create()))
        ->assertForbidden();
});

it('records a cash payment that is verified at once with a receipt', function () {
    $this->travelTo('2026-11-26 09:00');
    $level = Level::factory()->sd()->create();
    $student = Student::factory()->for($level)->create(['name' => 'Ahmad Kasir']);
    $bill = Bill::factory()->forStudent($student)->for(PaymentCategory::factory()->spp())->create(['amount' => 150_000]);
    $teller = User::factory()->teller()->forLevel($level)->create();
    $this->actingAs($teller);

    Livewire::test('pages::teller.index')
        ->set('search', 'Ahmad')
        ->assertSee('Ahmad Kasir')
        ->call('selectStudent', $student->id)
        ->call('toggleBill', $bill->id)
        ->call('recordPayment')
        ->assertHasNoErrors()
        ->assertDispatched('open-receipt');

    $payment = Payment::query()->sole();

    expect($payment->status)->toBe(PaymentStatus::Verified)
        ->and($payment->method)->toBe(PaymentMethod::Cash)
        ->and($payment->verified_by)->toBe($teller->id)
        ->and($payment->receipt_number)->toBe('KWT/SD/2026/0001')
        ->and($bill->refresh()->status)->toBe(BillStatus::Paid);

    $this->get(route('payments.receipt', $payment))->assertOk();
});

it('finds only students of the teller level', function () {
    $sd = Level::factory()->sd()->create();
    Student::factory()->for($sd)->create(['name' => 'Budi SD']);
    Student::factory()->for(Level::factory()->smp())->create(['name' => 'Budi SMP']);
    $this->actingAs(User::factory()->teller()->forLevel($sd)->create());

    Livewire::test('pages::teller.index')
        ->set('search', 'Budi')
        ->assertSee('Budi SD')
        ->assertDontSee('Budi SMP');
});

it('refuses non-cash payments from a teller', function () {
    $level = Level::factory()->sd()->create();
    $student = Student::factory()->for($level)->create();
    $bill = Bill::factory()->forStudent($student)->create(['amount' => 100_000]);

    expect(fn () => app(SubmitPayment::class)->handle(
        User::factory()->teller()->create(), $student, [$bill->id => 100_000], PaymentMethod::Transfer, CarbonImmutable::today(),
        PaymentChannel::factory()->for($level)->create(),
    ))->toThrow(ValidationException::class, 'Teller hanya mencatat pembayaran tunai.');
});

it('refuses a teller payment for a student of another level', function () {
    $student = Student::factory()->for(Level::factory()->smp())->create();
    $bill = Bill::factory()->forStudent($student)->create(['amount' => 100_000]);

    expect(fn () => app(SubmitPayment::class)->handle(
        User::factory()->teller()->forLevel(Level::factory()->sd()->create())->create(), $student, [$bill->id => 100_000], PaymentMethod::Cash, CarbonImmutable::today(),
    ))->toThrow(ValidationException::class, 'Anda tidak berhak membayar tagihan siswa ini.');
});
