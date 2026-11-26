<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Level;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => null,
            'email' => fake()->unique()->safeEmail(),
            'phone' => null,
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => Role::Guardian,
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function role(Role $role): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => $role,
            'username' => $role->isStaff() ? fake()->unique()->userName() : $attributes['username'],
        ]);
    }

    public function admin(): static
    {
        return $this->role(Role::Admin);
    }

    public function foundation(): static
    {
        return $this->role(Role::Foundation);
    }

    public function principal(): static
    {
        return $this->role(Role::Principal);
    }

    public function treasurer(): static
    {
        return $this->role(Role::Treasurer);
    }

    public function teller(): static
    {
        return $this->role(Role::Teller);
    }

    /**
     * Lock a staff member to a single level.
     */
    public function forLevel(Level $level): static
    {
        return $this->state(fn (array $attributes) => ['level_id' => $level->id]);
    }

    public function guardian(): static
    {
        return $this->role(Role::Guardian)->state(fn (array $attributes) => [
            'phone' => '08'.fake()->unique()->numerify('##########'),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Link the guardian to the given students.
     *
     * @param  Student|Collection<int, Student>  $students
     */
    public function withStudents(Student|Collection $students, string $relationship = 'Ayah'): static
    {
        return $this->hasAttached($students, ['relationship' => $relationship], 'students');
    }
}
