<?php

namespace Database\Factories;

use App\Models\Level;
use App\Models\UniformItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UniformItem>
 */
class UniformItemFactory extends Factory
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
            'name' => fake()->randomElement(['Seragam batik', 'Seragam olahraga', 'Seragam muslim', 'Jilbab', 'Topi']),
            'size' => fake()->randomElement(['S', 'M', 'L', null]),
            'price' => fake()->numberBetween(20, 200) * 1_000,
            'is_active' => true,
        ];
    }
}
