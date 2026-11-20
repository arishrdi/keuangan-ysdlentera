<?php

namespace App\Actions\Cash;

use App\Enums\BudgetAction;
use App\Enums\BudgetStatus;
use App\Enums\CashDirection;
use App\Enums\CashTransactionType;
use App\Enums\PaymentMethod;
use App\Enums\TransactionCategoryType;
use App\Models\ActivityLog;
use App\Models\BudgetProposal;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\DocumentSequence;
use App\Models\Level;
use App\Models\Payment;
use App\Models\TransactionCategory;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;

/**
 * Writes every movement of money into the cash book of a level.
 *
 * Entries are append-only: cancelling adds an opposite entry, so balances can always be traced.
 * Every entry belongs to a level. A shared account (no level) holds a separate balance for each level,
 * and money can only leave an account when the level's balance in it covers the amount.
 * The level defaults to the account's own level and must be given for a shared account.
 */
class CashLedger
{
    /**
     * Record money received outside student bills, e.g. a donation.
     */
    public function recordIncome(
        CashAccount $account,
        TransactionCategory $category,
        int $amount,
        CarbonInterface $transactedOn,
        string $description,
        ?string $counterparty = null,
        ?UploadedFile $proof = null,
        ?Level $level = null,
    ): CashTransaction {
        $this->ensureCategoryType($category, TransactionCategoryType::Income);

        return $this->record($account, $this->levelFor($account, $level), CashTransactionType::Income, CashDirection::In, $amount, $transactedOn, $description, [
            'counterparty' => $counterparty,
            'transaction_category_id' => $category->id,
            'proof_path' => $proof?->store('cash-proofs', 'local'),
        ]);
    }

    /**
     * Record money spent from an account, optionally as realization of an approved budget.
     * Spending against a budget may not exceed what is left of it.
     */
    public function recordExpense(
        CashAccount $account,
        TransactionCategory $category,
        int $amount,
        CarbonInterface $transactedOn,
        string $description,
        ?string $counterparty = null,
        ?UploadedFile $proof = null,
        ?BudgetProposal $budget = null,
        ?Level $level = null,
    ): CashTransaction {
        $this->ensureCategoryType($category, TransactionCategoryType::Expense);
        $level = $this->levelFor($account, $level);

        return DB::transaction(function () use ($account, $level, $category, $amount, $transactedOn, $description, $counterparty, $proof, $budget): CashTransaction {
            if ($budget !== null) {
                $budget = BudgetProposal::query()->lockForUpdate()->findOrFail($budget->id);

                if (! $budget->status->acceptsExpenses() || $budget->level_id !== $level->id) {
                    throw ValidationException::withMessages(['budgetId' => 'Anggaran harus sudah disetujui dan berasal dari jenjang yang sama.']);
                }

                if ($amount > $budget->remainingAmount()) {
                    throw ValidationException::withMessages([
                        'amount' => 'Melebihi sisa anggaran '.$budget->number.' ('.Number::rupiah($budget->remainingAmount()).').',
                    ]);
                }
            }

            $expense = $this->record($account, $level, CashTransactionType::Expense, CashDirection::Out, $amount, $transactedOn, $description, [
                'counterparty' => $counterparty,
                'transaction_category_id' => $category->id,
                'budget_proposal_id' => $budget?->id,
                'proof_path' => $proof?->store('cash-proofs', 'local'),
            ]);

            if ($budget?->status === BudgetStatus::Approved) {
                $budget->update(['status' => BudgetStatus::Disbursing]);
                $budget->approvals()->create([
                    'user_id' => Auth::id(),
                    'role' => Auth::user()?->role,
                    'action' => BudgetAction::DisbursementStarted,
                    'notes' => 'Pengeluaran pertama '.$expense->number,
                    'total_amount' => $budget->total_amount,
                ]);
            }

            return $expense;
        });
    }

    /**
     * Move a level's money between two of its accounts, e.g. depositing cash into the (shared) bank account.
     *
     * @return array{out: CashTransaction, in: CashTransaction}
     */
    public function transfer(CashAccount $from, CashAccount $to, int $amount, CarbonInterface $transactedOn, string $description, ?Level $level = null): array
    {
        $level ??= $from->level ?? $to->level;

        if ($level === null || $from->is($to) || ! $from->isAvailableTo($level) || ! $to->isAvailableTo($level)) {
            throw ValidationException::withMessages(['toAccountId' => 'Pilih akun tujuan lain pada jenjang yang sama.']);
        }

        return DB::transaction(function () use ($from, $to, $level, $amount, $transactedOn, $description): array {
            $out = $this->record($from, $level, CashTransactionType::Transfer, CashDirection::Out, $amount, $transactedOn, $description.' (ke '.$to->name.')');
            $in = $this->record($to, $level, CashTransactionType::Transfer, CashDirection::In, $amount, $transactedOn, $description.' (dari '.$from->name.')', [
                'related_transaction_id' => $out->id,
            ]);

            $out->update(['related_transaction_id' => $in->id]);

            return ['out' => $out, 'in' => $in];
        });
    }

    /**
     * Record the balance an account already held when the system went live.
     * Allowed once per account, or once per level for a shared account.
     */
    public function recordOpeningBalance(CashAccount $account, int $amount, CarbonInterface $transactedOn, ?Level $level = null): CashTransaction
    {
        $level = $this->levelFor($account, $level);

        $alreadyRecorded = $account->transactions()
            ->whereBelongsTo($level)
            ->where('type', CashTransactionType::OpeningBalance)
            ->effective()
            ->exists();

        if ($alreadyRecorded) {
            throw ValidationException::withMessages(['openingAmount' => 'Saldo awal akun ini sudah pernah dicatat.']);
        }

        return $this->record($account, $level, CashTransactionType::OpeningBalance, CashDirection::In, $amount, $transactedOn, 'Saldo awal '.$account->name.($account->isShared() ? ' ('.$level->name.')' : ''));
    }

    /**
     * Put a verified student payment into the cash box or the bank account behind its payment channel.
     */
    public function recordStudentPayment(Payment $payment): CashTransaction
    {
        $payment->loadMissing('level', 'student', 'channel');

        $account = $payment->method === PaymentMethod::Cash
            ? CashAccount::cashBoxFor($payment->level)
            : $payment->channel?->cashAccount;

        if ($account === null || ! $account->isAvailableTo($payment->level)) {
            throw ValidationException::withMessages([
                'payment' => 'Rekening/QRIS tujuan belum dihubungkan ke akun kas. Minta admin menghubungkannya di menu Rekening & QRIS.',
            ]);
        }

        return $this->record($account, $payment->level, CashTransactionType::StudentPayment, CashDirection::In, $payment->amount, $payment->paid_on, 'Pembayaran '.$payment->student->name.' ('.$payment->receipt_number.')', [
            'payment_id' => $payment->id,
            'counterparty' => $payment->student->name,
        ], log: false);
    }

    /**
     * Cancel an entry by adding its opposite. Transfers are cancelled on both sides.
     * Student payments must be cancelled from the payment itself.
     */
    public function cancel(CashTransaction $transaction, User $actor, string $reason, bool $fromPayment = false): void
    {
        if ($transaction->isReversal() || $transaction->isCancelled()) {
            throw ValidationException::withMessages(['cancellationReason' => 'Transaksi ini sudah dibatalkan.']);
        }

        if ($transaction->type === CashTransactionType::StudentPayment && ! $fromPayment) {
            throw ValidationException::withMessages(['cancellationReason' => 'Batalkan pembayaran siswa melalui menu Pembayaran.']);
        }

        DB::transaction(function () use ($transaction, $actor, $reason): void {
            $legs = $transaction->type === CashTransactionType::Transfer && $transaction->relatedTransaction !== null
                ? [$transaction, $transaction->relatedTransaction]
                : [$transaction];

            foreach ($legs as $leg) {
                $this->record($leg->cashAccount, $leg->level, $leg->type, $leg->direction->opposite(), $leg->amount, now(), 'Pembatalan '.$leg->number.': '.$reason, [
                    'reversal_of_id' => $leg->id,
                    'transaction_category_id' => $leg->transaction_category_id,
                    'payment_id' => $leg->payment_id,
                    'budget_proposal_id' => $leg->budget_proposal_id,
                ], log: false, errorKey: 'cancellationReason');

                $leg->update(['cancelled_at' => now(), 'cancelled_by' => $actor->id, 'cancellation_reason' => $reason]);
            }

            ActivityLog::record('cash.cancelled', 'Membatalkan transaksi kas '.$transaction->number, $transaction, ['reason' => $reason]);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function record(
        CashAccount $account,
        Level $level,
        CashTransactionType $type,
        CashDirection $direction,
        int $amount,
        CarbonInterface $transactedOn,
        string $description,
        array $attributes = [],
        bool $log = true,
        string $errorKey = 'amount',
    ): CashTransaction {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Nominal harus lebih dari nol.']);
        }

        if (! $account->isAvailableTo($level)) {
            throw ValidationException::withMessages([$errorKey => 'Akun '.$account->name.' bukan milik jenjang '.$level->name.'.']);
        }

        return DB::transaction(function () use ($account, $level, $type, $direction, $amount, $transactedOn, $description, $attributes, $log, $errorKey): CashTransaction {
            $account = CashAccount::query()->lockForUpdate()->findOrFail($account->id);
            $balance = $account->balance(level: $level);

            if ($direction === CashDirection::Out && $balance < $amount) {
                throw ValidationException::withMessages([
                    $errorKey => 'Saldo '.$account->name.($account->isShared() ? ' bagian '.$level->name : '').' tidak cukup (saldo '.Number::rupiah($balance).').',
                ]);
            }

            $transaction = CashTransaction::query()->create([
                ...$attributes,
                'number' => DocumentSequence::next(DocumentSequence::CASH_TRANSACTION, $level, $transactedOn->year),
                'level_id' => $level->id,
                'cash_account_id' => $account->id,
                'type' => $type,
                'direction' => $direction,
                'amount' => $amount,
                'transacted_on' => $transactedOn->toDateString(),
                'description' => $description,
                'created_by' => Auth::id(),
            ]);

            if ($log) {
                ActivityLog::record('cash.recorded', 'Mencatat '.mb_strtolower($type->label()).' '.$transaction->number.' sebesar '.Number::rupiah($amount), $transaction);
            }

            return $transaction;
        });
    }

    /**
     * The level an entry belongs to: the given one, or the account's own level.
     */
    private function levelFor(CashAccount $account, ?Level $level): Level
    {
        $level ??= $account->level;

        if ($level === null) {
            throw ValidationException::withMessages(['accountId' => 'Pilih jenjang untuk transaksi pada akun bersama.']);
        }

        return $level;
    }

    private function ensureCategoryType(TransactionCategory $category, TransactionCategoryType $type): void
    {
        if ($category->type !== $type || ! $category->is_active) {
            throw ValidationException::withMessages(['categoryId' => 'Pilih kategori '.mb_strtolower($type->label()).' yang aktif.']);
        }
    }
}
