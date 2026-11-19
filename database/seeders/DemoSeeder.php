<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Level;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    /**
     * Seed demo accounts, classrooms and students for local development.
     */
    public function run(): void
    {
        foreach ([
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

        $academicYear = AcademicYear::current();

        if ($academicYear === null || Student::query()->exists()) {
            return;
        }

        $classroomNames = [
            'PAUD' => [[0, 'TK A'], [0, 'TK B']],
            'SD' => [[1, '1A'], [2, '2A'], [6, '6A']],
            'SMP' => [[7, '7A'], [9, '9A']],
        ];

        foreach (Level::query()->ordered()->get() as $level) {
            foreach ($classroomNames[$level->code] ?? [] as [$grade, $name]) {
                $classroom = Classroom::query()->create([
                    'level_id' => $level->id,
                    'academic_year_id' => $academicYear->id,
                    'name' => $name,
                    'grade' => $grade,
                    'homeroom_teacher' => fake('id_ID')->name(),
                ]);

                Student::factory()
                    ->count(8)
                    ->for($level)
                    ->create()
                    ->each(fn (Student $student) => $student->enrollIn($classroom));
            }
        }

        User::factory()
            ->guardian()
            ->withStudents(Student::query()->inRandomOrder()->limit(2)->get())
            ->create(['name' => 'Wali Demo', 'phone' => '081234567890', 'email' => null]);
    }
}
