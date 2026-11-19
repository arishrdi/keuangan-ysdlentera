<?php

namespace Database\Factories;

use App\Enums\BillingCycle;
use App\Models\Level;
use App\Models\PaymentCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentCategory>
 */
class PaymentCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->lexify('KAT???'),
            'name' => fake()->words(2, true),
            'billing_cycle' => BillingCycle::OneTime,
            'is_installable' => false,
            'sort_order' => fake()->numberBetween(1, 9),
        ];
    }

    public function spp(): static
    {
        return $this->state(['code' => PaymentCategory::SPP, 'name' => 'SPP', 'billing_cycle' => BillingCycle::Monthly, 'is_installable' => false]);
    }

    public function infak(): static
    {
        return $this->state(['code' => PaymentCategory::INFAK, 'name' => 'Infak', 'billing_cycle' => BillingCycle::Monthly, 'is_installable' => false]);
    }

    public function dpp(): static
    {
        return $this->state(['code' => PaymentCategory::DPP, 'name' => 'DPP', 'billing_cycle' => BillingCycle::OneTime, 'is_installable' => true]);
    }

    public function building(): static
    {
        return $this->state(['code' => PaymentCategory::BUILDING, 'name' => 'Bangunan', 'billing_cycle' => BillingCycle::OneTime, 'is_installable' => true]);
    }

    public function uniform(): static
    {
        return $this->state(['code' => PaymentCategory::UNIFORM, 'name' => 'Seragam', 'billing_cycle' => BillingCycle::Itemized, 'is_installable' => false]);
    }

    /**
     * Switch the category on for the given levels.
     *
     * @param  iterable<Level>  $levels
     */
    public function activeFor(iterable $levels): static
    {
        return $this->afterCreating(function (PaymentCategory $category) use ($levels): void {
            foreach ($levels as $level) {
                $category->levels()->syncWithoutDetaching([$level->id => ['is_active' => true]]);
            }
        });
    }
}
