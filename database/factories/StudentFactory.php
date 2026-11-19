<?php

namespace Database\Factories;

use App\Enums\Gender;
use App\Enums\StudentStatus;
use App\Models\Level;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'level_id' => Level::factory(),
            'nis' => fake()->unique()->numerify('######'),
            'nisn' => null,
            'name' => fake()->name(),
            'gender' => fake()->randomElement(Gender::cases()),
            'birth_place' => fake()->city(),
            'birth_date' => fake()->dateTimeBetween('-15 years', '-4 years'),
            'status' => StudentStatus::Active,
        ];
    }

    public function withStatus(StudentStatus $status): static
    {
        return $this->state([
            'status' => $status,
            'status_changed_on' => $status === StudentStatus::Active ? null : now(),
        ]);
    }
}
