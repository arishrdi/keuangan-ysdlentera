<?php

namespace App\Actions\Savings;

use App\Enums\SavingsTransactionType;
use App\Enums\StudentStatus;
use App\Models\ActivityLog;
use App\Models\DocumentSequence;
use App\Models\SavingsTransaction;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;

/**
 * Records deposits and withdrawals of student savings. A balance can never go below zero
 * and entries are cancelled with an opposite entry instead of being deleted.
 */
class SavingsLedger
{
    public function deposit(Student $student, int $amount, CarbonInterface $transactedOn, ?string $notes = null): SavingsTransaction
    {
        if ($student->status !== StudentStatus::Active) {
            throw ValidationException::withMessages(['amount' => 'Setoran hanya untuk siswa aktif.']);
        }

        return $this->record($student, SavingsTransactionType::Deposit, $amount, $transactedOn, $notes);
    }

    public function withdraw(Student $student, int $amount, CarbonInterface $transactedOn, ?string $notes = null): SavingsTransaction
    {
        return $this->record($student, SavingsTransactionType::Withdrawal, $amount, $transactedOn, $notes);
    }

    /**
     * Record savings the student already had before the system went live. Allowed once per student.
     */
    public function recordOpeningBalance(Student $student, int $amount, CarbonInterface $transactedOn): SavingsTransaction
    {
        $alreadyRecorded = SavingsTransaction::query()
            ->where('student_id', $student->id)
            ->where('type', SavingsTransactionType::OpeningBalance)
            ->whereNull('reversal_of_id')
            ->whereNull('cancelled_at')
            ->exists();

        if ($alreadyRecorded) {
            throw ValidationException::withMessages(['amount' => 'Saldo awal tabungan siswa ini sudah pernah dicatat.']);
        }

        return $this->record($student, SavingsTransactionType::OpeningBalance, $amount, $transactedOn, 'Saldo awal');
    }

    /**
     * Cancel an entry by adding its opposite, e.g. a deposit typed for the wrong student.
     */
    public function cancel(SavingsTransaction $transaction, User $actor, string $reason): void
    {
        if ($transaction->isReversal() || $transaction->isCancelled()) {
            throw ValidationException::withMessages(['cancellationReason' => 'Transaksi ini sudah dibatalkan.']);
        }

        DB::transaction(function () use ($transaction, $actor, $reason): void {
            $this->record(
                $transaction->student,
                $transaction->type,
                $transaction->amount,
                now(),
                'Pembatalan '.$transaction->number.': '.$reason,
                reversalOf: $transaction,
                errorKey: 'cancellationReason',
            );

            $transaction->update(['cancelled_at' => now(), 'cancelled_by' => $actor->id, 'cancellation_reason' => $reason]);

            ActivityLog::record('savings.cancelled', 'Membatalkan transaksi tabungan '.$transaction->number, $transaction, ['reason' => $reason]);
        });
    }

    private function record(
        Student $student,
        SavingsTransactionType $type,
        int $amount,
        CarbonInterface $transactedOn,
        ?string $notes,
        ?SavingsTransaction $reversalOf = null,
        string $errorKey = 'amount',
    ): SavingsTransaction {
        if ($amount <= 0) {
            throw ValidationException::withMessages([$errorKey => 'Nominal harus lebih dari nol.']);
        }

        return DB::transaction(function () use ($student, $type, $amount, $transactedOn, $notes, $reversalOf, $errorKey): SavingsTransaction {
            $student = Student::query()->with('level')->lockForUpdate()->findOrFail($student->id);
            $direction = $reversalOf ? $reversalOf->direction->opposite() : $type->direction();
            $balance = $student->savingsBalance();

            if ($balance + $direction->sign() * $amount < 0) {
                throw ValidationException::withMessages([$errorKey => 'Saldo tabungan '.$student->name.' tidak cukup (saldo '.Number::rupiah($balance).').']);
            }

            $transaction = SavingsTransaction::query()->create([
                'number' => DocumentSequence::next(DocumentSequence::SAVINGS, $student->level, $transactedOn->year),
                'student_id' => $student->id,
                'level_id' => $student->level_id,
                'type' => $type,
                'direction' => $direction,
                'amount' => $amount,
                'transacted_on' => $transactedOn->toDateString(),
                'notes' => $notes,
                'reversal_of_id' => $reversalOf?->id,
                'created_by' => Auth::id(),
            ]);

            if ($reversalOf === null) {
                ActivityLog::record('savings.recorded', 'Mencatat '.mb_strtolower($type->label()).' tabungan '.$student->name.' sebesar '.Number::rupiah($amount), $transaction);
            }

            return $transaction;
        });
    }
}
