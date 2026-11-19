<?php

namespace Database\Seeders;

use App\Enums\BillingCycle;
use App\Models\Level;
use App\Models\PaymentCategory;
use Illuminate\Database\Seeder;

class PaymentCategorySeeder extends Seeder
{
    /**
     * Seed the five payment categories and switch them on for every level.
     */
    public function run(): void
    {
        $categories = [
            [PaymentCategory::INFAK, 'Infak', BillingCycle::Monthly, false],
            [PaymentCategory::DPP, 'DPP', BillingCycle::OneTime, true],
            [PaymentCategory::BUILDING, 'Bangunan', BillingCycle::OneTime, true],
            [PaymentCategory::UNIFORM, 'Seragam', BillingCycle::Itemized, false],
            [PaymentCategory::SPP, 'SPP', BillingCycle::Monthly, false],
        ];

        foreach ($categories as $index => [$code, $name, $cycle, $isInstallable]) {
            $category = PaymentCategory::query()->firstOrCreate(['code' => $code], [
                'name' => $name,
                'billing_cycle' => $cycle,
                'is_installable' => $isInstallable,
                'sort_order' => $index + 1,
            ]);

            $category->levels()->syncWithoutDetaching(
                Level::query()->pluck('id')->mapWithKeys(fn (int $levelId) => [$levelId => ['is_active' => true]])->all(),
            );
        }
    }
}
