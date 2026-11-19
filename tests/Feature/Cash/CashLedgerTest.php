<?php

use App\Actions\Cash\CashLedger;
use App\Enums\CashDirection;
use App\Enums\CashTransactionType;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Level;
use App\Models\TransactionCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

it('records income into the account and numbers it per level and year', function () {
    $this->travelTo('2026-11-19');
    $account = CashAccount::factory()->for(Level::factory()->sd())->create();

    $first = app(CashLedger::class)->recordIncome($account, TransactionCategory::factory()->income()->create(), 2_500_000, CarbonImmutable::today(), 'Donasi', 'Hamba Allah');
    $second = app(CashLedger::class)->recordIncome($account, TransactionCategory::factory()->income()->create(), 100_000, CarbonImmutable::today(), 'Donasi');

    expect($first->number)->toBe('TRX/SD/2026/0001')
        ->and($second->number)->toBe('TRX/SD/2026/0002')
        ->and($first->direction)->toBe(CashDirection::In)
        ->and($account->balance())->toBe(2_600_000);
});

it('stores the proof privately', function () {
    Storage::fake('local');
    $account = CashAccount::factory()->create();
    CashTransaction::factory()->for($account)->create(['amount' => 1_000_000]);

    $expense = app(CashLedger::class)->recordExpense($account, TransactionCategory::factory()->expense()->create(), 50_000, CarbonImmutable::today(), 'ATK', 'Toko', UploadedFile::fake()->image('nota.jpg'));

    Storage::disk('local')->assertExists($expense->proof_path);
});

it('refuses an expense larger than the account balance', function () {
    $account = CashAccount::factory()->create();
    CashTransaction::factory()->for($account)->create(['amount' => 100_000]);

    app(CashLedger::class)->recordExpense($account, TransactionCategory::factory()->expense()->create(), 150_000, CarbonImmutable::today(), 'Terlalu besar');
})->throws(ValidationException::class, 'tidak cukup');

it('refuses a category of the wrong type', function () {
    $account = CashAccount::factory()->create();

    app(CashLedger::class)->recordIncome($account, TransactionCategory::factory()->expense()->create(), 1_000, CarbonImmutable::today(), 'Salah kategori');
})->throws(ValidationException::class, 'Pilih kategori pemasukan yang aktif.');

it('moves money between accounts of the same level', function () {
    $level = Level::factory()->sd()->create();
    $cashBox = CashAccount::factory()->for($level)->create();
    $bank = CashAccount::factory()->bank()->for($level)->create();
    CashTransaction::factory()->for($cashBox)->create(['amount' => 1_000_000]);

    ['out' => $out, 'in' => $in] = app(CashLedger::class)->transfer($cashBox, $bank, 400_000, CarbonImmutable::today(), 'Setor tunai');

    expect($cashBox->balance())->toBe(600_000)
        ->and($bank->balance())->toBe(400_000)
        ->and($out->related_transaction_id)->toBe($in->id)
        ->and($in->related_transaction_id)->toBe($out->id);
});

it('refuses a transfer to an account of another level', function () {
    $cashBox = CashAccount::factory()->for(Level::factory()->sd())->create();
    CashTransaction::factory()->for($cashBox)->create(['amount' => 1_000_000]);

    app(CashLedger::class)->transfer($cashBox, CashAccount::factory()->for(Level::factory()->smp())->create(), 100_000, CarbonImmutable::today(), 'Salah');
})->throws(ValidationException::class);

it('records the opening balance only once per account', function () {
    $account = CashAccount::factory()->create();
    $ledger = app(CashLedger::class);

    $ledger->recordOpeningBalance($account, 5_000_000, CarbonImmutable::today());

    expect($account->balance())->toBe(5_000_000)
        ->and(fn () => $ledger->recordOpeningBalance($account, 1_000, CarbonImmutable::today()))
        ->toThrow(ValidationException::class, 'Saldo awal akun ini sudah pernah dicatat.');
});

it('cancels an expense by adding an opposite entry', function () {
    $account = CashAccount::factory()->create();
    CashTransaction::factory()->for($account)->create(['amount' => 1_000_000]);
    $expense = app(CashLedger::class)->recordExpense($account, TransactionCategory::factory()->expense()->create(), 300_000, CarbonImmutable::today(), 'Salah catat');
    $treasurer = User::factory()->treasurer()->create();

    app(CashLedger::class)->cancel($expense, $treasurer, 'Dobel input');

    $expense->refresh();

    expect($account->balance())->toBe(1_000_000)
        ->and($expense->isCancelled())->toBeTrue()
        ->and($expense->cancelled_by)->toBe($treasurer->id)
        ->and($expense->reversal->direction)->toBe(CashDirection::In)
        ->and(CashTransaction::query()->count())->toBe(3);
});

it('cancels both sides of a transfer', function () {
    $level = Level::factory()->sd()->create();
    $cashBox = CashAccount::factory()->for($level)->create();
    $bank = CashAccount::factory()->bank()->for($level)->create();
    CashTransaction::factory()->for($cashBox)->create(['amount' => 1_000_000]);
    ['out' => $out] = app(CashLedger::class)->transfer($cashBox, $bank, 400_000, CarbonImmutable::today(), 'Setor');

    app(CashLedger::class)->cancel($out, User::factory()->treasurer()->create(), 'Batal setor');

    expect($cashBox->balance())->toBe(1_000_000)
        ->and($bank->balance())->toBe(0)
        ->and(CashTransaction::query()->whereNotNull('cancelled_at')->count())->toBe(2);
});

it('refuses to cancel twice or to cancel a student payment directly', function () {
    $account = CashAccount::factory()->create();
    $entry = CashTransaction::factory()->for($account)->create(['amount' => 100_000]);
    $studentPayment = CashTransaction::factory()->for($account)->create(['type' => CashTransactionType::StudentPayment]);
    $treasurer = User::factory()->treasurer()->create();
    app(CashLedger::class)->cancel($entry, $treasurer, 'Salah');

    expect(fn () => app(CashLedger::class)->cancel($entry->refresh(), $treasurer, 'Lagi'))
        ->toThrow(ValidationException::class, 'Transaksi ini sudah dibatalkan.')
        ->and(fn () => app(CashLedger::class)->cancel($studentPayment, $treasurer, 'Salah'))
        ->toThrow(ValidationException::class, 'Batalkan pembayaran siswa melalui menu Pembayaran.');
});

it('refuses to cancel income that has already been spent', function () {
    $account = CashAccount::factory()->create();
    $income = app(CashLedger::class)->recordIncome($account, TransactionCategory::factory()->income()->create(), 500_000, CarbonImmutable::today(), 'Donasi');
    app(CashLedger::class)->recordExpense($account, TransactionCategory::factory()->expense()->create(), 400_000, CarbonImmutable::today(), 'Belanja');

    app(CashLedger::class)->cancel($income, User::factory()->treasurer()->create(), 'Salah');
})->throws(ValidationException::class, 'tidak cukup');

it('creates the cash box of a level once', function () {
    $level = Level::factory()->sd()->create();

    $first = CashAccount::cashBoxFor($level);
    $second = CashAccount::cashBoxFor($level);

    expect($first->is($second))->toBeTrue()
        ->and($first->name)->toBe('Kas Tunai SD');
});
