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
     * Seed the data every installation needs: levels, categories, an active academic year and the staff accounts.
     */
    public function run(): void
    {
        $this->call([LevelSeeder::class, PaymentCategorySeeder::class, TransactionCategorySeeder::class]);

        if (! AcademicYear::query()->exists()) {
            $startYear = now()->month >= 7 ? now()->year : now()->year - 1;

            AcademicYear::query()->create([
                'name' => $startYear.'/'.($startYear + 1),
                'starts_on' => Carbon::create($startYear, 7, 1),
                'ends_on' => Carbon::create($startYear + 1, 6, 30),
                'is_active' => true,
            ]);
        }

        foreach ([
            ['admin', 'Administrator', Role::Admin],
            ['yayasan', 'Ketua Yayasan', Role::Foundation],
            ['kepsek', 'Kepala Sekolah', Role::Principal],
            ['bendahara', 'Bendahara', Role::Treasurer],
        ] as [$username, $name, $role]) {
            User::query()->firstOrCreate(['username' => $username], [
                'name' => $name,
                'password' => 'password',
                'role' => $role,
            ]);
        }

        if (app()->isLocal()) {
            $this->call(DemoSeeder::class);
        }
    }
}
