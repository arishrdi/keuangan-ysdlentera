<?php

use App\Enums\PaymentChannelType;
use App\Models\AcademicYear;
use App\Models\FeeRate;
use App\Models\Level;
use App\Models\PaymentCategory;
use App\Models\PaymentChannel;
use App\Models\UniformItem;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('opens payment settings pages only for admins', function (string $route) {
    $this->actingAs(User::factory()->admin()->create())->get(route($route))->assertOk();
    $this->actingAs(User::factory()->treasurer()->create())->withSession([Level::SESSION_KEY => Level::ALL])->get(route($route))->assertForbidden();
})->with(['admin.fee-rates', 'admin.uniform-items', 'admin.payment-channels']);

it('switches a category off for one level only', function () {
    $this->actingAs(User::factory()->admin()->create());
    $paud = Level::factory()->paud()->create();
    $sd = Level::factory()->sd()->create();
    $dpp = PaymentCategory::factory()->dpp()->activeFor([$paud, $sd])->create();

    Livewire::test('pages::admin.fee-rates')->call('toggleActive', $dpp->id, $paud->id);

    expect($dpp->isActiveFor($paud))->toBeFalse()
        ->and($dpp->isActiveFor($sd))->toBeTrue();
});

it('toggles whether a category may be paid in installments', function () {
    $this->actingAs(User::factory()->admin()->create());
    $spp = PaymentCategory::factory()->spp()->create();

    Livewire::test('pages::admin.fee-rates')->call('toggleInstallable', $spp->id);

    expect($spp->refresh()->is_installable)->toBeTrue();
});

it('saves a rate for the active academic year and rejects a duplicate', function () {
    $this->actingAs(User::factory()->admin()->create());
    $year = AcademicYear::factory()->active()->create();
    $level = Level::factory()->sd()->create();
    $spp = PaymentCategory::factory()->spp()->create();

    $save = fn () => Livewire::test('pages::admin.fee-rates')
        ->set('level_id', $level->id)
        ->set('payment_category_id', $spp->id)
        ->set('amount', 250_000)
        ->call('save');

    $save()->assertHasNoErrors();
    $save()->assertHasErrors(['grade' => 'Tarif untuk kategori, jenjang, dan tingkat ini sudah ada.']);

    expect(FeeRate::query()->sole())
        ->academic_year_id->toBe($year->id)
        ->grade->toBeNull()
        ->amount->toBe(250_000);
});

it('does not accept a rate for the uniform category', function () {
    $this->actingAs(User::factory()->admin()->create());
    AcademicYear::factory()->active()->create();

    Livewire::test('pages::admin.fee-rates')
        ->set('level_id', Level::factory()->sd()->create()->id)
        ->set('payment_category_id', PaymentCategory::factory()->uniform()->create()->id)
        ->set('amount', 100_000)
        ->call('save')
        ->assertHasErrors(['payment_category_id' => 'Tarif seragam diatur di katalog seragam.']);
});

it('adds a uniform item to the catalog', function () {
    $this->actingAs(User::factory()->admin()->create());
    $level = Level::factory()->sd()->create();

    Livewire::test('pages::admin.uniform-items')
        ->set('level_id', $level->id)
        ->set('name', 'Seragam batik')
        ->set('size', 'M')
        ->set('price', 120_000)
        ->call('save')
        ->assertHasNoErrors();

    expect(UniformItem::query()->sole()->label())->toBe('Seragam batik (M)');
});

it('stores a QRIS image privately', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.payment-channels')
        ->set('level_id', Level::factory()->sd()->create()->id)
        ->set('type', PaymentChannelType::Qris->value)
        ->set('name', 'QRIS SD')
        ->set('qrisImage', UploadedFile::fake()->image('qris.png'))
        ->call('save')
        ->assertHasNoErrors();

    Storage::disk('local')->assertExists(PaymentChannel::query()->sole()->qris_image_path);
});

it('requires bank details for a bank account', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.payment-channels')
        ->set('level_id', Level::factory()->sd()->create()->id)
        ->set('name', 'BSI SD')
        ->call('save')
        ->assertHasErrors(['bank_name' => 'required', 'account_number' => 'required', 'account_holder' => 'required']);
});
