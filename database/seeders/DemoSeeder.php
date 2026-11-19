<?php

namespace Database\Seeders;

use App\Actions\Billing\IssueBills;
use App\Actions\Cash\CashLedger;
use App\Enums\CashAccountType;
use App\Enums\PaymentChannelType;
use App\Enums\Role;
use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\Classroom;
use App\Models\FeeRate;
use App\Models\Level;
use App\Models\PaymentCategory;
use App\Models\PaymentChannel;
use App\Models\Student;
use App\Models\TransactionCategory;
use App\Models\UniformItem;
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

        $this->seedBilling($academicYear);
    }

    /**
     * Seed fee rates, uniform items, payment channels and a few months of bills.
     */
    private function seedBilling(AcademicYear $academicYear): void
    {
        $rates = [
            'PAUD' => [PaymentCategory::SPP => 150_000, PaymentCategory::INFAK => 25_000, PaymentCategory::DPP => 1_500_000, PaymentCategory::BUILDING => 1_000_000],
            'SD' => [PaymentCategory::SPP => 250_000, PaymentCategory::INFAK => 30_000, PaymentCategory::DPP => 3_000_000, PaymentCategory::BUILDING => 2_000_000],
            'SMP' => [PaymentCategory::SPP => 350_000, PaymentCategory::INFAK => 40_000, PaymentCategory::DPP => 4_000_000, PaymentCategory::BUILDING => 2_500_000],
        ];

        $issueBills = app(IssueBills::class);
        $months = collect(range(0, 5))->map(fn (int $offset) => $academicYear->starts_on->copy()->addMonths($offset)->startOfMonth())->all();

        foreach (Level::query()->ordered()->get() as $level) {
            foreach ($rates[$level->code] as $code => $amount) {
                FeeRate::query()->create([
                    'level_id' => $level->id,
                    'payment_category_id' => PaymentCategory::query()->where('code', $code)->value('id'),
                    'academic_year_id' => $academicYear->id,
                    'amount' => $amount,
                ]);
            }

            foreach ([['Seragam batik', 120_000], ['Seragam olahraga', 95_000], ['Seragam muslim', 135_000]] as [$name, $price]) {
                UniformItem::query()->create(['level_id' => $level->id, 'name' => $name, 'price' => $price]);
            }

            $bankAccount = CashAccount::query()->create([
                'level_id' => $level->id,
                'type' => CashAccountType::Bank,
                'name' => 'BSI '.$level->name,
                'bank_name' => 'Bank Syariah Indonesia',
                'account_number' => '71'.str_pad((string) $level->id, 8, '0', STR_PAD_LEFT),
            ]);

            $ledger = app(CashLedger::class);
            $ledger->recordOpeningBalance(CashAccount::cashBoxFor($level), 5_000_000, $academicYear->starts_on);
            $ledger->recordOpeningBalance($bankAccount, 25_000_000, $academicYear->starts_on);
            $ledger->recordIncome($bankAccount, TransactionCategory::query()->where('name', 'Donasi')->firstOrFail(), 2_500_000, $academicYear->starts_on->addDays(20), 'Donasi wali murid untuk kegiatan', 'Hamba Allah');
            $ledger->recordExpense(CashAccount::cashBoxFor($level), TransactionCategory::query()->where('name', 'ATK')->firstOrFail(), 750_000, $academicYear->starts_on->addDays(25), 'Pembelian ATK semester ganjil', 'Toko Sinar Jaya');
            $ledger->transfer(CashAccount::cashBoxFor($level), $bankAccount, 1_000_000, $academicYear->starts_on->addDays(30), 'Setor tunai ke bank');

            PaymentChannel::query()->create([
                'level_id' => $level->id,
                'cash_account_id' => $bankAccount->id,
                'type' => PaymentChannelType::BankAccount,
                'name' => 'BSI '.$level->name.' Lentera',
                'bank_name' => 'Bank Syariah Indonesia',
                'account_number' => '71'.str_pad((string) $level->id, 8, '0', STR_PAD_LEFT),
                'account_holder' => 'Yayasan Lentera '.$level->name,
            ]);

            $students = Student::query()->active()->whereBelongsTo($level)->get();

            foreach ([PaymentCategory::SPP, PaymentCategory::INFAK, PaymentCategory::DPP] as $code) {
                $issueBills->handle(
                    category: PaymentCategory::query()->where('code', $code)->firstOrFail(),
                    level: $level,
                    academicYear: $academicYear,
                    students: $students,
                    periods: $months,
                    dueDate: $academicYear->starts_on->copy()->addMonths(3),
                );
            }
        }
    }
}
