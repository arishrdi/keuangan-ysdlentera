<?php

use App\Enums\BillStatus;
use App\Enums\PaymentStatus;
use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\FeeRate;
use App\Models\Level;
use App\Models\Payment;
use App\Models\PaymentCategory;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

it('opens billing and payments pages for finance staff', function (string $state, string $route) {
    $this->actingAs(User::factory()->{$state}()->create())
        ->withSession([Level::SESSION_KEY => Level::ALL])
        ->get(route($route))
        ->assertOk();
})->with(['treasurer', 'principal', 'foundation'])->with(['billing.index', 'payments.index']);

it('forbids billing pages for admins and guardians', function (string $state) {
    $this->actingAs(User::factory()->{$state}()->create())
        ->get(route('billing.index'))
        ->assertForbidden();
})->with(['admin', 'guardian']);

it('lets only the treasurer open the issue page', function () {
    $this->actingAs(User::factory()->principal()->create())
        ->withSession([Level::SESSION_KEY => Level::ALL])
        ->get(route('billing.issue'))
        ->assertForbidden();
});

it('summarises billed, received and outstanding amounts per category', function () {
    $this->actingAs(User::factory()->principal()->create());
    $year = AcademicYear::factory()->active()->create();
    $spp = PaymentCategory::factory()->spp()->create();
    Bill::factory()->for($year)->for($spp)->create(['amount' => 300_000, 'paid_amount' => 300_000, 'status' => BillStatus::Paid]);
    Bill::factory()->for($year)->for($spp)->create(['amount' => 300_000, 'discount_amount' => 50_000]);
    Bill::factory()->for($year)->for($spp)->create(['amount' => 999_000, 'status' => BillStatus::Cancelled]);

    $summary = Livewire::test('pages::billing.index')->instance()->summary->get($spp->id);

    expect((int) $summary->total)->toBe(550_000)
        ->and((int) $summary->received)->toBe(300_000)
        ->and((int) $summary->outstanding_students)->toBe(1);
});

it('issues monthly bills for the selected months of the active level', function () {
    $level = Level::factory()->sd()->create();
    $year = AcademicYear::factory()->startingIn(2026)->active()->create();
    $spp = PaymentCategory::factory()->spp()->activeFor([$level])->create();
    FeeRate::factory()->for($level)->for($spp)->for($year)->create(['amount' => 200_000]);
    Student::factory()->count(3)->for($level)->create();
    Student::factory()->for(Level::factory()->smp())->create();
    $this->actingAs(User::factory()->treasurer()->create());
    session([Level::SESSION_KEY => $level->id]);

    Livewire::test('pages::billing.issue')
        ->set('categoryId', $spp->id)
        ->set('months', ['2026-07', '2026-08'])
        ->call('issue')
        ->assertHasNoErrors()
        ->assertDispatched('toast', variant: 'success', text: '6 tagihan diterbitkan.');

    expect(Bill::query()->count())->toBe(6);
});

it('refuses a category that is switched off for the level', function () {
    $level = Level::factory()->paud()->create();
    AcademicYear::factory()->active()->create();
    $dpp = PaymentCategory::factory()->dpp()->create();
    $this->actingAs(User::factory()->treasurer()->create());
    session([Level::SESSION_KEY => $level->id]);

    Livewire::test('pages::billing.issue')
        ->set('categoryId', $dpp->id)
        ->call('issue')
        ->assertHasErrors(['categoryId' => 'Pilih kategori yang aktif untuk jenjang ini.']);
});

it('records a cash payment at the counter from the student page', function () {
    Notification::fake();
    $level = Level::factory()->sd()->create();
    $student = Student::factory()->for($level)->create();
    $bill = Bill::factory()->forStudent($student)->for(PaymentCategory::factory()->dpp())->create(['amount' => 1_000_000]);
    $this->actingAs(User::factory()->treasurer()->create());

    Livewire::test('pages::billing.student', ['student' => $student])
        ->call('toggleBill', $bill->id)
        ->assertSet('allocations', [$bill->id => 1_000_000])
        ->set("allocations.{$bill->id}", 400_000)
        ->set('method', 'tunai')
        ->call('recordPayment')
        ->assertHasNoErrors()
        ->assertDispatched('open-receipt');

    expect($bill->refresh()->status)->toBe(BillStatus::Partial)
        ->and(Payment::query()->sole()->status)->toBe(PaymentStatus::Verified);
});

it('gives a discount from the student page', function () {
    $student = Student::factory()->create();
    $bill = Bill::factory()->forStudent($student)->create(['amount' => 500_000]);
    $this->actingAs(User::factory()->treasurer()->create());

    Livewire::test('pages::billing.student', ['student' => $student])
        ->call('openDiscount', $bill->id)
        ->set('discountAmount', 500_000)
        ->set('discountReason', 'Yatim')
        ->call('applyDiscount')
        ->assertHasNoErrors();

    expect($bill->refresh()->status)->toBe(BillStatus::Paid);
});

it('edits an unpaid bill from the student page', function () {
    $student = Student::factory()->create();
    $bill = Bill::factory()->forStudent($student)->create(['amount' => 150_000, 'due_date' => null]);
    $this->actingAs(User::factory()->treasurer()->create());

    Livewire::test('pages::billing.student', ['student' => $student])
        ->assertSee('Ubah')
        ->call('openEdit', $bill->id)
        ->assertSet('editAmount', 150_000)
        ->set('editDescription', 'Tunggakan 2025')
        ->set('editAmount', 90_000)
        ->set('editDueDate', '2026-12-01')
        ->call('updateBill')
        ->assertHasNoErrors();

    expect($bill->refresh()->amount)->toBe(90_000)
        ->and($bill->description)->toBe('Tunggakan 2025')
        ->and($bill->due_date->toDateString())->toBe('2026-12-01');
});

it('lets the principal view a student page but not record payments', function () {
    $student = Student::factory()->create();
    $bill = Bill::factory()->forStudent($student)->create(['description' => 'SPP Juli 2026']);
    $this->actingAs(User::factory()->principal()->create());

    Livewire::test('pages::billing.student', ['student' => $student])
        ->assertSee('SPP Juli 2026')
        ->assertDontSee('Catat pembayaran')
        ->call('recordPayment')
        ->assertForbidden();
});

it('lets the treasurer verify and reject payments but not the principal', function () {
    Notification::fake();
    $student = Student::factory()->create();
    $bill = Bill::factory()->forStudent($student)->create(['amount' => 100_000]);
    $payment = Payment::factory()->for($student)->create(['amount' => 100_000, 'status' => PaymentStatus::Pending]);
    $payment->allocations()->create(['bill_id' => $bill->id, 'amount' => 100_000]);

    $this->actingAs(User::factory()->principal()->create());
    Livewire::test('pages::payments.index')->call('show', $payment->id)->call('verify')->assertForbidden();

    $this->actingAs(User::factory()->treasurer()->create());
    Livewire::test('pages::payments.index')
        ->call('show', $payment->id)
        ->call('reject')
        ->assertHasErrors(['rejectionReason' => 'required'])
        ->call('verify')
        ->assertHasNoErrors();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Verified);
});
