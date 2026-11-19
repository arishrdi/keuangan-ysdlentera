<?php

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Level;
use App\Models\Student;
use App\Models\User;

it('shows the student identity, guardians and class history', function () {
    $student = Student::factory()->create(['name' => 'Zahra Amelia']);
    $student->enrollIn(Classroom::factory()->for(AcademicYear::factory()->startingIn(2025))->create(['name' => '1B']));
    $student->enrollIn(Classroom::factory()->for(AcademicYear::factory()->startingIn(2026)->active())->create(['name' => '2B']));
    User::factory()->guardian()->withStudents($student, 'Ibu')->create(['name' => 'Ibu Zahra']);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('students.show', $student))
        ->assertOk()
        ->assertSee('Zahra Amelia')
        ->assertSee('Ibu Zahra')
        ->assertSeeInOrder(['2026/2027', '2B', '2025/2026', '1B']);
});

it('forbids treasurers from opening student details', function () {
    $student = Student::factory()->create();

    $this->actingAs(User::factory()->treasurer()->create())
        ->withSession([Level::SESSION_KEY => Level::ALL])
        ->get(route('students.show', $student))
        ->assertForbidden();
});
