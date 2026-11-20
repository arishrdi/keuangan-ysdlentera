<?php

use App\Actions\Cash\CashLedger;
use App\Actions\Payments\VerifyPayment;
use App\Enums\CashAccountType;
use App\Enums\PaymentMethod;
use App\Models\Bill;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Level;
use App\Models\Payment;
use App\Models\PaymentChannel;
use App\Models\Student;
use App\Models\TransactionCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

it('keeps a separate balance for each level in a shared account', function () {
    $sd = Level::factory()->sd()->create();
    $smp = Level::factory()->smp()->create();
    $shared = CashAccount::factory()->bank()->shared()->create();
    $ledger = app(CashLedger::class);

    $ledger->recordOpeningBalance($shared, 1_000_000, CarbonImmutable::today(), $sd);
    $ledger->recordOpeningBalance($shared, 400_000, CarbonImmutable::today(), $smp);

    expect($shared->balance(level: $sd))->toBe(1_000_000)
        ->and($shared->balance(level: $smp))->toBe(400_000)
        ->and($shared->balance())->toBe(1_400_000)
        ->and(CashTransaction::query()->whereBelongsTo($sd)->sole()->number)->toStartWith('TRX/SD/');
});

it('lets a level spend only its own share of a shared account', function () {
    $sd = Level::factory()->sd()->create();
    $smp = Level::factory()->smp()->create();
    $shared = CashAccount::factory()->bank()->shared()->create();
    $ledger = app(CashLedger::class);
    $ledger->recordOpeningBalance($shared, 100_000, CarbonImmutable::today(), $sd);
    $ledger->recordOpeningBalance($shared, 5_000_000, CarbonImmutable::today(), $smp);

    $ledger->recordExpense($shared, TransactionCategory::factory()->expense()->create(), 150_000, CarbonImmutable::today(), 'Belanja', level: $sd);
})->throws(ValidationException::class, 'Saldo');

it('requires a level for entries in a shared account', function () {
    $shared = CashAccount::factory()->shared()->create();

    app(CashLedger::class)->recordIncome($shared, TransactionCategory::factory()->income()->create(), 50_000, CarbonImmutable::today(), 'Donasi');
})->throws(ValidationException::class, 'Pilih jenjang untuk transaksi pada akun bersama.');

it('refuses an entry for a level in an account of another level', function () {
    $account = CashAccount::factory()->for(Level::factory()->sd())->create();

    app(CashLedger::class)->recordIncome($account, TransactionCategory::factory()->income()->create(), 50_000, CarbonImmutable::today(), 'Donasi', level: Level::factory()->smp()->create());
})->throws(ValidationException::class);

it('transfers a level cash box into a shared bank account', function () {
    $sd = Level::factory()->sd()->create();
    $cashBox = CashAccount::cashBoxFor($sd);
    $shared = CashAccount::factory()->bank()->shared()->create();
    CashTransaction::factory()->for($cashBox)->create(['amount' => 1_000_000]);

    app(CashLedger::class)->transfer($cashBox, $shared, 600_000, CarbonImmutable::today(), 'Setor tunai', $sd);

    expect($cashBox->balance())->toBe(400_000)
        ->and($shared->balance(level: $sd))->toBe(600_000);
});

it('puts a verified transfer into a shared bank account under the payment level', function () {
    Notification::fake();
    $sd = Level::factory()->sd()->create();
    $student = Student::factory()->for($sd)->create();
    $shared = CashAccount::factory()->bank()->shared()->create();
    $channel = PaymentChannel::factory()->for($sd)->create(['cash_account_id' => $shared->id]);
    $bill = Bill::factory()->forStudent($student)->create(['amount' => 100_000]);
    $payment = Payment::factory()->for($student)->create(['amount' => 100_000, 'method' => PaymentMethod::Transfer, 'payment_channel_id' => $channel->id]);
    $payment->allocations()->create(['bill_id' => $bill->id, 'amount' => 100_000]);

    app(VerifyPayment::class)->handle($payment, User::factory()->treasurer()->create());

    expect($shared->balance(level: $sd))->toBe(100_000)
        ->and($payment->refresh()->cashTransaction->level_id)->toBe($sd->id);
});

it('adds a shared account from the admin page', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.cash-accounts')
        ->call('create')
        ->set('level_id', '')
        ->set('type', CashAccountType::Bank->value)
        ->set('name', 'BSI Yayasan')
        ->set('bank_name', 'BSI')
        ->set('account_number', '7100000009')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Semua jenjang');

    expect(CashAccount::query()->sole()->isShared())->toBeTrue();
});

it('shows only the active level share and its mutations on the cash page', function () {
    $sd = Level::factory()->sd()->create();
    $smp = Level::factory()->smp()->create();
    $shared = CashAccount::factory()->bank()->shared()->create(['name' => 'BSI Yayasan']);
    CashTransaction::factory()->for($shared)->for($sd)->create(['amount' => 300_000, 'description' => 'Masuk SD']);
    CashTransaction::factory()->for($shared)->for($smp)->create(['amount' => 700_000, 'description' => 'Masuk SMP']);
    $this->actingAs(User::factory()->treasurer()->create());
    session([Level::SESSION_KEY => $sd->id]);

    $page = Livewire::test('pages::finance.cash')
        ->assertSee('BSI Yayasan')
        ->assertSee('Masuk SD')
        ->assertDontSee('Masuk SMP');

    expect($page->instance()->accounts->firstWhere('id', $shared->id)->balance)->toBe(300_000);
});
