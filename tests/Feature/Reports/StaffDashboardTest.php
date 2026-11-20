<?php

use App\Actions\Reports\StaffDashboard;
use App\Enums\CashDirection;
use App\Enums\CashTransactionType;
use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Classroom;
use App\Models\Level;
use App\Models\Student;
use App\Models\User;

it('formats compact rupiah figures', function (int $amount, string $expected) {
    expect(StaffDashboard::shortRupiah($amount))->toBe($expected);
})->with([
    [0, 'Rp 0'],
    [850_000, 'Rp 850 rb'],
    [85_250_000, 'Rp 85,3 jt'],
    [10_000_000, 'Rp 10 jt'],
    [1_200_000_000, 'Rp 1,2 M'],
]);

it('sums income and expense per month and ignores cancelled entries', function () {
    $this->travelTo(now()->setDate(2026, 11, 20));
    $account = CashAccount::factory()->create();
    $entry = fn (CashTransactionType $type, int $amount, string $date, array $extra = []) => CashTransaction::factory()->for($account)->create([
        'type' => $type,
        'direction' => $type === CashTransactionType::Expense ? CashDirection::Out : CashDirection::In,
        'amount' => $amount,
        'transacted_on' => $date,
        ...$extra,
    ]);

    $entry(CashTransactionType::StudentPayment, 300_000, '2026-10-05');
    $entry(CashTransactionType::Income, 200_000, '2026-11-02');
    $entry(CashTransactionType::Expense, 150_000, '2026-11-10');
    $entry(CashTransactionType::Expense, 999_000, '2026-11-11', ['cancelled_at' => now()]);

    $flow = (new StaffDashboard(User::factory()->treasurer()->create(), null, null))->monthlyFlow();
    $november = collect($flow)->last();
    $october = collect($flow)->first(fn (array $month) => $month['month']->month === 10);

    expect($november['income'])->toBe(200_000)
        ->and($november['expense'])->toBe(150_000)
        ->and($october['income'])->toBe(300_000);
});

it('groups the active year bills per classroom and counts students still owing', function () {
    $level = Level::factory()->sd()->create();
    $academicYear = AcademicYear::factory()->active()->create();
    $classroom = Classroom::factory()->for($level)->for($academicYear)->create(['name' => '1A']);
    [$paidUp, $owing] = Student::factory()->count(2)->for($level)->create()->all();
    $paidUp->enrollIn($classroom);
    $owing->enrollIn($classroom);

    Bill::factory()->for($paidUp)->for($academicYear)->create(['amount' => 100_000, 'paid_amount' => 100_000]);
    Bill::factory()->for($owing)->for($academicYear)->create(['amount' => 100_000, 'paid_amount' => 40_000]);

    $dashboard = new StaffDashboard(User::factory()->principal()->create(), $level, $academicYear);
    $row = $dashboard->billsByClassroom()->sole();

    expect($row->classroom)->toBe('1A')
        ->and($row->students)->toBe(2)
        ->and($row->billed)->toBe(200_000)
        ->and($row->paid)->toBe(140_000)
        ->and($dashboard->bills())->toMatchArray(['outstanding' => 60_000, 'outstanding_students' => 1, 'percent' => 70.0]);
});

it('limits the monthly figures to the chosen month', function () {
    $this->travelTo(now()->setDate(2026, 11, 20));
    $account = CashAccount::factory()->create();
    CashTransaction::factory()->for($account)->create(['type' => CashTransactionType::Income, 'amount' => 400_000, 'transacted_on' => '2026-09-15']);
    CashTransaction::factory()->for($account)->create(['type' => CashTransactionType::Income, 'amount' => 70_000, 'transacted_on' => '2026-11-03']);

    $user = User::factory()->treasurer()->create();

    expect((new StaffDashboard($user, null, null, StaffDashboard::parseMonth('2026-09')))->monthIncome())->toBe(400_000)
        ->and((new StaffDashboard($user, null, null))->monthIncome())->toBe(70_000);
});

it('offers the months from the start of the academic year up to now, newest first', function () {
    $this->travelTo(now()->setDate(2026, 11, 20));
    $academicYear = AcademicYear::factory()->active()->create(['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);

    $months = (new StaffDashboard(User::factory()->treasurer()->create(), null, $academicYear))->availableMonths();

    expect(array_map(fn ($month) => $month->format('Y-m'), $months))->toBe(['2026-11', '2026-10', '2026-09', '2026-08', '2026-07']);
});

it('ignores a malformed or out-of-range month in the dashboard url', function (string $value) {
    $this->travelTo(now()->setDate(2026, 11, 20));
    AcademicYear::factory()->active()->create(['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);

    $this->actingAs(User::factory()->treasurer()->create())
        ->withSession([Level::SESSION_KEY => Level::ALL])
        ->get(route('dashboard', ['bulan' => $value]))
        ->assertOk()
        ->assertSee('Pemasukan November');
})->with(['bukan-bulan', '2026-13', '2025-01']);

it('shows the chosen month on the dashboard', function () {
    $this->travelTo(now()->setDate(2026, 11, 20));
    AcademicYear::factory()->active()->create(['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);

    $this->actingAs(User::factory()->treasurer()->create())
        ->withSession([Level::SESSION_KEY => Level::ALL])
        ->get(route('dashboard', ['bulan' => '2026-09']))
        ->assertOk()
        ->assertSee('Pemasukan September')
        ->assertSee('Bulan September 2026');
});
