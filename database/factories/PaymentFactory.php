<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'level_id' => fn (array $attributes) => Student::query()->findOrFail((int) $attributes['student_id'])->level_id,
            'method' => PaymentMethod::Cash,
            'amount' => fake()->numberBetween(10, 500) * 1_000,
            'paid_on' => now()->toDateString(),
            'status' => PaymentStatus::Pending,
        ];
    }
}
