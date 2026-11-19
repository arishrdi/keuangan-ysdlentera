<?php

use App\Models\Payment;
use App\Models\User;
use App\Notifications\PaymentRejected;
use Livewire\Livewire;

it('shows unread notifications and marks one read when opened', function () {
    $guardian = User::factory()->guardian()->create();
    $guardian->notify(new PaymentRejected(Payment::factory()->create(['rejection_reason' => 'Bukti buram'])));
    $this->actingAs($guardian);

    $notification = $guardian->notifications()->sole();

    Livewire::test('notification-bell')
        ->assertSee('Pembayaran ditolak')
        ->assertSee('Bukti buram')
        ->call('open', $notification->id)
        ->assertRedirect(route('guardian.payments'));

    expect($notification->fresh()->read_at)->not->toBeNull();
});

it('cannot open notifications of another user', function () {
    $other = User::factory()->guardian()->create();
    $other->notify(new PaymentRejected(Payment::factory()->create()));
    $this->actingAs(User::factory()->guardian()->create());

    Livewire::test('notification-bell')
        ->call('open', $other->notifications()->sole()->id)
        ->assertNotFound();

    expect($other->notifications()->sole()->read_at)->toBeNull();
});
