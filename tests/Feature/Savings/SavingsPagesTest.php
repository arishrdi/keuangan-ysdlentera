<?php

use App\Enums\StudentStatus;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Level;
use App\Models\SavingsTransaction;
use App\Models\Student;
use App\Models\User;
use Livewire\Livewire;

it('opens the savings pages for finance staff only', function () {
    $student = Student::factory()->create();

    foreach (['treasurer', 'principal', 'foundation'] as $state) {
        $user = User::factory()->{$state}()->create();
        $this->actingAs($user)->withSession([Level::SESSION_KEY => Level::ALL])->get(route('savings.index'))->assertOk();
        $this->actingAs($user)->withSession([Level::SESSION_KEY => Level::ALL])->get(route('savings.student', $student))->assertOk();
    }

    $this->actingAs(User::factory()->admin()->create())->get(route('savings.index'))->assertForbidden();
    $this->actingAs(User::factory()->guardian()->withStudents($student)->create())->get(route('savings.student', $student))->assertForbidden();
});

it('records a deposit from the savings list', function () {
    $student = Student::factory()->create();
    $this->actingAs(User::factory()->treasurer()->create());

    Livewire::test('pages::savings.index')
        ->call('open', $student->id, 'setoran')
        ->set('amount', 25_000)
        ->call('save')
        ->assertHasNoErrors();

    expect($student->savingsBalance())->toBe(25_000);
});

it('shows the balance error on the amount field', function () {
    $student = Student::factory()->create();
    $this->actingAs(User::factory()->treasurer()->create());

    Livewire::test('pages::savings.student', ['student' => $student])
        ->call('open', 'penarikan')
        ->set('amount', 5_000)
        ->call('save')
        ->assertHasErrors('amount');
});

it('forbids the principal from recording savings', function () {
    $student = Student::factory()->create();
    $this->actingAs(User::factory()->principal()->create());

    Livewire::test('pages::savings.index')->call('open', $student->id, 'setoran')->assertForbidden();
});

it('prefills the full balance when withdrawing for a former student', function () {
    $student = Student::factory()->withStatus(StudentStatus::Transferred)->create();
    SavingsTransaction::factory()->for($student)->create(['amount' => 80_000]);
    $this->actingAs(User::factory()->treasurer()->create());

    Livewire::test('pages::savings.student', ['student' => $student])
        ->call('open', 'penarikan')
        ->assertSet('amount', 80_000)
        ->call('save')
        ->assertHasNoErrors();

    expect($student->savingsBalance())->toBe(0);
});

it('shows a running balance and cancels an entry from the student page', function () {
    $student = Student::factory()->create();
    $first = SavingsTransaction::factory()->for($student)->create(['amount' => 30_000, 'transacted_on' => '2026-11-01']);
    SavingsTransaction::factory()->for($student)->create(['amount' => 20_000, 'transacted_on' => '2026-11-02']);
    $this->actingAs(User::factory()->treasurer()->create());

    $component = Livewire::test('pages::savings.student', ['student' => $student]);

    expect($component->instance()->transactions->pluck('running_balance')->all())->toBe([50_000, 30_000]);

    $component->call('confirmCancel', $first->id)
        ->set('cancellationReason', 'Dobel')
        ->call('cancel')
        ->assertHasNoErrors();

    expect($student->savingsBalance())->toBe(20_000);
});

it('lists student balances of the active level only', function () {
    $sd = Level::factory()->sd()->create();
    $saver = Student::factory()->for($sd)->create(['name' => 'Penabung SD']);
    SavingsTransaction::factory()->for($saver)->create(['amount' => 15_000]);
    Student::factory()->for(Level::factory()->smp())->create(['name' => 'Siswa SMP']);
    $this->actingAs(User::factory()->foundation()->create());
    session([Level::SESSION_KEY => $sd->id]);

    Livewire::test('pages::savings.index')
        ->assertSee('Penabung SD')
        ->assertSee('Rp15.000')
        ->assertDontSee('Siswa SMP')
        ->assertSet('summary.balance', 15_000);
});

it('shows a guardian only their own children savings', function () {
    $child = Student::factory()->create();
    SavingsTransaction::factory()->for($child)->create(['amount' => 42_000, 'notes' => 'Setoran anak sendiri']);
    $stranger = Student::factory()->create();
    SavingsTransaction::factory()->for($stranger)->create(['amount' => 99_000, 'notes' => 'Setoran orang lain']);
    $this->actingAs(User::factory()->guardian()->withStudents($child)->create());

    Livewire::withQueryParams(['anak' => $stranger->id])
        ->test('pages::guardian.savings')
        ->assertSet('studentId', $child->id)
        ->assertSee('Rp42.000')
        ->assertSee('Setoran anak sendiri')
        ->assertDontSee('Setoran orang lain');
});

it('refuses to deactivate a student who still has savings', function () {
    $student = Student::factory()->create();
    SavingsTransaction::factory()->for($student)->create(['amount' => 60_000]);
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::students.index')
        ->call('edit', $student->id)
        ->set('student_status', StudentStatus::Graduated->value)
        ->call('save')
        ->assertHasErrors(['student_status' => 'Saldo tabungan '.$student->name.' masih Rp60.000. Catat penarikan seluruh saldo terlebih dahulu.']);

    expect($student->refresh()->status)->toBe(StudentStatus::Active);
});

it('refuses to graduate a class while a student still has savings', function () {
    $this->actingAs(User::factory()->admin()->create());
    $lastYear = AcademicYear::factory()->startingIn(2025)->create();
    AcademicYear::factory()->startingIn(2026)->active()->create();
    $source = Classroom::factory()->for($lastYear)->create();
    $student = Student::factory()->create();
    $student->enrollIn($source);
    SavingsTransaction::factory()->for($student)->create(['amount' => 10_000]);

    Livewire::test('pages::admin.promotions')
        ->set('sourceClassroomId', $source->id)
        ->set('mode', 'graduate')
        ->call('process')
        ->assertHasErrors('selectedStudentIds');

    expect($student->refresh()->status)->toBe(StudentStatus::Active);
});
