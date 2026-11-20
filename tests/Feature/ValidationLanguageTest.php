<?php

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;

it('translates every validation rule the framework ships', function () {
    $english = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
    $indonesian = require lang_path('id/validation.php');

    $missing = array_diff(
        array_keys(Arr::dot(Arr::except($english, ['custom', 'attributes']))),
        array_keys(Arr::dot(Arr::except($indonesian, ['custom', 'attributes']))),
    );

    expect($missing)->toBe([]);
});

it('shows validation messages in Indonesian with Indonesian field names', function (array $data, array $rules, string $field, string $message) {
    expect(Validator::make($data, $rules)->errors()->first($field))->toBe($message);
})->with([
    'required' => [[], ['rejectionReason' => 'required'], 'rejectionReason', 'Alasan penolakan wajib diisi.'],
    'minimum' => [['amount' => 500], ['amount' => 'integer|min:1000'], 'amount', 'Nominal minimal bernilai 1000.'],
    'nested wildcard' => [['items' => [['unit_price' => null]]], ['items.*.unit_price' => 'required'], 'items.0.unit_price', 'Harga satuan wajib diisi.'],
    'previously untranslated rule' => [['due_date' => 'besok'], ['due_date' => 'date_format:Y-m-d'], 'due_date', 'Tanggal jatuh tempo harus sesuai format Y-m-d.'],
]);

it('shows Indonesian messages inside a Livewire form', function () {
    $this->actingAs(User::factory()->admin()->create());

    $component = Livewire::test('pages::admin.uniform-items')
        ->set('name', '')
        ->set('price', null)
        ->call('save')
        ->assertHasErrors(['name', 'price']);

    expect($component->errors()->first('name'))->toBe('Nama item wajib diisi.')
        ->and($component->errors()->first('price'))->toBe('Harga wajib diisi.');
});
