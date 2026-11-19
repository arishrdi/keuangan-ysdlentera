<?php

namespace Database\Seeders;

use App\Enums\TransactionCategoryType;
use App\Models\CashAccount;
use App\Models\Level;
use App\Models\TransactionCategory;
use Illuminate\Database\Seeder;

class TransactionCategorySeeder extends Seeder
{
    /**
     * Seed the default income, expense and budget categories and a cash box for every level.
     */
    public function run(): void
    {
        $categories = [
            TransactionCategoryType::Income->value => ['Donasi', 'Dana BOS', 'Hibah', 'Lain-lain'],
            TransactionCategoryType::Expense->value => ['Gaji & honor', 'Operasional', 'ATK', 'Sarana & prasarana', 'Kegiatan siswa', 'Listrik, air & internet', 'Lain-lain'],
            TransactionCategoryType::Budget->value => ['Operasional', 'Sarana & prasarana', 'Pengadaan barang', 'Kegiatan siswa', 'Pengembangan guru', 'Lain-lain'],
        ];

        foreach ($categories as $type => $names) {
            foreach ($names as $name) {
                TransactionCategory::query()->firstOrCreate(['type' => $type, 'name' => $name]);
            }
        }

        Level::query()->each(fn (Level $level) => CashAccount::cashBoxFor($level));
    }
}
