<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Level;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Classroom>
 */
class ClassroomFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $grade = fake()->numberBetween(1, 6);

        return [
            'level_id' => Level::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'name' => $grade.' '.fake()->unique()->bothify('?#'),
            'grade' => $grade,
            'homeroom_teacher' => fake()->name(),
        ];
    }
}
