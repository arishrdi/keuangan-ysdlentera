<?php

use App\Models\Level;
use App\Models\User;
use Livewire\Livewire;

it('opens the level profile page for admins only', function () {
    $this->actingAs(User::factory()->admin()->create())->get(route('admin.levels'))->assertOk();
    $this->actingAs(User::factory()->foundation()->create())->get(route('admin.levels'))->assertForbidden();
});

it('updates the name, address and phone of a level', function () {
    $this->actingAs(User::factory()->admin()->create());
    $level = Level::factory()->sd()->create();

    Livewire::test('pages::admin.levels')
        ->call('edit', $level->id)
        ->set('name', 'SD Lentera')
        ->set('address', 'Jl. Pendidikan No. 5')
        ->set('phone', '022-1234567')
        ->call('save')
        ->assertHasNoErrors();

    expect($level->refresh())
        ->name->toBe('SD Lentera')
        ->address->toBe('Jl. Pendidikan No. 5')
        ->phone->toBe('022-1234567')
        ->code->toBe('SD');
});

it('requires a level name', function () {
    $this->actingAs(User::factory()->admin()->create());
    $level = Level::factory()->sd()->create();

    Livewire::test('pages::admin.levels')
        ->call('edit', $level->id)
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name' => 'required']);
});
