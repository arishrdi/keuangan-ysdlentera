<?php

namespace App\Actions\Billing;

use App\Enums\BillStatus;
use App\Models\AcademicYear;
use App\Models\ActivityLog;
use App\Models\Bill;
use App\Models\PaymentCategory;
use App\Models\Student;
use App\Models\UniformItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create a single bill for one student: a manual bill (e.g. arrears from before the system)
 * or an itemized uniform bill.
 */
class CreateBill
{
    /**
     * @param  array<int, int>  $uniformQuantities  Quantities keyed by uniform item id, for uniform bills.
     */
    public function handle(
        Student $student,
        PaymentCategory $category,
        AcademicYear $academicYear,
        string $description,
        ?int $amount = null,
        ?CarbonInterface $dueDate = null,
        array $uniformQuantities = [],
    ): Bill {
        return DB::transaction(function () use ($student, $category, $academicYear, $description, $amount, $dueDate, $uniformQuantities): Bill {
            $items = $category->isUniform() ? $this->uniformItems($student, $uniformQuantities) : [];
            $total = $category->isUniform() ? array_sum(array_column($items, 'subtotal')) : (int) $amount;

            if ($total <= 0) {
                throw ValidationException::withMessages(['amount' => 'Nominal tagihan harus lebih dari nol.']);
            }

            $bill = Bill::query()->create([
                'student_id' => $student->id,
                'level_id' => $student->level_id,
                'academic_year_id' => $academicYear->id,
                'payment_category_id' => $category->id,
                'description' => $description,
                'amount' => $total,
                'due_date' => $dueDate?->toDateString(),
                'status' => BillStatus::Unpaid,
                'created_by' => Auth::id(),
            ]);

            $bill->items()->createMany($items);

            ActivityLog::record('bill.created', 'Membuat tagihan '.$description.' untuk '.$student->name, $bill, ['amount' => $total]);

            return $bill;
        });
    }

    /**
     * @param  array<int, int>  $quantities
     * @return array<int, array{uniform_item_id: int, name: string, size: string|null, quantity: int, unit_price: int, subtotal: int}>
     */
    private function uniformItems(Student $student, array $quantities): array
    {
        $quantities = array_filter($quantities, fn (int $quantity) => $quantity > 0);

        $catalog = UniformItem::query()
            ->where('level_id', $student->level_id)
            ->where('is_active', true)
            ->whereKey(array_keys($quantities))
            ->get();

        if ($catalog->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Pilih minimal satu item seragam.']);
        }

        return $catalog->map(fn (UniformItem $item) => [
            'uniform_item_id' => $item->id,
            'name' => $item->name,
            'size' => $item->size,
            'quantity' => $quantities[$item->id],
            'unit_price' => $item->price,
            'subtotal' => $item->price * $quantities[$item->id],
        ])->values()->all();
    }
}
