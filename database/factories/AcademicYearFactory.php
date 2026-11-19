<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<AcademicYear>
 */
class AcademicYearFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startYear = fake()->unique()->numberBetween(1950, 2019);

        return $this->forStartYear($startYear);
    }

    public function active(): static
    {
        return $this->state(['is_active' => true]);
    }

    public function startingIn(int $startYear): static
    {
        return $this->state(fn () => $this->forStartYear($startYear));
    }

    /**
     * @return array{name: string, starts_on: Carbon, ends_on: Carbon, is_active: bool}
     */
    private function forStartYear(int $startYear): array
    {
        return [
            'name' => $startYear.'/'.($startYear + 1),
            'starts_on' => Carbon::create($startYear, 7, 1),
            'ends_on' => Carbon::create($startYear + 1, 6, 30),
            'is_active' => false,
        ];
    }
}
