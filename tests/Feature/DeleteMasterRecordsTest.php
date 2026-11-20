<?php

use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\Payment;
use App\Models\PaymentChannel;
use App\Models\Student;
use App\Models\User;
use Livewire\Livewire;

it('deletes a student that has no financial history', function () {
    $this->actingAs(User::factory()->admin()->create());
    $student = Student::factory()->create();

    Livewire::test('pages::students.index')
        ->call('delete', $student->id)
        ->assertDispatched('toast', variant: 'success', text: 'Data siswa dihapus.');

    expect($student->fresh())->toBeNull();
});

it('refuses to delete a student that already has a bill', function () {
    $this->actingAs(User::factory()->admin()->create());
    $student = Student::factory()->create();
    Bill::factory()->for($student)->create();

    Livewire::test('pages::students.index')
        ->call('delete', $student->id)
        ->assertDispatched('toast', variant: 'error');

    expect($student->fresh())->not->toBeNull();
});

it('refuses to delete a payment channel that payments point at', function () {
    $this->actingAs(User::factory()->admin()->create());
    $channel = PaymentChannel::factory()->create();
    Payment::factory()->create(['payment_channel_id' => $channel->id]);

    Livewire::test('pages::admin.payment-channels')
        ->call('delete', $channel->id)
        ->assertDispatched('toast', variant: 'error');

    expect($channel->fresh())->not->toBeNull();
});

it('refuses to delete the active academic year', function () {
    $this->actingAs(User::factory()->admin()->create());
    $academicYear = AcademicYear::factory()->active()->create();

    Livewire::test('pages::admin.academic-years')
        ->call('delete', $academicYear->id)
        ->assertDispatched('toast', variant: 'error', text: 'Tahun ajaran yang sedang aktif tidak bisa dihapus.');

    expect($academicYear->fresh())->not->toBeNull();
});

it('refuses to let an admin delete their own account', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test('pages::admin.users')
        ->call('delete', $admin->id)
        ->assertDispatched('toast', variant: 'error', text: 'Anda tidak bisa menghapus akun sendiri.');

    expect($admin->fresh())->not->toBeNull();
});

it('forbids non-admin staff from deleting students', function () {
    $this->actingAs(User::factory()->treasurer()->create());
    $student = Student::factory()->create();

    Livewire::test('pages::students.index')
        ->call('delete', $student->id)
        ->assertForbidden();

    expect($student->fresh())->not->toBeNull();
});
