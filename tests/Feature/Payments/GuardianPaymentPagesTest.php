<?php

use App\Enums\PaymentStatus;
use App\Models\Bill;
use App\Models\Level;
use App\Models\Payment;
use App\Models\PaymentCategory;
use App\Models\PaymentChannel;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('opens the guardian pages only for guardians', function (string $route) {
    $this->actingAs(User::factory()->guardian()->create())->get(route($route))->assertOk();
    $this->actingAs(User::factory()->admin()->create())->get(route($route))->assertForbidden();
})->with(['guardian.bills', 'guardian.installments', 'guardian.payments']);

it('shows only the bills of the guardian own children', function () {
    $child = Student::factory()->create();
    Bill::factory()->forStudent($child)->create(['description' => 'SPP anak sendiri']);
    Bill::factory()->create(['description' => 'SPP anak lain']);
    $this->actingAs(User::factory()->guardian()->withStudents($child)->create());

    Livewire::test('pages::guardian.bills')
        ->assertSee('SPP anak sendiri')
        ->assertDontSee('SPP anak lain');
});

it('ignores a child id from the URL that is not linked to the guardian', function () {
    $child = Student::factory()->create(['name' => 'Anak Sendiri']);
    $stranger = Student::factory()->create();
    Bill::factory()->forStudent($stranger)->create(['description' => 'Tagihan orang lain']);
    $this->actingAs(User::factory()->guardian()->withStudents($child)->create());

    Livewire::withQueryParams(['anak' => $stranger->id])
        ->test('pages::guardian.bills')
        ->assertSet('studentId', $child->id)
        ->assertDontSee('Tagihan orang lain');
});

it('submits a transfer with proof for verification', function () {
    Storage::fake('local');
    Notification::fake();
    $level = Level::factory()->sd()->create();
    $child = Student::factory()->for($level)->create();
    $channel = PaymentChannel::factory()->for($level)->create();
    $bill = Bill::factory()->forStudent($child)->for(PaymentCategory::factory()->spp())->create(['amount' => 250_000]);
    $this->actingAs(User::factory()->guardian()->withStudents($child)->create());

    Livewire::test('pages::guardian.bills')
        ->call('toggleBill', $bill->id)
        ->set('channelId', $channel->id)
        ->set('proof', UploadedFile::fake()->image('bukti.jpg'))
        ->call('submit')
        ->assertHasNoErrors();

    $payment = Payment::query()->sole();

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->amount)->toBe(250_000)
        ->and($payment->payment_channel_id)->toBe($channel->id);
});

it('requires proof when a guardian pays', function () {
    $level = Level::factory()->sd()->create();
    $child = Student::factory()->for($level)->create();
    $channel = PaymentChannel::factory()->for($level)->create();
    $bill = Bill::factory()->forStudent($child)->create();
    $this->actingAs(User::factory()->guardian()->withStudents($child)->create());

    Livewire::test('pages::guardian.bills')
        ->call('toggleBill', $bill->id)
        ->set('channelId', $channel->id)
        ->call('submit')
        ->assertHasErrors(['proof' => 'required']);
});

it('lists the payment history of the guardian children with the rejection reason', function () {
    $child = Student::factory()->create();
    Payment::factory()->for($child)->create(['status' => PaymentStatus::Rejected, 'rejection_reason' => 'Nominal tidak sesuai']);
    Payment::factory()->create(['amount' => 777_000]);
    $this->actingAs(User::factory()->guardian()->withStudents($child)->create());

    Livewire::test('pages::guardian.payments')
        ->assertSee('Nominal tidak sesuai')
        ->assertDontSee('Rp777.000');
});

it('shows installment progress for installable bills only', function () {
    $child = Student::factory()->create();
    Bill::factory()->forStudent($child)->for(PaymentCategory::factory()->dpp())->create(['description' => 'DPP 2026/2027', 'amount' => 3_000_000, 'paid_amount' => 1_000_000]);
    Bill::factory()->forStudent($child)->for(PaymentCategory::factory()->spp())->create(['description' => 'SPP Juli 2026']);
    $this->actingAs(User::factory()->guardian()->withStudents($child)->create());

    Livewire::test('pages::guardian.installments')
        ->assertSee('DPP 2026/2027')
        ->assertSee('Rp2.000.000')
        ->assertDontSee('SPP Juli 2026');
});
