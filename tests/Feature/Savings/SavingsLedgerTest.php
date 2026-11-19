<?php

use App\Actions\Savings\SavingsLedger;
use App\Enums\CashDirection;
use App\Enums\StudentStatus;
use App\Models\CashTransaction;
use App\Models\Level;
use App\Models\SavingsTransaction;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

it('adds deposits, subtracts withdrawals and numbers entries per level', function () {
    $this->travelTo('2026-11-19');
    $student = Student::factory()->for(Level::factory()->sd())->create();
    $ledger = app(SavingsLedger::class);

    $deposit = $ledger->deposit($student, 50_000, CarbonImmutable::today(), 'Uang jajan');
    $withdrawal = $ledger->withdraw($student, 20_000, CarbonImmutable::today());

    expect($student->savingsBalance())->toBe(30_000)
        ->and($deposit->number)->toBe('TAB/SD/2026/0001')
        ->and($withdrawal->number)->toBe('TAB/SD/2026/0002')
        ->and($withdrawal->direction)->toBe(CashDirection::Out);
});

it('keeps savings out of the school cash book', function () {
    app(SavingsLedger::class)->deposit(Student::factory()->create(), 50_000, CarbonImmutable::today());

    expect(CashTransaction::query()->exists())->toBeFalse();
});

it('refuses a withdrawal larger than the balance', function () {
    $student = Student::factory()->create();
    app(SavingsLedger::class)->deposit($student, 10_000, CarbonImmutable::today());

    app(SavingsLedger::class)->withdraw($student, 15_000, CarbonImmutable::today());
})->throws(ValidationException::class, 'tidak cukup');

it('only takes deposits from active students but lets former students withdraw', function () {
    $student = Student::factory()->create();
    app(SavingsLedger::class)->deposit($student, 40_000, CarbonImmutable::today());
    $student->update(['status' => StudentStatus::Graduated]);

    expect(fn () => app(SavingsLedger::class)->deposit($student, 10_000, CarbonImmutable::today()))
        ->toThrow(ValidationException::class, 'Setoran hanya untuk siswa aktif.');

    app(SavingsLedger::class)->withdraw($student, 40_000, CarbonImmutable::today());

    expect($student->savingsBalance())->toBe(0);
});

it('records the opening balance only once', function () {
    $student = Student::factory()->create();
    $ledger = app(SavingsLedger::class);

    $ledger->recordOpeningBalance($student, 125_000, CarbonImmutable::today());

    expect($student->savingsBalance())->toBe(125_000)
        ->and(fn () => $ledger->recordOpeningBalance($student, 1_000, CarbonImmutable::today()))
        ->toThrow(ValidationException::class, 'Saldo awal tabungan siswa ini sudah pernah dicatat.');
});

it('cancels a deposit with an opposite entry', function () {
    $student = Student::factory()->create();
    $deposit = app(SavingsLedger::class)->deposit($student, 50_000, CarbonImmutable::today());
    $treasurer = User::factory()->treasurer()->create();

    app(SavingsLedger::class)->cancel($deposit, $treasurer, 'Salah siswa');

    expect($student->savingsBalance())->toBe(0)
        ->and($deposit->refresh()->isCancelled())->toBeTrue()
        ->and($deposit->reversal->direction)->toBe(CashDirection::Out)
        ->and(SavingsTransaction::query()->count())->toBe(2);
});

it('refuses to cancel a deposit that was already withdrawn', function () {
    $student = Student::factory()->create();
    $deposit = app(SavingsLedger::class)->deposit($student, 50_000, CarbonImmutable::today());
    app(SavingsLedger::class)->withdraw($student, 50_000, CarbonImmutable::today());

    app(SavingsLedger::class)->cancel($deposit, User::factory()->treasurer()->create(), 'Salah');
})->throws(ValidationException::class, 'tidak cukup');

it('refuses to cancel twice', function () {
    $student = Student::factory()->create();
    $deposit = app(SavingsLedger::class)->deposit($student, 50_000, CarbonImmutable::today());
    $treasurer = User::factory()->treasurer()->create();
    app(SavingsLedger::class)->cancel($deposit, $treasurer, 'Salah');

    app(SavingsLedger::class)->cancel($deposit->refresh(), $treasurer, 'Lagi');
})->throws(ValidationException::class, 'Transaksi ini sudah dibatalkan.');

it('lists only students with a positive balance', function () {
    $saver = Student::factory()->create();
    SavingsTransaction::factory()->for($saver)->create(['amount' => 10_000]);
    $emptied = Student::factory()->create();
    SavingsTransaction::factory()->for($emptied)->create(['amount' => 10_000]);
    SavingsTransaction::factory()->for($emptied)->create(['amount' => 10_000, 'direction' => CashDirection::Out]);
    Student::factory()->create();

    expect(Student::query()->withPositiveSavings()->pluck('id')->all())->toBe([$saver->id]);
});
