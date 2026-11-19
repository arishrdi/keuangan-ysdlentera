<?php

use App\Enums\StudentStatus;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Level;
use App\Models\Student;
use App\Models\User;
use Livewire\Livewire;

it('promotes selected students into a classroom of the active year and keeps their history', function () {
    $this->actingAs(User::factory()->admin()->create());
    $level = Level::factory()->sd()->create();
    $lastYear = AcademicYear::factory()->startingIn(2025)->create();
    $thisYear = AcademicYear::factory()->startingIn(2026)->active()->create();
    $source = Classroom::factory()->for($level)->for($lastYear)->create(['name' => '1A', 'grade' => 1]);
    $target = Classroom::factory()->for($level)->for($thisYear)->create(['name' => '2A', 'grade' => 2]);
    [$promoted, $heldBack] = Student::factory()->count(2)->for($level)->create()->each->enrollIn($source)->all();

    Livewire::test('pages::admin.promotions')
        ->assertSet('sourceYearId', $lastYear->id)
        ->set('sourceClassroomId', $source->id)
        ->assertSet('selectedStudentIds', fn (array $ids) => collect($ids)->sort()->values()->all() === [$promoted->id, $heldBack->id])
        ->set('selectedStudentIds', [$promoted->id])
        ->set('targetClassroomId', $target->id)
        ->call('process')
        ->assertHasNoErrors();

    expect($promoted->currentEnrollment()->first()->classroom_id)->toBe($target->id)
        ->and($promoted->enrollments()->count())->toBe(2)
        ->and($heldBack->currentEnrollment()->first())->toBeNull();
});

it('moves the student to the target level when promoting across levels', function () {
    $this->actingAs(User::factory()->admin()->create());
    $sd = Level::factory()->sd()->create();
    $smp = Level::factory()->smp()->create();
    $lastYear = AcademicYear::factory()->startingIn(2025)->create();
    $thisYear = AcademicYear::factory()->startingIn(2026)->active()->create();
    $source = Classroom::factory()->for($sd)->for($lastYear)->create();
    $target = Classroom::factory()->for($smp)->for($thisYear)->create();
    $student = Student::factory()->for($sd)->create();
    $student->enrollIn($source);

    Livewire::test('pages::admin.promotions')
        ->set('sourceClassroomId', $source->id)
        ->set('targetClassroomId', $target->id)
        ->call('process');

    expect($student->refresh()->level_id)->toBe($smp->id);
});

it('rejects a target classroom outside the active year', function () {
    $this->actingAs(User::factory()->admin()->create());
    $lastYear = AcademicYear::factory()->startingIn(2025)->create();
    AcademicYear::factory()->startingIn(2026)->active()->create();
    $source = Classroom::factory()->for($lastYear)->create();
    $otherOldClassroom = Classroom::factory()->for($lastYear)->create();
    Student::factory()->create()->enrollIn($source);

    Livewire::test('pages::admin.promotions')
        ->set('sourceClassroomId', $source->id)
        ->set('targetClassroomId', $otherOldClassroom->id)
        ->call('process')
        ->assertHasErrors(['targetClassroomId' => 'Kelas tujuan harus berada di tahun ajaran aktif.']);
});

it('graduates selected students', function () {
    $this->actingAs(User::factory()->admin()->create());
    $lastYear = AcademicYear::factory()->startingIn(2025)->create();
    AcademicYear::factory()->startingIn(2026)->active()->create();
    $source = Classroom::factory()->for($lastYear)->create();
    $student = Student::factory()->create();
    $student->enrollIn($source);

    Livewire::test('pages::admin.promotions')
        ->set('sourceClassroomId', $source->id)
        ->set('mode', 'graduate')
        ->set('graduatedOn', '2026-06-20')
        ->call('process')
        ->assertHasNoErrors();

    $student->refresh();

    expect($student->status)->toBe(StudentStatus::Graduated)
        ->and($student->status_changed_on->toDateString())->toBe('2026-06-20');
});

it('requires at least one selected student', function () {
    $this->actingAs(User::factory()->admin()->create());
    AcademicYear::factory()->active()->create();
    $source = Classroom::factory()->create();

    Livewire::test('pages::admin.promotions')
        ->set('sourceClassroomId', $source->id)
        ->set('mode', 'graduate')
        ->call('process')
        ->assertHasErrors(['selectedStudentIds' => 'Pilih minimal satu siswa.']);
});
