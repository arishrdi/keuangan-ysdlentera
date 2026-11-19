<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\AcademicYear;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the data every installation needs: levels, an active academic year and the first admin.
     */
    public function run(): void
    {
        $this->call(LevelSeeder::class);

        if (! AcademicYear::query()->exists()) {
            $startYear = now()->month >= 7 ? now()->year : now()->year - 1;

            AcademicYear::query()->create([
                'name' => $startYear.'/'.($startYear + 1),
                'starts_on' => Carbon::create($startYear, 7, 1),
                'ends_on' => Carbon::create($startYear + 1, 6, 30),
                'is_active' => true,
            ]);
        }

        User::query()->firstOrCreate(['username' => 'admin'], [
            'name' => 'Administrator',
            'password' => 'password',
            'role' => Role::Admin,
        ]);

        if (app()->isLocal()) {
            $this->call(DemoSeeder::class);
        }
    }
}
