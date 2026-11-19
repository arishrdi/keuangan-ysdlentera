<?php

use App\Actions\Cash\CashLedger;
use App\Enums\BudgetStatus;
use App\Models\BudgetProposal;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Level;
use App\Models\TransactionCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * @return array{level: Level, account: CashAccount, category: TransactionCategory}
 */
function fundedLevel(int $balance = 50_000_000): array
{
    $level = Level::factory()->sd()->create();
    $account = CashAccount::factory()->for($level)->create();
    CashTransaction::factory()->for($account)->create(['amount' => $balance]);

    return ['level' => $level, 'account' => $account, 'category' => TransactionCategory::factory()->expense()->create()];
}

it('counts linked expenses as realization and starts disbursement on the first one', function () {
    $this->actingAs(User::factory()->treasurer()->create());
    ['level' => $level, 'account' => $account, 'category' => $category] = fundedLevel();
    $budget = BudgetProposal::factory()->for($level)->status(BudgetStatus::Approved)->withTotal(5_000_000)->create();

    app(CashLedger::class)->recordExpense($account, $category, 2_000_000, CarbonImmutable::today(), 'Beli laptop', budget: $budget);

    $budget->refresh();

    expect($budget->realizedAmount())->toBe(2_000_000)
        ->and($budget->remainingAmount())->toBe(3_000_000)
        ->and($budget->status)->toBe(BudgetStatus::Disbursing);
});

it('blocks an expense larger than what is left of the budget', function () {
    ['level' => $level, 'account' => $account, 'category' => $category] = fundedLevel();
    $budget = BudgetProposal::factory()->for($level)->status(BudgetStatus::Disbursing)->withTotal(5_000_000)->create();
    app(CashLedger::class)->recordExpense($account, $category, 4_000_000, CarbonImmutable::today(), 'Belanja', budget: $budget);

    app(CashLedger::class)->recordExpense($account, $category, 1_500_000, CarbonImmutable::today(), 'Lebih', budget: $budget);
})->throws(ValidationException::class, 'Melebihi sisa anggaran');

it('frees the budget again when a linked expense is cancelled', function () {
    ['level' => $level, 'account' => $account, 'category' => $category] = fundedLevel();
    $budget = BudgetProposal::factory()->for($level)->status(BudgetStatus::Disbursing)->withTotal(5_000_000)->create();
    $expense = app(CashLedger::class)->recordExpense($account, $category, 5_000_000, CarbonImmutable::today(), 'Belanja', budget: $budget);

    app(CashLedger::class)->cancel($expense, User::factory()->treasurer()->create(), 'Salah');

    expect($budget->refresh()->realizedAmount())->toBe(0);
});

it('refuses budgets that are not approved, completed, or from another level', function (string $case) {
    ['level' => $level, 'account' => $account, 'category' => $category] = fundedLevel();
    $budget = match ($case) {
        'awaiting approval' => BudgetProposal::factory()->for($level)->status(BudgetStatus::AwaitingFoundation)->withTotal(1_000_000)->create(),
        'completed' => BudgetProposal::factory()->for($level)->status(BudgetStatus::Completed)->withTotal(1_000_000)->create(),
        'another level' => BudgetProposal::factory()->for(Level::factory()->smp())->status(BudgetStatus::Approved)->withTotal(1_000_000)->create(),
    };

    expect(fn () => app(CashLedger::class)->recordExpense($account, $category, 100_000, CarbonImmutable::today(), 'Belanja', budget: $budget))
        ->toThrow(ValidationException::class, 'Anggaran harus sudah disetujui dan berasal dari jenjang yang sama.');
})->with(['awaiting approval', 'completed', 'another level']);
