<?php

use App\Models\Level;
use App\Models\Student;
use App\Models\User;

it('shows a guardian only their own linked children', function () {
    $ownChild = Student::factory()->create(['name' => 'Anak Sendiri']);
    $otherChild = Student::factory()->create(['name' => 'Anak Orang Lain']);
    $guardian = User::factory()->guardian()->withStudents($ownChild)->create();

    $this->actingAs($guardian)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Anak Sendiri')
        ->assertDontSee('Anak Orang Lain');
});

it('counts only active students of the active level for staff', function () {
    $sd = Level::factory()->sd()->create();
    $smp = Level::factory()->smp()->create();
    Student::factory()->count(3)->for($sd)->create();
    Student::factory()->count(5)->for($smp)->create();

    $this->actingAs(User::factory()->principal()->create())
        ->withSession([Level::SESSION_KEY => $sd->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeInOrder(['Siswa aktif', '3', 'SD']);
});

it('shows expense figures to the foundation only', function (string $state, bool $seesExpenses) {
    $response = $this->actingAs(User::factory()->{$state}()->create())
        ->withSession([Level::SESSION_KEY => Level::ALL])
        ->get(route('dashboard'))
        ->assertOk();

    $seesExpenses
        ? $response->assertSee('Pemasukan dan pengeluaran per bulan')
        : $response->assertSee('Pemasukan per bulan')->assertDontSee('Pemasukan dan pengeluaran per bulan');
})->with([
    'foundation' => ['foundation', true],
    'principal' => ['principal', false],
    'treasurer' => ['treasurer', false],
]);

it('hides the savings balance from the treasurer', function () {
    $this->actingAs(User::factory()->treasurer()->create())
        ->withSession([Level::SESSION_KEY => Level::ALL])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Tabungan siswa');
});

it('greets tellers with a link to the cashier page', function () {
    $this->actingAs(User::factory()->teller()->create())
        ->withSession([Level::SESSION_KEY => Level::ALL])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('teller.index'));
});
