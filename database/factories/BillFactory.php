<?php

namespace Database\Factories;

use App\Enums\BillStatus;
use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\PaymentCategory;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bill>
 */
class BillFactory extends Factory
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
            'academic_year_id' => AcademicYear::factory(),
            'payment_category_id' => PaymentCategory::factory(),
            'period' => null,
            'description' => 'Tagihan '.fake()->word(),
            'amount' => fake()->numberBetween(10, 500) * 1_000,
            'discount_amount' => 0,
            'paid_amount' => 0,
            'due_date' => now()->addMonth()->toDateString(),
            'status' => BillStatus::Unpaid,
        ];
    }

    public function forStudent(Student $student): static
    {
        return $this->state(['student_id' => $student->id, 'level_id' => $student->level_id]);
    }
}
