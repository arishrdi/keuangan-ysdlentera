<?php

namespace Database\Factories;

use App\Enums\PaymentChannelType;
use App\Models\CashAccount;
use App\Models\Level;
use App\Models\PaymentChannel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentChannel>
 */
class PaymentChannelFactory extends Factory
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
            'cash_account_id' => fn (array $attributes) => CashAccount::factory()->bank()->create(['level_id' => $attributes['level_id']])->id,
            'type' => PaymentChannelType::BankAccount,
            'name' => 'Rekening BSI',
            'bank_name' => 'BSI',
            'account_number' => fake()->numerify('##########'),
            'account_holder' => 'Yayasan Lentera',
            'is_active' => true,
        ];
    }

    public function qris(): static
    {
        return $this->state([
            'type' => PaymentChannelType::Qris,
            'name' => 'QRIS',
            'bank_name' => null,
            'account_number' => null,
            'account_holder' => null,
            'qris_image_path' => 'qris/contoh.png',
        ]);
    }
}
