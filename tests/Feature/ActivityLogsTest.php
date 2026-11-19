<?php

use App\Models\ActivityLog;
use App\Models\User;
use Livewire\Livewire;

it('lists recorded activities with the acting user', function () {
    $admin = User::factory()->admin()->create(['name' => 'Admin Sekolah']);
    $this->actingAs($admin);
    ActivityLog::record('student.created', 'Menambah data siswa Budi');

    $this->get(route('activity-logs.index'))
        ->assertOk()
        ->assertSee('Menambah data siswa Budi')
        ->assertSee('Admin Sekolah');
});

it('filters activities by keyword', function () {
    $this->actingAs(User::factory()->foundation()->create());
    ActivityLog::record('student.created', 'Menambah data siswa Budi');
    ActivityLog::record('classroom.created', 'Menambah kelas 1A');

    Livewire::test('pages::activity-logs.index')
        ->set('search', 'kelas')
        ->assertSee('Menambah kelas 1A')
        ->assertDontSee('Menambah data siswa Budi');
});
