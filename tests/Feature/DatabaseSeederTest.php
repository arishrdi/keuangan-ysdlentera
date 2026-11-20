<?php

use App\Enums\Role;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

it('seeds the staff accounts but no demo data outside local', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(User::query()->orderBy('id')->pluck('role', 'username')->all())->toBe([
        'admin' => Role::Admin,
        'yayasan' => Role::Foundation,
        'kepsek' => Role::Principal,
        'bendahara' => Role::Treasurer,
    ])->and(Student::query()->exists())->toBeFalse();
});
