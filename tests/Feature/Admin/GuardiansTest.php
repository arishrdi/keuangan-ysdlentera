<?php

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Student;
use App\Models\User;
use Livewire\Livewire;

it('creates a guardian account with a normalized phone number', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.guardians')
        ->set('name', 'Pak Ahmad')
        ->set('phone', '+62 812-1111-2222')
        ->set('password', 'rahasia123')
        ->call('save')
        ->assertHasNoErrors();

    $guardian = User::query()->where('name', 'Pak Ahmad')->sole();

    expect($guardian->role)->toBe(Role::Guardian)
        ->and($guardian->phone)->toBe('081211112222')
        ->and($guardian->email)->toBeNull();
});

it('rejects a phone number already used by another account', function () {
    $this->actingAs(User::factory()->admin()->create());
    User::factory()->guardian()->create(['phone' => '081211112222']);

    Livewire::test('pages::admin.guardians')
        ->set('name', 'Pak Budi')
        ->set('phone', '0812-1111-2222')
        ->set('password', 'rahasia123')
        ->call('save')
        ->assertHasErrors(['phone' => 'unique']);
});

it('links and unlinks students to a guardian', function () {
    $this->actingAs(User::factory()->admin()->create());
    $guardian = User::factory()->guardian()->create();
    $student = Student::factory()->create(['name' => 'Aisyah Putri']);

    Livewire::test('pages::admin.guardians')
        ->call('manageStudents', $guardian->id)
        ->set('studentSearch', 'Aisyah')
        ->assertSee('Aisyah Putri')
        ->set('relationship', 'Ibu')
        ->call('linkStudent', $student->id)
        ->assertHasNoErrors();

    expect($guardian->students()->sole()->pivot->relationship)->toBe('Ibu')
        ->and(ActivityLog::query()->where('action', 'guardian.student_linked')->exists())->toBeTrue();

    Livewire::test('pages::admin.guardians')
        ->call('manageStudents', $guardian->id)
        ->call('unlinkStudent', $student->id);

    expect($guardian->students()->exists())->toBeFalse();
});

it('links one guardian to several students across levels', function () {
    $this->actingAs(User::factory()->admin()->create());
    $guardian = User::factory()->guardian()->create();
    $students = Student::factory()->count(2)->create();

    $component = Livewire::test('pages::admin.guardians')->call('manageStudents', $guardian->id);

    foreach ($students as $student) {
        $component->call('linkStudent', $student->id);
    }

    expect($guardian->students()->count())->toBe(2);
});

it('refuses to manage children of a staff account', function () {
    $this->actingAs(User::factory()->admin()->create());
    $staff = User::factory()->treasurer()->create();

    Livewire::test('pages::admin.guardians')
        ->call('manageStudents', $staff->id)
        ->assertNotFound();
});
