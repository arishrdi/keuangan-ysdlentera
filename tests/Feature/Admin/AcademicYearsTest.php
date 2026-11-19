<?php

use App\Models\AcademicYear;
use App\Models\ActivityLog;
use App\Models\User;
use Livewire\Livewire;

it('creates an academic year', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.academic-years')
        ->set('name', '2027/2028')
        ->set('starts_on', '2027-07-01')
        ->set('ends_on', '2028-06-30')
        ->call('save')
        ->assertHasNoErrors();

    $academicYear = AcademicYear::query()->sole();

    expect($academicYear->name)->toBe('2027/2028')
        ->and($academicYear->starts_on->toDateString())->toBe('2027-07-01')
        ->and($academicYear->is_active)->toBeFalse();

    expect(ActivityLog::query()->where('action', 'academic_year.created')->exists())->toBeTrue();
});

it('rejects a badly formatted or reversed academic year', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.academic-years')
        ->set('name', '2027-2028')
        ->set('starts_on', '2027-07-01')
        ->set('ends_on', '2027-01-01')
        ->call('save')
        ->assertHasErrors(['name' => 'Format tahun ajaran harus seperti 2026/2027.', 'ends_on' => 'after']);
});

it('keeps exactly one academic year active', function () {
    $this->actingAs(User::factory()->admin()->create());
    $previous = AcademicYear::factory()->startingIn(2025)->active()->create();
    $next = AcademicYear::factory()->startingIn(2026)->create();

    Livewire::test('pages::admin.academic-years')->call('activate', $next->id);

    expect($next->refresh()->is_active)->toBeTrue()
        ->and($previous->refresh()->is_active)->toBeFalse()
        ->and(AcademicYear::current()->is($next))->toBeTrue();
});
