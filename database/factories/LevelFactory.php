<?php

namespace Database\Factories;

use App\Models\Level;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Level>
 */
class LevelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->lexify('???'),
            'name' => fake()->words(2, true),
            'sort_order' => fake()->numberBetween(1, 9),
        ];
    }

    public function paud(): static
    {
        return $this->state(['code' => 'PAUD', 'name' => 'PAUD', 'sort_order' => 1]);
    }

    public function sd(): static
    {
        return $this->state(['code' => 'SD', 'name' => 'SD', 'sort_order' => 2]);
    }

    public function smp(): static
    {
        return $this->state(['code' => 'SMP', 'name' => 'SMP', 'sort_order' => 3]);
    }
}
