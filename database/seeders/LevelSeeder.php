<?php

namespace Database\Seeders;

use App\Models\Level;
use Illuminate\Database\Seeder;

class LevelSeeder extends Seeder
{
    /**
     * Seed the three education levels of the foundation.
     */
    public function run(): void
    {
        foreach ([['PAUD', 'PAUD'], ['SD', 'SD'], ['SMP', 'SMP']] as $index => [$code, $name]) {
            Level::query()->updateOrCreate(['code' => $code], ['name' => $name, 'sort_order' => $index + 1]);
        }
    }
}
