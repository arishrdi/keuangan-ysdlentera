<?php

use App\Actions\Billing\CreateBill;
use App\Models\AcademicYear;
use App\Models\Level;
use App\Models\PaymentCategory;
use App\Models\Student;
use App\Models\UniformItem;
use Illuminate\Validation\ValidationException;

it('totals an itemized uniform bill from the catalog prices', function () {
    $level = Level::factory()->sd()->create();
    $student = Student::factory()->for($level)->create();
    $batik = UniformItem::factory()->for($level)->create(['name' => 'Batik', 'size' => 'M', 'price' => 120_000]);
    $sport = UniformItem::factory()->for($level)->create(['name' => 'Olahraga', 'price' => 95_000]);

    $bill = app(CreateBill::class)->handle(
        $student,
        PaymentCategory::factory()->uniform()->create(),
        AcademicYear::factory()->active()->create(),
        'Seragam baru',
        uniformQuantities: [$batik->id => 2, $sport->id => 1],
    );

    expect($bill->amount)->toBe(335_000)
        ->and($bill->items)->toHaveCount(2)
        ->and($bill->items->firstWhere('uniform_item_id', $batik->id)->subtotal)->toBe(240_000);
});

it('ignores uniform items from another level', function () {
    $student = Student::factory()->for(Level::factory()->sd())->create();
    $otherLevelItem = UniformItem::factory()->for(Level::factory()->smp())->create();

    app(CreateBill::class)->handle(
        $student,
        PaymentCategory::factory()->uniform()->create(),
        AcademicYear::factory()->active()->create(),
        'Seragam',
        uniformQuantities: [$otherLevelItem->id => 1],
    );
})->throws(ValidationException::class, 'Pilih minimal satu item seragam.');

it('creates a manual bill such as arrears from before the system', function () {
    $student = Student::factory()->create();

    $bill = app(CreateBill::class)->handle(
        $student,
        PaymentCategory::factory()->spp()->create(),
        AcademicYear::factory()->active()->create(),
        'Tunggakan SPP 2025/2026',
        amount: 1_250_000,
    );

    expect($bill->amount)->toBe(1_250_000)
        ->and($bill->level_id)->toBe($student->level_id)
        ->and($bill->items)->toBeEmpty();
});
