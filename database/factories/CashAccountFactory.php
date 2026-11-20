<?php

namespace Database\Factories;

use App\Enums\CashAccountType;
use App\Models\CashAccount;
use App\Models\Level;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashAccount>
 */
class CashAccountFactory extends Factory
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
            'type' => CashAccountType::Cash,
            'name' => 'Kas '.fake()->unique()->word(),
            'is_active' => true,
        ];
    }

    /**
     * An account shared by every level.
     */
    public function shared(): static
    {
        return $this->state(fn () => ['level_id' => null]);
    }

    public function bank(): static
    {
        return $this->state(fn () => [
            'type' => CashAccountType::Bank,
            'name' => 'Bank '.fake()->unique()->word(),
            'bank_name' => 'BSI',
            'account_number' => fake()->numerify('##########'),
        ]);
    }
}
