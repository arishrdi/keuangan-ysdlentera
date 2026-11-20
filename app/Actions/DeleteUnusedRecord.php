<?php

namespace App\Actions;

use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\PaymentChannel;
use App\Models\Student;
use App\Models\TransactionCategory;
use App\Models\UniformItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deletes a master record that was entered by mistake. A record that any transaction,
 * bill or history row still points at is refused: it should be deactivated instead, so
 * the financial history never loses its references.
 */
class DeleteUnusedRecord
{
    /**
     * Columns that point at each model, as [table, column] pairs.
     *
     * @var array<class-string<Model>, list<array{0: string, 1: string}>>
     */
    private const REFERENCES = [
        AcademicYear::class => [
            ['classrooms', 'academic_year_id'],
            ['enrollments', 'academic_year_id'],
            ['bills', 'academic_year_id'],
            ['fee_rates', 'academic_year_id'],
        ],
        CashAccount::class => [
            ['cash_transactions', 'cash_account_id'],
            ['payment_channels', 'cash_account_id'],
        ],
        PaymentChannel::class => [
            ['payments', 'payment_channel_id'],
        ],
        TransactionCategory::class => [
            ['cash_transactions', 'transaction_category_id'],
            ['budget_proposals', 'transaction_category_id'],
        ],
        UniformItem::class => [
            ['bill_items', 'uniform_item_id'],
        ],
        Student::class => [
            ['bills', 'student_id'],
            ['payments', 'student_id'],
            ['savings_transactions', 'student_id'],
        ],
        User::class => [
            ['payments', 'submitted_by'],
            ['payments', 'verified_by'],
            ['payments', 'cancelled_by'],
            ['bills', 'created_by'],
            ['cash_transactions', 'created_by'],
            ['cash_transactions', 'cancelled_by'],
            ['savings_transactions', 'created_by'],
            ['savings_transactions', 'cancelled_by'],
            ['budget_proposals', 'created_by'],
            ['budget_approvals', 'user_id'],
        ],
    ];

    /**
     * @throws ValidationException when the record is still referenced or cannot be removed.
     */
    public function handle(Model $record): void
    {
        if ($record instanceof AcademicYear && $record->is_active) {
            throw ValidationException::withMessages(['delete' => 'Tahun ajaran yang sedang aktif tidak bisa dihapus.']);
        }

        if ($record instanceof User && $record->is(auth()->user())) {
            throw ValidationException::withMessages(['delete' => 'Anda tidak bisa menghapus akun sendiri.']);
        }

        if ($this->isReferenced($record)) {
            throw ValidationException::withMessages(['delete' => 'Data ini sudah dipakai di transaksi atau riwayat, jadi tidak bisa dihapus. Nonaktifkan saja.']);
        }

        DB::transaction(fn () => $record->delete());
    }

    public function isReferenced(Model $record): bool
    {
        foreach (self::REFERENCES[$record::class] ?? [] as [$table, $column]) {
            if (DB::table($table)->where($column, $record->getKey())->exists()) {
                return true;
            }
        }

        return false;
    }
}
