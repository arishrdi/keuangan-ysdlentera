<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\FeeRate;
use App\Models\Level;
use App\Models\PaymentCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeeRate>
 */
class FeeRateFactory extends Factory
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
            'payment_category_id' => PaymentCategory::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'grade' => null,
            'amount' => fake()->numberBetween(10, 500) * 1_000,
        ];
    }
}
