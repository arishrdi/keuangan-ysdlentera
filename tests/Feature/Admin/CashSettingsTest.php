<?php

use App\Enums\CashAccountType;
use App\Models\CashAccount;
use App\Models\Level;
use App\Models\TransactionCategory;
use App\Models\User;
use Livewire\Livewire;

it('opens cash settings for admins only', function (string $route) {
    $this->actingAs(User::factory()->admin()->create())->get(route($route))->assertOk();
    $this->actingAs(User::factory()->treasurer()->create())->withSession([Level::SESSION_KEY => Level::ALL])->get(route($route))->assertForbidden();
})->with(['admin.cash-accounts', 'admin.transaction-categories']);

it('adds a bank account to a level', function () {
    $this->actingAs(User::factory()->admin()->create());
    $level = Level::factory()->sd()->create();

    Livewire::test('pages::admin.cash-accounts')
        ->set('level_id', $level->id)
        ->set('type', CashAccountType::Bank->value)
        ->set('name', 'BSI SD')
        ->set('bank_name', 'BSI')
        ->set('account_number', '7100000002')
        ->call('save')
        ->assertHasNoErrors();

    expect(CashAccount::query()->sole())
        ->type->toBe(CashAccountType::Bank)
        ->level_id->toBe($level->id);
});

it('keeps account names unique within a level', function () {
    $this->actingAs(User::factory()->admin()->create());
    $level = Level::factory()->sd()->create();
    CashAccount::factory()->for($level)->create(['name' => 'Kas Tunai SD']);

    Livewire::test('pages::admin.cash-accounts')
        ->set('level_id', $level->id)
        ->set('type', CashAccountType::Cash->value)
        ->set('name', 'Kas Tunai SD')
        ->call('save')
        ->assertHasErrors(['name' => 'unique']);
});

it('adds and deactivates an expense category', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.transaction-categories')
        ->call('create', 'pengeluaran')
        ->set('name', 'Konsumsi rapat')
        ->call('save')
        ->assertHasNoErrors();

    $category = TransactionCategory::query()->sole();

    Livewire::test('pages::admin.transaction-categories')
        ->call('edit', $category->id)
        ->set('is_active', false)
        ->call('save');

    expect($category->refresh()->is_active)->toBeFalse();
});
