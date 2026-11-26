<?php

use App\Models\Bill;
use App\Models\Level;
use App\Models\Student;
use App\Models\User;
use Livewire\Livewire;

it('opens the reminder page for admins only', function () {
    $this->actingAs(User::factory()->admin()->create())->get(route('admin.payment-reminders'))->assertOk();
    $this->actingAs(User::factory()->foundation()->create())->get(route('admin.payment-reminders'))->assertForbidden();
});

it('links each guardian to a prefilled WhatsApp reminder', function () {
    $this->travelTo('2026-11-26 09:00');
    $student = Student::factory()->for(Level::factory()->sd())->create(['name' => 'Siti Aminah']);
    Bill::factory()->forStudent($student)->create(['description' => 'SPP November', 'amount' => 150_000, 'due_date' => '2026-11-10']);
    User::factory()->guardian()->withStudents($student)->create(['name' => 'Ibu Rahma', 'phone' => '081234567890']);
    $this->actingAs(User::factory()->admin()->create());

    $component = Livewire::test('pages::admin.payment-reminders')
        ->assertSee('Siti Aminah')
        ->assertSee('SPP November');

    $html = $component->html();

    expect($html)->toContain('https://wa.me/6281234567890?text=')
        ->and(rawurldecode(html_entity_decode($html)))->toContain('*Siti Aminah*')->toContain('*Total: Rp150.000*');
});

it('flags guardians without a phone number', function () {
    $student = Student::factory()->create();
    Bill::factory()->forStudent($student)->create(['due_date' => now()->subDay()]);
    User::factory()->guardian()->withStudents($student)->create(['phone' => null]);
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.payment-reminders')
        ->assertSee('Tanpa no. HP')
        ->assertDontSee('https://wa.me/');
});

it('filters bills by how close they are to the due date', function () {
    $this->travelTo('2026-11-26 09:00');
    Bill::factory()->forStudent(Student::factory()->create(['name' => 'Sudah Lewat']))->create(['due_date' => '2026-11-10']);
    Bill::factory()->forStudent(Student::factory()->create(['name' => 'Minggu Depan']))->create(['due_date' => '2026-12-01']);
    Bill::factory()->forStudent(Student::factory()->create(['name' => 'Masih Lama']))->create(['due_date' => '2027-01-10']);
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.payment-reminders')
        ->assertSee('Sudah Lewat')->assertSee('Minggu Depan')->assertDontSee('Masih Lama')
        ->set('scope', 'lewat')
        ->assertSee('Sudah Lewat')->assertDontSee('Minggu Depan')
        ->set('scope', 'semua')
        ->assertSee('Masih Lama');
});
