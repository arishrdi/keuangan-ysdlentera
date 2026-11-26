<?php

use App\Enums\Role;
use App\Models\Level;
use App\Models\User;
use Livewire\Livewire;

it('creates a staff account that can log in with its username', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.users')
        ->set('name', 'Bu Bendahara')
        ->set('username', 'Bendahara')
        ->set('role', Role::Treasurer->value)
        ->set('password', 'rahasia123')
        ->call('save')
        ->assertHasNoErrors();

    $user = User::query()->where('username', 'bendahara')->sole();

    expect($user->role)->toBe(Role::Treasurer)
        ->and($user->email)->toBeNull()
        ->and($user->is_active)->toBeTrue();

    auth()->logout();

    $this->post(route('login.store'), ['login' => 'bendahara', 'password' => 'rahasia123']);

    $this->assertAuthenticatedAs($user);
});

it('requires a password for a new account but not when editing', function () {
    $admin = User::factory()->admin()->create();
    $treasurer = User::factory()->treasurer()->create();
    $originalHash = $treasurer->password;
    $this->actingAs($admin);

    Livewire::test('pages::admin.users')
        ->set('name', 'Tanpa Password')
        ->set('username', 'tanpapass')
        ->set('role', Role::Principal->value)
        ->call('save')
        ->assertHasErrors(['password' => 'required']);

    Livewire::test('pages::admin.users')
        ->call('edit', $treasurer->id)
        ->set('name', 'Nama Baru')
        ->call('save')
        ->assertHasNoErrors();

    expect($treasurer->refresh()->name)->toBe('Nama Baru')
        ->and($treasurer->password)->toBe($originalHash);
});

it('does not allow creating guardian accounts from the staff page', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.users')
        ->set('name', 'Bukan Staf')
        ->set('username', 'bukanstaf')
        ->set('role', Role::Guardian->value)
        ->set('password', 'rahasia123')
        ->call('save')
        ->assertHasErrors(['role' => 'in']);
});

it('prevents admins from deactivating or demoting themselves', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test('pages::admin.users')
        ->call('edit', $admin->id)
        ->set('is_active', false)
        ->call('save')
        ->assertHasErrors('role');

    expect($admin->refresh()->is_active)->toBeTrue();
});

it('forbids non-admins from saving accounts', function () {
    $this->actingAs(User::factory()->foundation()->create());

    Livewire::test('pages::admin.users')
        ->set('name', 'Penyusup')
        ->set('username', 'penyusup')
        ->set('role', Role::Admin->value)
        ->set('password', 'rahasia123')
        ->call('save')
        ->assertForbidden();

    expect(User::query()->where('username', 'penyusup')->exists())->toBeFalse();
});

it('locks a teller account to the chosen level', function () {
    $level = Level::factory()->sd()->create();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.users')
        ->set('name', 'Pak Teller')
        ->set('username', 'teller.sd')
        ->set('role', Role::Teller->value)
        ->set('level_id', $level->id)
        ->set('password', 'rahasia123')
        ->call('save')
        ->assertHasNoErrors();

    expect(User::query()->where('username', 'teller.sd')->sole()->level_id)->toBe($level->id);
});

it('ignores a level for roles that always cover every level', function () {
    $level = Level::factory()->sd()->create();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.users')
        ->set('name', 'Ketua Yayasan')
        ->set('username', 'yayasan2')
        ->set('role', Role::Foundation->value)
        ->set('level_id', $level->id)
        ->set('password', 'rahasia123')
        ->call('save')
        ->assertHasNoErrors();

    expect(User::query()->where('username', 'yayasan2')->sole()->level_id)->toBeNull();
});
