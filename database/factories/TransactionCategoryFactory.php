<?php

namespace Database\Factories;

use App\Enums\TransactionCategoryType;
use App\Models\TransactionCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransactionCategory>
 */
class TransactionCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => TransactionCategoryType::Expense,
            'name' => ucfirst(fake()->unique()->word()).' '.fake()->word(),
            'is_active' => true,
        ];
    }

    public function income(): static
    {
        return $this->state(['type' => TransactionCategoryType::Income]);
    }

    public function expense(): static
    {
        return $this->state(['type' => TransactionCategoryType::Expense]);
    }
}
