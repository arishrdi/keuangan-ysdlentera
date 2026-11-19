<?php

use App\Actions\Budgets\CompleteBudgetProposal;
use App\Actions\Budgets\DecideBudgetProposal;
use App\Actions\Budgets\SaveBudgetProposal;
use App\Actions\Budgets\StartBudgetDisbursement;
use App\Actions\Budgets\SubmitBudgetProposal;
use App\Enums\BudgetAction;
use App\Enums\BudgetStatus;
use App\Enums\Role;
use App\Enums\TransactionCategoryType;
use App\Models\BudgetProposal;
use App\Models\Level;
use App\Models\TransactionCategory;
use App\Models\User;
use App\Notifications\BudgetProposalUpdated;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(fn () => Notification::fake());

/**
 * @param  array<int, array{name: string, quantity: int, unit: string, unit_price: int}>  $items
 */
function draftBudget(?Level $level = null, array $items = [['name' => 'Laptop', 'quantity' => 2, 'unit' => 'unit', 'unit_price' => 10_000_000]]): BudgetProposal
{
    return app(SaveBudgetProposal::class)->handle(
        User::factory()->treasurer()->create(),
        null,
        $level ?? Level::factory()->sd()->create(),
        TransactionCategory::factory()->create(['type' => TransactionCategoryType::Budget]),
        'Pengadaan laptop',
        'Untuk laboratorium komputer',
        $items,
    );
}

it('saves a draft with items and computes the total', function () {
    Storage::fake('local');
    $proposal = app(SaveBudgetProposal::class)->handle(
        User::factory()->treasurer()->create(),
        null,
        Level::factory()->sd()->create(),
        TransactionCategory::factory()->create(['type' => TransactionCategoryType::Budget]),
        'Pengadaan laptop',
        'Untuk laboratorium',
        [
            ['name' => 'Laptop', 'quantity' => 2, 'unit' => 'unit', 'unit_price' => 10_000_000],
            ['name' => 'Printer', 'quantity' => 1, 'unit' => 'unit', 'unit_price' => 2_000_000],
            ['name' => 'ATK', 'quantity' => 5, 'unit' => 'paket', 'unit_price' => 200_000],
        ],
        [UploadedFile::fake()->create('rab.pdf', 100, 'application/pdf')],
    );

    expect($proposal->status)->toBe(BudgetStatus::Draft)
        ->and($proposal->number)->toBeNull()
        ->and($proposal->total_amount)->toBe(23_000_000)
        ->and($proposal->items)->toHaveCount(3)
        ->and($proposal->attachments()->sole()->original_name)->toBe('rab.pdf');
});

it('numbers the proposal on first submission and notifies the principal', function () {
    $this->travelTo('2026-11-19');
    $principal = User::factory()->principal()->create();
    $proposal = draftBudget();

    app(SubmitBudgetProposal::class)->handle($proposal, $proposal->creator);

    expect($proposal->refresh()->status)->toBe(BudgetStatus::AwaitingPrincipal)
        ->and($proposal->number)->toBe('AGR/SD/2026/0001')
        ->and($proposal->approvals()->sole()->action)->toBe(BudgetAction::Submitted);

    Notification::assertSentTo($principal, BudgetProposalUpdated::class);
});

it('goes through principal and foundation approval', function () {
    $foundation = User::factory()->foundation()->create();
    $treasurer = User::factory()->treasurer()->create();
    $proposal = draftBudget();
    app(SubmitBudgetProposal::class)->handle($proposal, $treasurer);

    app(DecideBudgetProposal::class)->handle($proposal->refresh(), User::factory()->principal()->create(), BudgetAction::Approved);
    expect($proposal->refresh()->status)->toBe(BudgetStatus::AwaitingFoundation);
    Notification::assertSentTo($foundation, BudgetProposalUpdated::class);

    app(DecideBudgetProposal::class)->handle($proposal, $foundation, BudgetAction::Approved, 'Silakan dilaksanakan');

    expect($proposal->refresh()->status)->toBe(BudgetStatus::Approved)
        ->and($proposal->approved_at)->not->toBeNull()
        ->and($proposal->approvals->pluck('stage')->all())->toBe([null, 1, 2])
        ->and($proposal->approvals->last()->notes)->toBe('Silakan dilaksanakan');

    Notification::assertSentTo($treasurer, BudgetProposalUpdated::class);
});

it('does not let the foundation decide before the principal', function () {
    $proposal = draftBudget();
    app(SubmitBudgetProposal::class)->handle($proposal, $proposal->creator);

    app(DecideBudgetProposal::class)->handle($proposal->refresh(), User::factory()->foundation()->create(), BudgetAction::Approved);
})->throws(ValidationException::class, 'Pengajuan ini tidak sedang menunggu keputusan Anda.');

it('refuses decisions from roles that do not approve', function (string $state) {
    $proposal = draftBudget();
    app(SubmitBudgetProposal::class)->handle($proposal, $proposal->creator);

    expect(fn () => app(DecideBudgetProposal::class)->handle($proposal->refresh(), User::factory()->{$state}()->create(), BudgetAction::Approved))
        ->toThrow(ValidationException::class);
})->with(['treasurer', 'admin', 'guardian']);

it('requires a note to reject or request a revision', function (BudgetAction $decision) {
    $proposal = draftBudget();
    app(SubmitBudgetProposal::class)->handle($proposal, $proposal->creator);

    expect(fn () => app(DecideBudgetProposal::class)->handle($proposal->refresh(), User::factory()->principal()->create(), $decision))
        ->toThrow(ValidationException::class, 'Catatan wajib diisi saat menolak atau meminta revisi.');
})->with([BudgetAction::Rejected, BudgetAction::RevisionRequested]);

it('makes a rejection final', function (string $deciderStatus, BudgetStatus $expected) {
    $proposal = draftBudget();
    app(SubmitBudgetProposal::class)->handle($proposal, $proposal->creator);

    if ($deciderStatus === 'foundation') {
        app(DecideBudgetProposal::class)->handle($proposal->refresh(), User::factory()->principal()->create(), BudgetAction::Approved);
    }

    app(DecideBudgetProposal::class)->handle($proposal->refresh(), User::factory()->{$deciderStatus === 'foundation' ? 'foundation' : 'principal'}()->create(), BudgetAction::Rejected, 'Tidak prioritas');

    expect($proposal->refresh()->status)->toBe($expected)
        ->and($proposal->status->isEditable())->toBeFalse()
        ->and(fn () => app(SubmitBudgetProposal::class)->handle($proposal, $proposal->creator))->toThrow(ValidationException::class);
})->with([
    'by the principal' => ['principal', BudgetStatus::RejectedByPrincipal],
    'by the foundation' => ['foundation', BudgetStatus::RejectedByFoundation],
]);

it('restarts at the principal after a revision requested by the foundation', function () {
    $treasurer = User::factory()->treasurer()->create();
    $proposal = draftBudget();
    app(SubmitBudgetProposal::class)->handle($proposal, $treasurer);
    app(DecideBudgetProposal::class)->handle($proposal->refresh(), User::factory()->principal()->create(), BudgetAction::Approved);
    app(DecideBudgetProposal::class)->handle($proposal->refresh(), User::factory()->foundation()->create(), BudgetAction::RevisionRequested, 'Kurangi jumlah laptop');

    expect($proposal->refresh()->status)->toBe(BudgetStatus::NeedsRevision);

    app(SaveBudgetProposal::class)->handle($treasurer, $proposal, $proposal->level, $proposal->category, $proposal->title, $proposal->reason, [
        ['name' => 'Laptop', 'quantity' => 1, 'unit' => 'unit', 'unit_price' => 10_000_000],
    ]);
    app(SubmitBudgetProposal::class)->handle($proposal->refresh(), $treasurer);

    expect($proposal->refresh()->status)->toBe(BudgetStatus::AwaitingPrincipal)
        ->and($proposal->number)->toBe($proposal->approvals->first()->proposal->number)
        ->and($proposal->total_amount)->toBe(10_000_000)
        ->and($proposal->approvals)->toHaveCount(4)
        ->and($proposal->approvals->pluck('total_amount')->all())->toBe([20_000_000, 20_000_000, 20_000_000, 10_000_000]);
});

it('refuses to edit a proposal that is waiting for approval', function () {
    $proposal = draftBudget();
    app(SubmitBudgetProposal::class)->handle($proposal, $proposal->creator);

    app(SaveBudgetProposal::class)->handle($proposal->creator, $proposal->refresh(), $proposal->level, $proposal->category, 'Diubah', 'Diubah', [
        ['name' => 'X', 'quantity' => 1, 'unit' => 'unit', 'unit_price' => 1],
    ]);
})->throws(ValidationException::class, 'Pengajuan yang sudah dikirim tidak bisa diubah.');

it('only accepts budget categories', function () {
    app(SaveBudgetProposal::class)->handle(
        User::factory()->treasurer()->create(), null, Level::factory()->create(),
        TransactionCategory::factory()->expense()->create(), 'Judul', 'Alasan',
        [['name' => 'X', 'quantity' => 1, 'unit' => 'unit', 'unit_price' => 1]],
    );
})->throws(ValidationException::class, 'Pilih kategori anggaran yang aktif.');

it('starts disbursement and completes an approved budget', function () {
    $treasurer = User::factory()->treasurer()->create();
    $proposal = BudgetProposal::factory()->status(BudgetStatus::Approved)->withTotal(5_000_000)->create();

    app(StartBudgetDisbursement::class)->handle($proposal, $treasurer);
    expect($proposal->refresh()->status)->toBe(BudgetStatus::Disbursing);

    app(CompleteBudgetProposal::class)->handle($proposal, $treasurer, 'Kegiatan selesai');

    expect($proposal->refresh()->status)->toBe(BudgetStatus::Completed)
        ->and($proposal->completed_at)->not->toBeNull()
        ->and($proposal->approvals->last()->notes)->toContain('sisa Rp5.000.000');
});

it('only completes approved budgets', function () {
    app(CompleteBudgetProposal::class)->handle(
        BudgetProposal::factory()->status(BudgetStatus::AwaitingPrincipal)->create(),
        User::factory()->treasurer()->create(),
    );
})->throws(ValidationException::class);

it('records which role made each decision', function () {
    $proposal = draftBudget();
    app(SubmitBudgetProposal::class)->handle($proposal, $proposal->creator);

    app(DecideBudgetProposal::class)->handle($proposal->refresh(), User::factory()->principal()->create(), BudgetAction::Approved);

    expect($proposal->approvals()->get()->pluck('role')->all())->toBe([Role::Treasurer, Role::Principal]);
});
