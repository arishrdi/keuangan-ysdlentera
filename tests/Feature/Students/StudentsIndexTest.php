<?php

use App\Enums\Gender;
use App\Enums\StudentStatus;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Level;
use App\Models\Student;
use App\Models\User;
use Livewire\Livewire;

it('creates a student and places them in a classroom of the active year', function () {
    $this->actingAs(User::factory()->admin()->create());
    $level = Level::factory()->sd()->create();
    $classroom = Classroom::factory()->for($level)->for(AcademicYear::factory()->active())->create();

    Livewire::test('pages::students.index')
        ->set('name', 'Muhammad Fajar')
        ->set('level_id', $level->id)
        ->set('classroom_id', $classroom->id)
        ->set('nis', '2026001')
        ->set('gender', Gender::Male->value)
        ->call('save')
        ->assertHasNoErrors();

    $student = Student::query()->sole();

    expect($student->name)->toBe('Muhammad Fajar')
        ->and($student->status)->toBe(StudentStatus::Active)
        ->and($student->currentEnrollment()->first()->classroom_id)->toBe($classroom->id);
});

it('rejects a classroom from another level', function () {
    $this->actingAs(User::factory()->admin()->create());
    $academicYear = AcademicYear::factory()->active()->create();
    $sd = Level::factory()->sd()->create();
    $smpClassroom = Classroom::factory()->for(Level::factory()->smp())->for($academicYear)->create();

    Livewire::test('pages::students.index')
        ->set('name', 'Salah Kelas')
        ->set('level_id', $sd->id)
        ->set('classroom_id', $smpClassroom->id)
        ->set('gender', Gender::Female->value)
        ->call('save')
        ->assertHasErrors(['classroom_id' => 'Kelas harus sesuai jenjang siswa dan tahun ajaran aktif.']);
});

it('rejects a duplicate NIS', function () {
    $this->actingAs(User::factory()->admin()->create());
    $existing = Student::factory()->create(['nis' => '12345']);

    Livewire::test('pages::students.index')
        ->set('name', 'Kembar NIS')
        ->set('level_id', $existing->level_id)
        ->set('nis', '12345')
        ->set('gender', Gender::Male->value)
        ->call('save')
        ->assertHasErrors(['nis' => 'unique']);
});

it('records the date when a student leaves', function () {
    $this->travelTo('2026-11-19');
    $this->actingAs(User::factory()->admin()->create());
    $student = Student::factory()->create();

    Livewire::test('pages::students.index')
        ->call('edit', $student->id)
        ->set('student_status', StudentStatus::Transferred->value)
        ->call('save')
        ->assertHasNoErrors();

    $student->refresh();

    expect($student->status)->toBe(StudentStatus::Transferred)
        ->and($student->status_changed_on->toDateString())->toBe('2026-11-19');
});

it('clears the status date when a student becomes active again', function () {
    $this->actingAs(User::factory()->admin()->create());
    $student = Student::factory()->withStatus(StudentStatus::DroppedOut)->create();

    Livewire::test('pages::students.index')
        ->call('edit', $student->id)
        ->set('student_status', StudentStatus::Active->value)
        ->call('save');

    expect($student->refresh()->status_changed_on)->toBeNull();
});

it('lists only students of the active level', function () {
    $this->actingAs(User::factory()->admin()->create());
    $sd = Level::factory()->sd()->create();
    Student::factory()->for($sd)->create(['name' => 'Siswa SD']);
    Student::factory()->for(Level::factory()->smp())->create(['name' => 'Siswa SMP']);

    session([Level::SESSION_KEY => $sd->id]);

    Livewire::test('pages::students.index')
        ->assertSee('Siswa SD')
        ->assertDontSee('Siswa SMP');
});

it('hides students who are no longer active by default', function () {
    $this->actingAs(User::factory()->admin()->create());
    Student::factory()->create(['name' => 'Masih Aktif']);
    Student::factory()->withStatus(StudentStatus::Graduated)->create(['name' => 'Sudah Lulus']);

    Livewire::test('pages::students.index')
        ->assertSee('Masih Aktif')
        ->assertDontSee('Sudah Lulus')
        ->set('status', '')
        ->assertSee('Sudah Lulus');
});

it('lets the foundation view students but not change them', function () {
    $this->actingAs(User::factory()->foundation()->create());
    $student = Student::factory()->create(['name' => 'Hanya Dilihat']);

    Livewire::test('pages::students.index')
        ->assertSee('Hanya Dilihat')
        ->assertDontSee('Tambah siswa')
        ->call('edit', $student->id)
        ->assertForbidden();
});
