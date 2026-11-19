<?php

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Level;
use App\Models\Student;
use App\Models\User;
use Livewire\Livewire;

it('creates a classroom in the selected academic year', function () {
    $this->actingAs(User::factory()->admin()->create());
    $academicYear = AcademicYear::factory()->active()->create();
    $level = Level::factory()->sd()->create();

    Livewire::test('pages::admin.classrooms')
        ->set('level_id', $level->id)
        ->set('name', '1A')
        ->set('grade', 1)
        ->set('homeroom_teacher', 'Bu Siti')
        ->call('save')
        ->assertHasNoErrors();

    $classroom = Classroom::query()->sole();

    expect($classroom->academic_year_id)->toBe($academicYear->id)
        ->and($classroom->level_id)->toBe($level->id)
        ->and($classroom->homeroom_teacher)->toBe('Bu Siti');
});

it('rejects a duplicate classroom name in the same level and year', function () {
    $this->actingAs(User::factory()->admin()->create());
    $academicYear = AcademicYear::factory()->active()->create();
    $level = Level::factory()->sd()->create();
    Classroom::factory()->for($level)->for($academicYear)->create(['name' => '1A']);

    Livewire::test('pages::admin.classrooms')
        ->set('level_id', $level->id)
        ->set('name', '1A')
        ->set('grade', 1)
        ->call('save')
        ->assertHasErrors(['name' => 'unique']);
});

it('allows the same classroom name in another level', function () {
    $this->actingAs(User::factory()->admin()->create());
    $academicYear = AcademicYear::factory()->active()->create();
    Classroom::factory()->for(Level::factory()->sd())->for($academicYear)->create(['name' => 'A']);
    $paud = Level::factory()->paud()->create();

    Livewire::test('pages::admin.classrooms')
        ->set('level_id', $paud->id)
        ->set('name', 'A')
        ->set('grade', 0)
        ->call('save')
        ->assertHasNoErrors();

    expect(Classroom::query()->count())->toBe(2);
});

it('lists only classrooms of the active level', function () {
    $this->actingAs(User::factory()->admin()->create());
    $academicYear = AcademicYear::factory()->active()->create();
    $sd = Level::factory()->sd()->create();
    Classroom::factory()->for($sd)->for($academicYear)->create(['name' => 'Kelas SD']);
    Classroom::factory()->for(Level::factory()->smp())->for($academicYear)->create(['name' => 'Kelas SMP']);

    session([Level::SESSION_KEY => $sd->id]);

    Livewire::test('pages::admin.classrooms')
        ->assertSee('Kelas SD')
        ->assertDontSee('Kelas SMP');
});

it('refuses to delete a classroom that has students', function () {
    $this->actingAs(User::factory()->admin()->create());
    $classroom = Classroom::factory()->for(AcademicYear::factory()->active())->create();
    Student::factory()->create()->enrollIn($classroom);

    Livewire::test('pages::admin.classrooms')
        ->call('delete', $classroom->id)
        ->assertDispatched('toast', variant: 'error', text: 'Kelas yang sudah berisi siswa tidak bisa dihapus.');

    expect($classroom->fresh())->not->toBeNull();
});

it('deletes an empty classroom', function () {
    $this->actingAs(User::factory()->admin()->create());
    $classroom = Classroom::factory()->for(AcademicYear::factory()->active())->create();

    Livewire::test('pages::admin.classrooms')->call('delete', $classroom->id);

    expect($classroom->fresh())->toBeNull();
});
