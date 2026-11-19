<?php

namespace Database\Factories;

use App\Enums\CashDirection;
use App\Enums\CashTransactionType;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Raw ledger rows for tests that only read balances. Use CashLedger to exercise the business rules.
 *
 * @extends Factory<CashTransaction>
 */
class CashTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => 'TRX/TEST/'.fake()->unique()->numerify('######'),
            'cash_account_id' => CashAccount::factory(),
            'level_id' => fn (array $attributes) => CashAccount::query()->findOrFail((int) $attributes['cash_account_id'])->level_id,
            'type' => CashTransactionType::Income,
            'direction' => CashDirection::In,
            'amount' => fake()->numberBetween(10, 500) * 1_000,
            'transacted_on' => now()->toDateString(),
            'description' => fake()->sentence(3),
        ];
    }
}
