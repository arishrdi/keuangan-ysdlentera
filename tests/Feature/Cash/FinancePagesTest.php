<?php

use App\Enums\CashTransactionType;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Level;
use App\Models\TransactionCategory;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('opens the finance pages for finance staff only', function (string $route) {
    $this->actingAs(User::factory()->principal()->create())->withSession([Level::SESSION_KEY => Level::ALL])->get(route($route))->assertOk();
    $this->actingAs(User::factory()->admin()->create())->get(route($route))->assertForbidden();
    $this->actingAs(User::factory()->guardian()->create())->get(route($route))->assertForbidden();
})->with(['finance.incomes', 'finance.cash']);

it('opens the expense page for the foundation only', function () {
    $this->actingAs(User::factory()->foundation()->create())->withSession([Level::SESSION_KEY => Level::ALL])->get(route('finance.expenses'))->assertOk();

    foreach (['treasurer', 'principal', 'admin'] as $state) {
        $this->actingAs(User::factory()->{$state}()->create())->withSession([Level::SESSION_KEY => Level::ALL])->get(route('finance.expenses'))->assertForbidden();
    }
});

it('records an expense from the expense page', function () {
    $level = Level::factory()->sd()->create();
    $account = CashAccount::factory()->for($level)->create();
    CashTransaction::factory()->for($account)->create(['amount' => 1_000_000]);
    $category = TransactionCategory::factory()->expense()->create(['name' => 'ATK']);
    $this->actingAs(User::factory()->treasurer()->create());
    session([Level::SESSION_KEY => $level->id]);

    Livewire::test('finance.entry-list', ['kind' => 'pengeluaran'])
        ->call('create')
        ->set('accountId', $account->id)
        ->set('categoryId', $category->id)
        ->set('amount', 250_000)
        ->set('description', 'Kertas dan tinta')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Kertas dan tinta');

    expect($account->balance())->toBe(750_000);
});

it('shows the balance error on the amount field', function () {
    $level = Level::factory()->sd()->create();
    $account = CashAccount::factory()->for($level)->create();
    $this->actingAs(User::factory()->treasurer()->create());
    session([Level::SESSION_KEY => $level->id]);

    Livewire::test('finance.entry-list', ['kind' => 'pengeluaran'])
        ->call('create')
        ->set('accountId', $account->id)
        ->set('categoryId', TransactionCategory::factory()->expense()->create()->id)
        ->set('amount', 250_000)
        ->set('description', 'Belanja')
        ->call('save')
        ->assertHasErrors('amount');
});

it('asks the treasurer to pick a level before recording', function () {
    $this->actingAs(User::factory()->treasurer()->create());
    session([Level::SESSION_KEY => Level::ALL]);

    Livewire::test('finance.entry-list', ['kind' => 'pemasukan'])
        ->call('create')
        ->assertDispatched('toast', variant: 'warning', text: 'Pilih jenjang tertentu di kanan atas terlebih dahulu.');
});

it('lists student payments among incomes and excludes cancelled amounts from the total', function () {
    $account = CashAccount::factory()->create();
    CashTransaction::factory()->for($account)->create(['type' => CashTransactionType::StudentPayment, 'amount' => 300_000, 'description' => 'Pembayaran Budi']);
    CashTransaction::factory()->for($account)->create(['amount' => 200_000, 'cancelled_at' => now(), 'description' => 'Donasi batal']);
    $this->actingAs(User::factory()->foundation()->create());

    Livewire::test('finance.entry-list', ['kind' => 'pemasukan'])
        ->assertSee('Pembayaran Budi')
        ->assertSee('Donasi batal')
        ->assertSet('total', 300_000)
        ->assertDontSee('Catat pemasukan');
});

it('forbids the principal from recording or cancelling', function () {
    $entry = CashTransaction::factory()->create();
    $this->actingAs(User::factory()->principal()->create());

    Livewire::test('finance.entry-list', ['kind' => 'pemasukan'])->call('create')->assertForbidden();
    Livewire::test('finance.entry-list', ['kind' => 'pemasukan'])->call('confirmCancel', $entry->id)->assertForbidden();
});

it('cancels an income from the income page', function () {
    $account = CashAccount::factory()->create();
    $entry = CashTransaction::factory()->for($account)->create(['amount' => 200_000]);
    $this->actingAs(User::factory()->treasurer()->create());

    Livewire::test('finance.entry-list', ['kind' => 'pemasukan'])
        ->call('confirmCancel', $entry->id)
        ->set('cancellationReason', 'Salah input')
        ->call('cancel')
        ->assertHasNoErrors();

    expect($entry->refresh()->isCancelled())->toBeTrue()
        ->and($account->balance())->toBe(0);
});

it('shows a running balance on the cash page', function () {
    $this->travelTo('2026-11-19');
    $level = Level::factory()->sd()->create();
    $account = CashAccount::factory()->for($level)->create();
    CashTransaction::factory()->for($account)->create(['amount' => 1_000_000, 'transacted_on' => '2026-10-15']);
    CashTransaction::factory()->for($account)->create(['amount' => 500_000, 'transacted_on' => '2026-11-02']);
    CashTransaction::factory()->for($account)->create(['amount' => 200_000, 'direction' => 'keluar', 'transacted_on' => '2026-11-05']);
    $this->actingAs(User::factory()->principal()->create());
    session([Level::SESSION_KEY => $level->id]);

    $component = Livewire::test('pages::finance.cash');

    expect($component->get('openingBalance'))->toBe(1_000_000)
        ->and($component->instance()->mutations->pluck('running_balance')->all())->toBe([1_500_000, 1_300_000]);
});

it('transfers between accounts from the cash page', function () {
    $level = Level::factory()->sd()->create();
    $cashBox = CashAccount::factory()->for($level)->create();
    $bank = CashAccount::factory()->bank()->for($level)->create();
    CashTransaction::factory()->for($cashBox)->create(['amount' => 1_000_000]);
    $this->actingAs(User::factory()->treasurer()->create());
    session([Level::SESSION_KEY => $level->id]);

    Livewire::test('pages::finance.cash')
        ->call('openTransfer')
        ->set('fromAccountId', $cashBox->id)
        ->set('toAccountId', $bank->id)
        ->set('transferAmount', 2_000_000)
        ->call('transfer')
        ->assertHasErrors('transferAmount')
        ->set('transferAmount', 600_000)
        ->call('transfer')
        ->assertHasNoErrors();

    expect($bank->balance())->toBe(600_000);
});

it('lets staff open the proof of an entry but not guardians', function () {
    Storage::fake('local');
    Storage::disk('local')->put('cash-proofs/nota.jpg', 'nota');
    $entry = CashTransaction::factory()->create(['proof_path' => 'cash-proofs/nota.jpg']);

    $this->actingAs(User::factory()->foundation()->create())->get(route('finance.proof', $entry))->assertOk();
    $this->actingAs(User::factory()->guardian()->create())->get(route('finance.proof', $entry))->assertForbidden();
});
