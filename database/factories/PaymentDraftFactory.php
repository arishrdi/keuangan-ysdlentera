<?php

namespace Database\Factories;

use App\Models\PaymentDraft;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentDraft>
 */
class PaymentDraftFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->guardian(),
            'student_id' => Student::factory(),
            'allocations' => [],
        ];
    }
}
