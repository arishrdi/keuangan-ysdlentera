<?php

namespace Database\Factories;

use App\Enums\BudgetStatus;
use App\Enums\TransactionCategoryType;
use App\Models\BudgetProposal;
use App\Models\Level;
use App\Models\TransactionCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BudgetProposal>
 */
class BudgetProposalFactory extends Factory
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
            'transaction_category_id' => TransactionCategory::factory()->state(['type' => TransactionCategoryType::Budget]),
            'title' => 'Pengadaan '.fake()->word(),
            'reason' => fake()->sentence(),
            'total_amount' => 0,
            'status' => BudgetStatus::Draft,
        ];
    }

    /**
     * Give the proposal one item whose subtotal is the given total.
     */
    public function withTotal(int $total): static
    {
        return $this->afterCreating(function (BudgetProposal $proposal) use ($total): void {
            $proposal->items()->create(['name' => 'Kebutuhan', 'quantity' => 1, 'unit' => 'paket', 'unit_price' => $total, 'subtotal' => $total]);
            $proposal->refreshTotal();
        });
    }

    public function status(BudgetStatus $status): static
    {
        return $this->state(fn () => [
            'status' => $status,
            'number' => $status === BudgetStatus::Draft ? null : 'AGR/TEST/'.fake()->unique()->numerify('####'),
            'submitted_at' => $status === BudgetStatus::Draft ? null : now(),
        ]);
    }
}
