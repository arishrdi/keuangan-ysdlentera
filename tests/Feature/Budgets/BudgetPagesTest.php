<?php

use App\Enums\BudgetStatus;
use App\Enums\TransactionCategoryType;
use App\Models\BudgetProposal;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Level;
use App\Models\TransactionCategory;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(fn () => Notification::fake());

it('opens budget pages for finance staff and forbids others', function () {
    $proposal = BudgetProposal::factory()->status(BudgetStatus::AwaitingPrincipal)->withTotal(1_000_000)->create();

    foreach (['treasurer', 'principal', 'foundation'] as $state) {
        $user = User::factory()->{$state}()->create();
        $this->actingAs($user)->withSession([Level::SESSION_KEY => Level::ALL])->get(route('budgets.index'))->assertOk();
        $this->actingAs($user)->withSession([Level::SESSION_KEY => Level::ALL])->get(route('budgets.show', $proposal))->assertOk();
    }

    $this->actingAs(User::factory()->admin()->create())->get(route('budgets.index'))->assertForbidden();
    $this->actingAs(User::factory()->guardian()->create())->get(route('budgets.show', $proposal))->assertForbidden();
});

it('lets only the treasurer open the proposal form', function () {
    $this->actingAs(User::factory()->principal()->create())
        ->withSession([Level::SESSION_KEY => Level::ALL])
        ->get(route('budgets.create'))
        ->assertForbidden();
});

it('hides drafts from approvers', function () {
    $draft = BudgetProposal::factory()->create(['title' => 'Draft rahasia']);
    $this->actingAs(User::factory()->principal()->create());

    Livewire::test('pages::budgets.index')->set('tab', '')->assertDontSee('Draft rahasia');
    $this->withSession([Level::SESSION_KEY => Level::ALL])->get(route('budgets.show', $draft))->assertNotFound();
});

it('opens the principal on the queue of proposals awaiting their decision', function () {
    BudgetProposal::factory()->status(BudgetStatus::AwaitingPrincipal)->withTotal(1_000_000)->create(['title' => 'Menunggu saya']);
    BudgetProposal::factory()->status(BudgetStatus::AwaitingFoundation)->withTotal(1_000_000)->create(['title' => 'Sudah lewat']);
    $this->actingAs(User::factory()->principal()->create());

    Livewire::test('pages::budgets.index')
        ->assertSet('tab', 'antrean')
        ->assertSet('queueCount', 1)
        ->assertSee('Menunggu saya')
        ->assertDontSee('Sudah lewat');
});

it('creates and submits a proposal from the form', function () {
    $level = Level::factory()->sd()->create();
    $category = TransactionCategory::factory()->create(['type' => TransactionCategoryType::Budget]);
    User::factory()->principal()->create();
    $this->actingAs(User::factory()->treasurer()->create());
    session([Level::SESSION_KEY => $level->id]);

    Livewire::test('pages::budgets.form')
        ->set('title', 'Pengadaan printer')
        ->set('categoryId', $category->id)
        ->set('reason', 'Printer rusak')
        ->set('items.0.name', 'Printer')
        ->set('items.0.quantity', 2)
        ->set('items.0.unit_price', 1_500_000)
        ->assertSet('total', 3_000_000)
        ->call('submit')
        ->assertHasNoErrors();

    $proposal = BudgetProposal::query()->sole();

    expect($proposal->status)->toBe(BudgetStatus::AwaitingPrincipal)
        ->and($proposal->total_amount)->toBe(3_000_000)
        ->and($proposal->level_id)->toBe($level->id);
});

it('validates every item row', function () {
    $level = Level::factory()->sd()->create();
    $this->actingAs(User::factory()->treasurer()->create());
    session([Level::SESSION_KEY => $level->id]);

    Livewire::test('pages::budgets.form')
        ->set('title', 'Tanpa harga')
        ->set('categoryId', TransactionCategory::factory()->create(['type' => TransactionCategoryType::Budget])->id)
        ->set('reason', 'Alasan')
        ->set('items.0.name', '')
        ->call('save')
        ->assertHasErrors(['items.0.name' => 'required', 'items.0.unit_price' => 'required']);
});

it('refuses to create a proposal while viewing all levels', function () {
    $this->actingAs(User::factory()->treasurer()->create());
    session([Level::SESSION_KEY => Level::ALL]);

    Livewire::test('pages::budgets.form')
        ->set('title', 'Judul')
        ->set('categoryId', TransactionCategory::factory()->create(['type' => TransactionCategoryType::Budget])->id)
        ->set('reason', 'Alasan')
        ->set('items.0.name', 'Barang')
        ->set('items.0.unit_price', 1000)
        ->call('save')
        ->assertHasErrors(['proposal' => 'Pilih jenjang tertentu di kanan atas terlebih dahulu.']);

    expect(BudgetProposal::query()->exists())->toBeFalse();
});

it('lets the principal approve from the detail page but not the treasurer', function () {
    $proposal = BudgetProposal::factory()->status(BudgetStatus::AwaitingPrincipal)->withTotal(1_000_000)->create();

    $this->actingAs(User::factory()->treasurer()->create());
    Livewire::test('pages::budgets.show', ['budgetProposal' => $proposal])
        ->assertDontSee('Keputusan tahap 1')
        ->call('decide', 'disetujui')
        ->assertForbidden();

    $this->actingAs(User::factory()->principal()->create());
    Livewire::test('pages::budgets.show', ['budgetProposal' => $proposal])
        ->assertSee('Keputusan tahap 1')
        ->call('decide', 'ditolak')
        ->assertHasErrors('notes')
        ->set('notes', 'Mohon rinci harga')
        ->call('decide', 'minta_revisi')
        ->assertHasNoErrors();

    expect($proposal->refresh()->status)->toBe(BudgetStatus::NeedsRevision);
});

it('charges an expense to a budget from the expense page', function () {
    $level = Level::factory()->sd()->create();
    $account = CashAccount::factory()->for($level)->create();
    CashTransaction::factory()->for($account)->create(['amount' => 10_000_000]);
    $budget = BudgetProposal::factory()->for($level)->status(BudgetStatus::Approved)->withTotal(3_000_000)->create();
    $this->actingAs(User::factory()->treasurer()->create());
    session([Level::SESSION_KEY => $level->id]);

    Livewire::withQueryParams(['anggaran' => $budget->id])
        ->test('finance.entry-list', ['kind' => 'pengeluaran'])
        ->assertSet('budgetId', $budget->id)
        ->set('accountId', $account->id)
        ->set('categoryId', TransactionCategory::factory()->expense()->create()->id)
        ->set('amount', 3_500_000)
        ->set('description', 'Belanja')
        ->call('save')
        ->assertHasErrors('amount')
        ->set('amount', 3_000_000)
        ->call('save')
        ->assertHasNoErrors();

    expect($budget->refresh()->remainingAmount())->toBe(0)
        ->and($budget->status)->toBe(BudgetStatus::Disbursing);
});

it('downloads attachments only for finance staff', function () {
    Storage::fake('local');
    Storage::disk('local')->put('budget-attachments/rab.pdf', 'rab');
    $proposal = BudgetProposal::factory()->status(BudgetStatus::AwaitingPrincipal)->create();
    $attachment = $proposal->attachments()->create(['path' => 'budget-attachments/rab.pdf', 'original_name' => 'RAB Laptop.pdf', 'size' => 3]);

    $this->actingAs(User::factory()->foundation()->create())
        ->get(route('budgets.attachment', [$proposal, $attachment]))
        ->assertOk()
        ->assertDownload('RAB Laptop.pdf');

    $this->actingAs(User::factory()->guardian()->create())
        ->get(route('budgets.attachment', [$proposal, $attachment]))
        ->assertForbidden();
});

it('records and cancels an expense from the budget detail page', function () {
    $level = Level::factory()->sd()->create();
    $account = CashAccount::factory()->for($level)->create();
    CashTransaction::factory()->for($account)->create(['amount' => 10_000_000]);
    $budget = BudgetProposal::factory()->for($level)->status(BudgetStatus::Approved)->withTotal(3_000_000)->create();
    $category = TransactionCategory::factory()->expense()->create();
    $this->actingAs(User::factory()->treasurer()->forLevel($level)->create());

    $component = Livewire::test('pages::budgets.show', ['budgetProposal' => $budget])
        ->call('openExpense')
        ->assertSet('expenseAccountId', $account->id)
        ->set('expenseCategoryId', $category->id)
        ->set('expenseAmount', 3_500_000)
        ->call('recordExpense')
        ->assertHasErrors('expenseAmount')
        ->set('expenseAmount', 1_000_000)
        ->call('recordExpense')
        ->assertHasNoErrors();

    $expense = $budget->expenses()->sole();

    expect($budget->refresh()->remainingAmount())->toBe(2_000_000)
        ->and($expense->level_id)->toBe($level->id);

    $component->call('confirmCancelExpense', $expense->id)
        ->set('expenseCancellationReason', 'Salah input')
        ->call('cancelExpense')
        ->assertHasNoErrors();

    expect($budget->refresh()->remainingAmount())->toBe(3_000_000);
});

it('does not let the principal record budget expenses', function () {
    $budget = BudgetProposal::factory()->status(BudgetStatus::Approved)->withTotal(1_000_000)->create();
    $this->actingAs(User::factory()->principal()->create());

    Livewire::test('pages::budgets.show', ['budgetProposal' => $budget])->call('openExpense')->assertForbidden();
});
