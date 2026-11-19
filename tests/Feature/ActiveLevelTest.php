<?php

use App\Models\Level;
use App\Models\User;

it('sends principals and treasurers to the level picker before the dashboard', function (string $state) {
    $user = User::factory()->{$state}()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('levels.select'));
})->with(['principal', 'treasurer']);

it('lets admins and the foundation reach the dashboard without picking a level', function (string $state) {
    $user = User::factory()->{$state}()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
})->with(['admin', 'foundation']);

it('renders the level picker with every level', function () {
    Level::factory()->sd()->create();
    Level::factory()->smp()->create();

    $this->actingAs(User::factory()->principal()->create())
        ->get(route('levels.select'))
        ->assertOk()
        ->assertSeeInOrder(['SD', 'SMP', 'Lihat semua jenjang']);
});

it('stores the chosen level and continues to the dashboard', function () {
    $level = Level::factory()->sd()->create();

    $this->actingAs(User::factory()->principal()->create())
        ->post(route('levels.switch'), ['level' => $level->id])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas(Level::SESSION_KEY, $level->id);

    $this->get(route('dashboard'))->assertOk()->assertSee('Jenjang SD');
});

it('stores the all-levels choice', function () {
    $this->actingAs(User::factory()->treasurer()->create())
        ->post(route('levels.switch'), ['level' => Level::ALL])
        ->assertSessionHas(Level::SESSION_KEY, Level::ALL);

    expect(Level::active())->toBeNull();
});

it('returns to the previous page when switching from the topbar', function () {
    $level = Level::factory()->sd()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->from(route('admin.classrooms'))
        ->post(route('levels.switch'), ['level' => $level->id, 'stay' => 1])
        ->assertRedirect(route('admin.classrooms'));
});

it('rejects an unknown level', function () {
    $this->actingAs(User::factory()->principal()->create())
        ->post(route('levels.switch'), ['level' => 999])
        ->assertSessionHasErrors('level')
        ->assertSessionMissing(Level::SESSION_KEY);
});

it('forbids guardians from switching levels', function () {
    $level = Level::factory()->sd()->create();

    $this->actingAs(User::factory()->guardian()->create())
        ->post(route('levels.switch'), ['level' => $level->id])
        ->assertForbidden();
});
