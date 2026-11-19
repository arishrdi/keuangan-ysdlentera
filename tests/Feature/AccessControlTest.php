<?php

use App\Models\Level;
use App\Models\User;

dataset('admin pages', [
    'users' => 'admin.users',
    'academic years' => 'admin.academic-years',
    'classrooms' => 'admin.classrooms',
    'guardians' => 'admin.guardians',
    'promotions' => 'admin.promotions',
]);

it('redirects guests to the login page', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));
})->with('admin pages');

it('opens every admin page for admins', function (string $route) {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route($route))
        ->assertOk();
})->with('admin pages');

it('forbids admin pages for other roles', function (string $state, string $route) {
    $this->actingAs(User::factory()->{$state}()->create())
        ->withSession([Level::SESSION_KEY => Level::ALL])
        ->get(route($route))
        ->assertForbidden();
})->with(['foundation', 'principal', 'treasurer', 'guardian'])->with('admin pages');

it('opens school data and the activity log for the foundation', function (string $route) {
    $this->actingAs(User::factory()->foundation()->create())
        ->get(route($route))
        ->assertOk();
})->with(['students.index', 'activity-logs.index']);

it('forbids school data and the activity log for principals, treasurers and guardians', function (string $state, string $route) {
    $this->actingAs(User::factory()->{$state}()->create())
        ->withSession([Level::SESSION_KEY => Level::ALL])
        ->get(route($route))
        ->assertForbidden();
})->with(['principal', 'treasurer', 'guardian'])->with(['students.index', 'activity-logs.index']);
