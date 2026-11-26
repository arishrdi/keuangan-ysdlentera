<?php

use App\Actions\Payments\SubmitPayment;
use App\Enums\BillStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Bill;
use App\Models\Level;
use App\Models\Payment;
use App\Models\PaymentCategory;
use App\Models\PaymentChannel;
use App\Models\PaymentDraft;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
});

it('saves a draft without proof and leaves the bill payable', function () {
    $level = Level::factory()->sd()->create();
    $child = Student::factory()->for($level)->create();
    $channel = PaymentChannel::factory()->for($level)->create();
    $bill = Bill::factory()->forStudent($child)->for(PaymentCategory::factory()->spp())->create(['amount' => 250_000]);
    $guardian = User::factory()->guardian()->withStudents($child)->create();
    $this->actingAs($guardian);

    Livewire::test('pages::guardian.bills')
        ->call('toggleBill', $bill->id)
        ->set('channelId', $channel->id)
        ->call('saveDraft')
        ->assertHasNoErrors()
        ->assertSet('allocations', [])
        ->assertSee('Draft pembayaran')
        ->assertSee('Rp250.000');

    $draft = PaymentDraft::query()->sole();

    expect($draft->user_id)->toBe($guardian->id)
        ->and($draft->allocations)->toBe([$bill->id => 250_000])
        ->and($draft->payment_channel_id)->toBe($channel->id)
        ->and(Payment::query()->count())->toBe(0)
        ->and($bill->refresh()->payableAmount())->toBe(250_000);
});

it('continues a draft, sends the proof and removes the draft', function () {
    $level = Level::factory()->sd()->create();
    $child = Student::factory()->for($level)->create();
    $channel = PaymentChannel::factory()->for($level)->create();
    $bill = Bill::factory()->forStudent($child)->for(PaymentCategory::factory()->spp())->create(['amount' => 250_000]);
    $guardian = User::factory()->guardian()->withStudents($child)->create();
    $draft = PaymentDraft::factory()->for($guardian)->for($child)->create(['allocations' => [$bill->id => 250_000], 'payment_channel_id' => $channel->id]);
    $this->actingAs($guardian);

    Livewire::test('pages::guardian.bills')
        ->call('resumeDraft', $draft->id)
        ->assertSet('allocations', [$bill->id => 250_000])
        ->assertSet('channelId', $channel->id)
        ->set('proof', UploadedFile::fake()->image('bukti.jpg'))
        ->call('submit')
        ->assertHasNoErrors();

    expect(Payment::query()->sole()->status)->toBe(PaymentStatus::Pending)
        ->and(PaymentDraft::query()->exists())->toBeFalse();
});

it('drops a draft whose bills were paid elsewhere', function () {
    $child = Student::factory()->create();
    $paid = Bill::factory()->forStudent($child)->for(PaymentCategory::factory()->spp())->create(['amount' => 100_000]);
    $guardian = User::factory()->guardian()->withStudents($child)->create();
    PaymentDraft::factory()->for($guardian)->for($child)->create(['allocations' => [$paid->id => 100_000]]);

    app(SubmitPayment::class)->handle(User::factory()->teller()->create(), $child, [$paid->id => 100_000], PaymentMethod::Cash, CarbonImmutable::today());

    $this->actingAs($guardian);

    Livewire::test('pages::guardian.bills')->assertDontSee('Draft pembayaran');

    expect(PaymentDraft::query()->exists())->toBeFalse();
});

it('keeps the still-payable bills of a partly settled draft and caps installments', function () {
    $child = Student::factory()->create();
    $paid = Bill::factory()->forStudent($child)->for(PaymentCategory::factory()->spp())->create(['amount' => 100_000]);
    $installment = Bill::factory()->forStudent($child)->for(PaymentCategory::factory()->dpp())->create(['amount' => 1_000_000, 'paid_amount' => 700_000]);
    $draft = PaymentDraft::factory()->for($child)->create(['allocations' => [$paid->id => 100_000, $installment->id => 500_000]]);
    $paid->update(['paid_amount' => 100_000, 'status' => BillStatus::Paid]);

    expect($draft->syncWithBills())->toBeTrue()
        ->and($draft->refresh()->allocations)->toBe([$installment->id => 300_000]);
});

it('does not let a guardian delete someone else\'s draft', function () {
    $draft = PaymentDraft::factory()->create(['allocations' => [1 => 10_000]]);
    $this->actingAs(User::factory()->guardian()->withStudents(Student::factory()->create())->create());

    Livewire::test('pages::guardian.bills')->call('deleteDraft', $draft->id);

    expect($draft->fresh())->not->toBeNull();
});
