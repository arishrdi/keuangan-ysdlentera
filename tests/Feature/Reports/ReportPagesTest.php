<?php

use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Level;
use App\Models\User;
use Livewire\Livewire;
use Spatie\SimpleExcel\SimpleExcelReader;

it('lists the reports a role may open', function () {
    $this->actingAs(User::factory()->treasurer()->create())
        ->withSession([Level::SESSION_KEY => Level::ALL])
        ->get(route('reports.index'))
        ->assertOk()
        ->assertSee('Laporan Pemasukan')
        ->assertDontSee('Laporan Konsolidasi Yayasan');

    $this->actingAs(User::factory()->foundation()->create())
        ->get(route('reports.index'))
        ->assertOk()
        ->assertSee('Laporan Konsolidasi Yayasan');
});

it('forbids reports for admins and guardians', function (string $state) {
    $this->actingAs(User::factory()->{$state}()->create())->get(route('reports.index'))->assertForbidden();
})->with(['admin', 'guardian']);

it('opens every report page for the foundation', function (string $report) {
    $this->actingAs(User::factory()->foundation()->create())
        ->get(route('reports.show', $report))
        ->assertOk();
})->with(['pemasukan', 'pengeluaran', 'mutasi-kas', 'rekap-pembayaran', 'tunggakan', 'tabungan', 'anggaran', 'konsolidasi']);

it('forbids the consolidated report for the treasurer, on screen and as export', function () {
    $treasurer = User::factory()->treasurer()->create();

    $this->actingAs($treasurer)->withSession([Level::SESSION_KEY => Level::ALL])->get(route('reports.show', 'konsolidasi'))->assertForbidden();
    $this->actingAs($treasurer)->withSession([Level::SESSION_KEY => Level::ALL])->get(route('reports.export', ['konsolidasi', 'excel']))->assertForbidden();
});

it('returns 404 for an unknown report', function () {
    $this->actingAs(User::factory()->foundation()->create())->get(route('reports.show', 'tidak-ada'))->assertNotFound();
    $this->actingAs(User::factory()->foundation()->create())->get(route('reports.export', ['tidak-ada', 'pdf']))->assertNotFound();
});

it('downloads a report as an Excel file with the filtered rows', function () {
    $this->travelTo('2026-11-20');
    $level = Level::factory()->sd()->create();
    CashTransaction::factory()->for(CashAccount::factory()->for($level))->create(['amount' => 750_000, 'transacted_on' => '2026-11-05', 'description' => 'Donasi alumni']);

    $response = $this->actingAs(User::factory()->principal()->create())
        ->withSession([Level::SESSION_KEY => $level->id])
        ->get(route('reports.export', ['pemasukan', 'excel', 'dari' => '2026-11-01', 'sampai' => '2026-11-30']));

    $response->assertOk()->assertDownload('laporan-pemasukan-2026-11-20.xlsx');

    $rows = SimpleExcelReader::create($response->baseResponse->getFile()->getPathname(), 'xlsx')->noHeaderRow()->getRows()->all();

    expect($rows[0][0])->toBe('Laporan Pemasukan')
        ->and(collect($rows)->flatten()->contains('Donasi alumni'))->toBeTrue()
        ->and(collect($rows)->flatten()->contains(750_000))->toBeTrue();
});

it('opens a report as a PDF', function () {
    $this->actingAs(User::factory()->foundation()->create())
        ->get(route('reports.export', ['tunggakan', 'pdf']))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('updates the table when filters change and builds matching export links', function () {
    $level = Level::factory()->sd()->create();
    $account = CashAccount::factory()->for($level)->create();
    CashTransaction::factory()->for($account)->create(['amount' => 100_000, 'transacted_on' => '2026-10-05', 'description' => 'Bulan lalu']);
    CashTransaction::factory()->for($account)->create(['amount' => 200_000, 'transacted_on' => '2026-11-05', 'description' => 'Bulan ini']);
    $this->actingAs(User::factory()->treasurer()->create());
    session([Level::SESSION_KEY => $level->id]);

    $component = Livewire::test('pages::reports.show', ['report' => 'pemasukan'])
        ->set('from', '2026-11-01')
        ->set('until', '2026-11-30')
        ->assertSee('Bulan ini')
        ->assertDontSee('Bulan lalu');

    expect($component->instance()->exportUrl('excel'))->toContain('dari=2026-11-01')->toContain('sampai=2026-11-30');
});

it('shows the per-level comparison on the foundation dashboard', function () {
    Level::factory()->sd()->create();
    Level::factory()->smp()->create();

    $this->actingAs(User::factory()->foundation()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Perbandingan per jenjang')
        ->assertSeeInOrder(['SD', 'SMP', 'Gabungan']);
});

it('hides the expense and savings reports from the treasurer', function (string $report) {
    $this->actingAs(User::factory()->treasurer()->create())
        ->withSession([Level::SESSION_KEY => Level::ALL])
        ->get(route('reports.show', $report))
        ->assertForbidden();
})->with(['pengeluaran', 'tabungan']);

it('hides the expense report but not the savings report from the principal', function () {
    $principal = User::factory()->principal()->create();

    $this->actingAs($principal)->withSession([Level::SESSION_KEY => Level::ALL])->get(route('reports.show', 'pengeluaran'))->assertForbidden();
    $this->actingAs($principal)->withSession([Level::SESSION_KEY => Level::ALL])->get(route('reports.show', 'tabungan'))->assertOk();
});
