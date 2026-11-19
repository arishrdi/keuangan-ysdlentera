<?php

use App\Actions\Billing\IssueBills;
use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\Classroom;
use App\Models\FeeRate;
use App\Models\Level;
use App\Models\PaymentCategory;
use App\Models\Student;
use Carbon\CarbonImmutable;

it('creates one bill per student per month with the due day of each month', function () {
    $level = Level::factory()->sd()->create();
    $year = AcademicYear::factory()->startingIn(2026)->active()->create();
    $spp = PaymentCategory::factory()->spp()->create();
    FeeRate::factory()->for($level)->for($spp)->for($year)->create(['amount' => 250_000]);
    $students = Student::factory()->count(2)->for($level)->create();

    $result = app(IssueBills::class)->handle($spp, $level, $year, $students, [
        CarbonImmutable::parse('2026-07-01'),
        CarbonImmutable::parse('2027-02-01'),
    ], dueDay: 30);

    expect($result)->toBe(['created' => 4, 'existing' => 0, 'without_rate' => 0]);

    $february = Bill::query()->whereDate('period', '2027-02-01')->firstOrFail();

    expect($february->amount)->toBe(250_000)
        ->and($february->description)->toBe('SPP Februari 2027')
        ->and($february->due_date->toDateString())->toBe('2027-02-28');
});

it('skips months that were already billed', function () {
    $level = Level::factory()->sd()->create();
    $year = AcademicYear::factory()->startingIn(2026)->active()->create();
    $spp = PaymentCategory::factory()->spp()->create();
    FeeRate::factory()->for($level)->for($spp)->for($year)->create();
    $students = Student::factory()->count(1)->for($level)->create();
    $july = [CarbonImmutable::parse('2026-07-01')];

    app(IssueBills::class)->handle($spp, $level, $year, $students, $july);
    $result = app(IssueBills::class)->handle($spp, $level, $year, $students, $july);

    expect($result)->toBe(['created' => 0, 'existing' => 1, 'without_rate' => 0])
        ->and(Bill::query()->count())->toBe(1);
});

it('prefers the grade-specific rate over the level-wide rate', function () {
    $level = Level::factory()->sd()->create();
    $year = AcademicYear::factory()->startingIn(2026)->active()->create();
    $dpp = PaymentCategory::factory()->dpp()->create();
    FeeRate::factory()->for($level)->for($dpp)->for($year)->create(['grade' => null, 'amount' => 2_000_000]);
    FeeRate::factory()->for($level)->for($dpp)->for($year)->create(['grade' => 1, 'amount' => 3_000_000]);
    $firstGrader = Student::factory()->for($level)->create();
    $firstGrader->enrollIn(Classroom::factory()->for($level)->for($year)->create(['grade' => 1]));
    $sixthGrader = Student::factory()->for($level)->create();
    $sixthGrader->enrollIn(Classroom::factory()->for($level)->for($year)->create(['grade' => 6]));

    app(IssueBills::class)->handle($dpp, $level, $year, collect([$firstGrader, $sixthGrader]));

    expect($firstGrader->bills()->sole()->amount)->toBe(3_000_000)
        ->and($sixthGrader->bills()->sole()->amount)->toBe(2_000_000);
});

it('bills a one-time category only once per academic year', function () {
    $level = Level::factory()->sd()->create();
    $year = AcademicYear::factory()->startingIn(2026)->active()->create();
    $dpp = PaymentCategory::factory()->dpp()->create();
    FeeRate::factory()->for($level)->for($dpp)->for($year)->create();
    $students = Student::factory()->count(1)->for($level)->create();

    app(IssueBills::class)->handle($dpp, $level, $year, $students);
    $result = app(IssueBills::class)->handle($dpp, $level, $year, $students);

    expect($result['existing'])->toBe(1)
        ->and(Bill::query()->sole()->period)->toBeNull();
});

it('reports students without a rate and uses the override amount when given', function () {
    $level = Level::factory()->sd()->create();
    $year = AcademicYear::factory()->startingIn(2026)->active()->create();
    $building = PaymentCategory::factory()->building()->create();
    $students = Student::factory()->count(2)->for($level)->create();

    $withoutRate = app(IssueBills::class)->handle($building, $level, $year, $students);
    $withOverride = app(IssueBills::class)->handle($building, $level, $year, $students, amountOverride: 750_000);

    expect($withoutRate)->toBe(['created' => 0, 'existing' => 0, 'without_rate' => 2])
        ->and($withOverride['created'])->toBe(2)
        ->and(Bill::query()->pluck('amount')->unique()->all())->toBe([750_000]);
});

it('ignores students of another level', function () {
    $sd = Level::factory()->sd()->create();
    $year = AcademicYear::factory()->startingIn(2026)->active()->create();
    $dpp = PaymentCategory::factory()->dpp()->create();
    $smpStudent = Student::factory()->for(Level::factory()->smp())->create();

    $result = app(IssueBills::class)->handle($dpp, $sd, $year, collect([$smpStudent]), amountOverride: 100_000);

    expect($result['created'])->toBe(0);
});
