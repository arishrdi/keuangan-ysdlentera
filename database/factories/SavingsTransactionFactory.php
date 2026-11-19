<?php

namespace Database\Factories;

use App\Enums\CashDirection;
use App\Enums\SavingsTransactionType;
use App\Models\SavingsTransaction;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Raw savings rows for tests that only read balances. Use SavingsLedger to exercise the business rules.
 *
 * @extends Factory<SavingsTransaction>
 */
class SavingsTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => 'TAB/TEST/'.fake()->unique()->numerify('######'),
            'student_id' => Student::factory(),
            'level_id' => fn (array $attributes) => Student::query()->findOrFail((int) $attributes['student_id'])->level_id,
            'type' => SavingsTransactionType::Deposit,
            'direction' => CashDirection::In,
            'amount' => fake()->numberBetween(5, 100) * 1_000,
            'transacted_on' => now()->toDateString(),
        ];
    }
}
