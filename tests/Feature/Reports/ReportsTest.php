<?php

use App\Actions\Reports\ArrearsReport;
use App\Actions\Reports\BudgetRealizationReport;
use App\Actions\Reports\CashMutationReport;
use App\Actions\Reports\ConsolidatedReport;
use App\Actions\Reports\ExpenseReport;
use App\Actions\Reports\IncomeReport;
use App\Actions\Reports\PaymentRecapReport;
use App\Actions\Reports\ReportFilters;
use App\Actions\Reports\SavingsReport;
use App\Enums\BillStatus;
use App\Enums\BudgetStatus;
use App\Enums\CashDirection;
use App\Enums\CashTransactionType;
use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\BudgetProposal;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Level;
use App\Models\PaymentCategory;
use App\Models\SavingsTransaction;
use App\Models\Student;
use App\Models\TransactionCategory;
use Carbon\CarbonImmutable;

function novemberFilters(?Level $level = null, array $extra = []): ReportFilters
{
    return ReportFilters::fromArray(['dari' => '2026-11-01', 'sampai' => '2026-11-30', ...$extra], $level);
}

it('lists income of the period and leaves out cancelled entries, transfers and other levels', function () {
    $sd = Level::factory()->sd()->create();
    $account = CashAccount::factory()->for($sd)->create();
    $donation = TransactionCategory::factory()->income()->create(['name' => 'Donasi']);
    CashTransaction::factory()->for($account)->create(['type' => CashTransactionType::StudentPayment, 'amount' => 250_000, 'transacted_on' => '2026-11-05']);
    CashTransaction::factory()->for($account)->create(['transaction_category_id' => $donation->id, 'amount' => 1_000_000, 'transacted_on' => '2026-11-10']);
    CashTransaction::factory()->for($account)->create(['amount' => 500_000, 'transacted_on' => '2026-11-11', 'cancelled_at' => now()]);
    CashTransaction::factory()->for($account)->create(['type' => CashTransactionType::Transfer, 'amount' => 700_000, 'transacted_on' => '2026-11-12']);
    CashTransaction::factory()->for($account)->create(['amount' => 300_000, 'transacted_on' => '2026-10-31']);
    CashTransaction::factory()->for(CashAccount::factory()->for(Level::factory()->smp()))->create(['amount' => 900_000, 'transacted_on' => '2026-11-05']);

    $table = (new IncomeReport)->build(novemberFilters($sd));

    expect($table->rows)->toHaveCount(2)
        ->and($table->totals['amount'])->toBe(1_250_000)
        ->and(collect($table->summary)->pluck('value', 'label')->all())->toBe(['Pembayaran siswa' => 'Rp250.000', 'Donasi' => 'Rp1.000.000']);
});

it('filters income to student payments only', function () {
    $account = CashAccount::factory()->create();
    CashTransaction::factory()->for($account)->create(['type' => CashTransactionType::StudentPayment, 'amount' => 250_000, 'transacted_on' => '2026-11-05']);
    CashTransaction::factory()->for($account)->create(['amount' => 1_000_000, 'transacted_on' => '2026-11-10']);

    $table = (new IncomeReport)->build(novemberFilters(extra: ['kategori' => -1]));

    expect($table->totals['amount'])->toBe(250_000);
});

it('shows the budget an expense was charged to', function () {
    $account = CashAccount::factory()->create();
    $budget = BudgetProposal::factory()->status(BudgetStatus::Disbursing)->create(['number' => 'AGR/SD/2026/0007']);
    CashTransaction::factory()->for($account)->create(['type' => CashTransactionType::Expense, 'direction' => CashDirection::Out, 'amount' => 400_000, 'transacted_on' => '2026-11-03', 'budget_proposal_id' => $budget->id]);

    $table = (new ExpenseReport)->build(novemberFilters());

    expect($table->rows[0]['budget'])->toBe('AGR/SD/2026/0007')
        ->and($table->totals['amount'])->toBe(400_000);
});

it('starts the cash book from the balance before the period and keeps a running balance', function () {
    $account = CashAccount::factory()->create();
    CashTransaction::factory()->for($account)->create(['amount' => 1_000_000, 'transacted_on' => '2026-10-20']);
    CashTransaction::factory()->for($account)->create(['amount' => 300_000, 'transacted_on' => '2026-11-02']);
    CashTransaction::factory()->for($account)->create(['amount' => 100_000, 'direction' => CashDirection::Out, 'transacted_on' => '2026-11-04']);

    $table = (new CashMutationReport)->build(novemberFilters(extra: ['akun' => $account->id]));

    expect(array_column($table->rows, 'balance'))->toBe([1_000_000, 1_300_000, 1_200_000])
        ->and($table->totals)->toMatchArray(['in' => 300_000, 'out' => 100_000, 'balance' => 1_200_000]);
});

it('recaps billed, received and outstanding per category without cancelled bills', function () {
    $sd = Level::factory()->sd()->create();
    $year = AcademicYear::factory()->active()->create();
    $spp = PaymentCategory::factory()->spp()->create();
    $student = Student::factory()->for($sd)->create();
    Bill::factory()->forStudent($student)->for($spp)->for($year)->create(['amount' => 300_000, 'paid_amount' => 300_000, 'status' => BillStatus::Paid]);
    Bill::factory()->forStudent($student)->for($spp)->for($year)->create(['amount' => 300_000, 'discount_amount' => 100_000]);
    Bill::factory()->forStudent($student)->for($spp)->for($year)->create(['amount' => 999_000, 'status' => BillStatus::Cancelled]);

    $table = (new PaymentRecapReport)->build(ReportFilters::fromArray(['tahun' => $year->id], $sd));

    expect($table->rows[0])->toMatchArray([
        'category' => 'SPP',
        'bills' => 2,
        'gross' => 600_000,
        'discount' => 100_000,
        'received' => 300_000,
        'outstanding' => 200_000,
        'paid_rate' => 60.0,
    ]);
});

it('lists students with overdue unpaid bills and their total', function () {
    $student = Student::factory()->create(['name' => 'Budi Menunggak']);
    Bill::factory()->forStudent($student)->create(['amount' => 250_000, 'due_date' => '2026-09-10']);
    Bill::factory()->forStudent($student)->create(['amount' => 250_000, 'paid_amount' => 100_000, 'status' => BillStatus::Partial, 'due_date' => '2026-10-10']);
    Bill::factory()->forStudent($student)->create(['amount' => 250_000, 'due_date' => '2026-12-10']);
    Bill::factory()->create(['amount' => 250_000, 'paid_amount' => 250_000, 'status' => BillStatus::Paid, 'due_date' => '2026-09-10']);

    $table = (new ArrearsReport)->build(novemberFilters());

    expect($table->rows)->toHaveCount(1)
        ->and($table->rows[0])->toMatchArray(['student' => 'Budi Menunggak', 'bills' => 2, 'outstanding' => 400_000])
        ->and($table->rows[0]['oldest_due']->toDateString())->toBe('2026-09-10');
});

it('splits savings into opening balance, deposits and withdrawals of the period', function () {
    $student = Student::factory()->create();
    SavingsTransaction::factory()->for($student)->create(['amount' => 50_000, 'transacted_on' => '2026-10-01']);
    SavingsTransaction::factory()->for($student)->create(['amount' => 20_000, 'transacted_on' => '2026-11-03']);
    SavingsTransaction::factory()->for($student)->create(['amount' => 30_000, 'direction' => CashDirection::Out, 'transacted_on' => '2026-11-04']);
    SavingsTransaction::factory()->for($student)->create(['amount' => 99_000, 'transacted_on' => '2026-12-01']);
    Student::factory()->create();

    $table = (new SavingsReport)->build(novemberFilters());

    expect($table->rows)->toHaveCount(1)
        ->and($table->rows[0])->toMatchArray(['opening' => 50_000, 'deposits' => 20_000, 'withdrawals' => 30_000, 'closing' => 40_000]);
});

it('reports approved and realized amounts of budgets submitted in the period', function () {
    $this->travelTo('2026-11-15');
    $account = CashAccount::factory()->create();
    $approved = BudgetProposal::factory()->status(BudgetStatus::Disbursing)->withTotal(5_000_000)->create(['submitted_at' => '2026-11-02']);
    CashTransaction::factory()->for($account)->create(['type' => CashTransactionType::Expense, 'direction' => CashDirection::Out, 'amount' => 2_000_000, 'budget_proposal_id' => $approved->id]);
    BudgetProposal::factory()->status(BudgetStatus::AwaitingPrincipal)->withTotal(1_000_000)->create(['submitted_at' => '2026-11-05']);
    BudgetProposal::factory()->withTotal(9_000_000)->create();

    $table = (new BudgetRealizationReport)->build(novemberFilters());

    expect($table->rows)->toHaveCount(2)
        ->and($table->totals)->toMatchArray(['proposed' => 6_000_000, 'approved' => 5_000_000, 'realized' => 2_000_000, 'remaining' => 3_000_000, 'realization_rate' => 40.0]);
});

it('consolidates every level into one row each plus a combined total', function () {
    $this->travelTo('2026-11-15');
    $year = AcademicYear::factory()->active()->create();
    $sd = Level::factory()->sd()->create();
    $smp = Level::factory()->smp()->create();
    Student::factory()->count(2)->for($sd)->create();
    Student::factory()->for($smp)->create();
    CashTransaction::factory()->for(CashAccount::factory()->for($sd))->create(['amount' => 1_000_000, 'transacted_on' => '2026-11-05']);
    CashTransaction::factory()->for(CashAccount::factory()->for($smp))->create(['amount' => 500_000, 'transacted_on' => '2026-11-06']);

    $table = (new ConsolidatedReport)->build(ReportFilters::fromArray(['dari' => '2026-11-01', 'sampai' => '2026-11-30', 'tahun' => $year->id], null));

    expect(array_column($table->rows, 'level'))->toBe(['SD', 'SMP'])
        ->and(array_column($table->rows, 'income'))->toBe([1_000_000, 500_000])
        ->and($table->totals)->toMatchArray(['level' => 'Gabungan', 'students' => 3, 'income' => 1_500_000, 'cash' => 1_500_000]);
});

it('falls back to the current month for missing or invalid dates', function () {
    $this->travelTo('2026-11-20');

    $filters = ReportFilters::fromArray(['dari' => 'bukan-tanggal'], null);

    expect($filters->from->toDateString())->toBe('2026-11-01')
        ->and($filters->until->toDateString())->toBe('2026-11-30');
});

it('never ends a period before it starts', function () {
    $filters = ReportFilters::fromArray(['dari' => '2026-11-10', 'sampai' => '2026-11-01'], null);

    expect($filters->until->toDateString())->toBe('2026-11-10')
        ->and($filters->from)->toBeInstanceOf(CarbonImmutable::class);
});
