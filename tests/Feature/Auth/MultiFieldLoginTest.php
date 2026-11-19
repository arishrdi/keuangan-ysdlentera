<?php

use App\Models\User;

it('logs in with a username', function () {
    $user = User::factory()->admin()->create(['username' => 'bendahara1']);

    $this->post(route('login.store'), ['login' => 'Bendahara1', 'password' => 'password'])
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($user);
});

it('logs in with a phone number written in international format', function () {
    $user = User::factory()->guardian()->create(['phone' => '081234567890', 'email' => null]);

    $this->post(route('login.store'), ['login' => '+62 812-3456-7890', 'password' => 'password'])
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($user);
});

it('rejects a deactivated account even with the right password', function () {
    $user = User::factory()->admin()->inactive()->create();

    $this->post(route('login.store'), ['login' => $user->username, 'password' => 'password'])
        ->assertSessionHasErrors(['login' => 'Username/email/no. HP atau password salah.']);

    $this->assertGuest();
});

it('rejects a wrong password', function () {
    $user = User::factory()->admin()->create();

    $this->post(route('login.store'), ['login' => $user->username, 'password' => 'wrong-password'])
        ->assertSessionHasErrors('login');

    $this->assertGuest();
});

it('does not offer public registration', function () {
    expect(Route::has('register'))->toBeFalse();
});
