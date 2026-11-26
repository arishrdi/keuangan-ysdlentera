<?php

use App\Actions\Payments\SubmitPayment;
use App\Enums\BudgetStatus;
use App\Enums\PaymentMethod;
use App\Models\Bill;
use App\Models\BudgetProposal;
use App\Models\Level;
use App\Models\PaymentChannel;
use App\Models\Student;
use App\Models\User;
use App\Notifications\PaymentSubmitted;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

it('takes a level-locked treasurer straight to the dashboard of their level', function () {
    $level = Level::factory()->sd()->create();

    $this->actingAs(User::factory()->treasurer()->forLevel($level)->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Jenjang SD')
        ->assertDontSee('data-test="level-switcher"', false);

    expect(Level::active()?->is($level))->toBeTrue();
});

it('forbids a level-locked staff member from switching levels', function () {
    $sd = Level::factory()->sd()->create();
    $smp = Level::factory()->smp()->create();

    $this->actingAs(User::factory()->principal()->forLevel($sd)->create())
        ->post(route('levels.switch'), ['level' => $smp->id])
        ->assertForbidden();
});

it('keeps a level-locked treasurer out of students of another level', function () {
    $sd = Level::factory()->sd()->create();
    $treasurer = User::factory()->treasurer()->forLevel($sd)->create();

    $this->actingAs($treasurer)->get(route('billing.student', Student::factory()->for($sd)->create()))->assertOk();
    $this->actingAs($treasurer)->get(route('billing.student', Student::factory()->for(Level::factory()->smp())->create()))->assertForbidden();
});

it('keeps a level-locked principal out of budgets of another level', function () {
    $sd = Level::factory()->sd()->create();
    $budget = BudgetProposal::factory()->for(Level::factory()->smp())->status(BudgetStatus::AwaitingPrincipal)->withTotal(1_000_000)->create();

    $this->actingAs(User::factory()->principal()->forLevel($sd)->create())
        ->get(route('budgets.show', $budget))
        ->assertForbidden();
});

it('notifies only treasurers who can access the level of a submitted payment', function () {
    Storage::fake('local');
    Notification::fake();
    $sd = Level::factory()->sd()->create();
    $student = Student::factory()->for($sd)->create();
    $sdTreasurer = User::factory()->treasurer()->forLevel($sd)->create();
    $allLevelsTreasurer = User::factory()->treasurer()->create();
    $smpTreasurer = User::factory()->treasurer()->forLevel(Level::factory()->smp()->create())->create();

    app(SubmitPayment::class)->handle(
        User::factory()->guardian()->withStudents($student)->create(), $student,
        [Bill::factory()->forStudent($student)->create(['amount' => 100_000])->id => 100_000],
        PaymentMethod::Transfer, CarbonImmutable::today(), PaymentChannel::factory()->for($sd)->create(), UploadedFile::fake()->image('bukti.jpg'),
    );

    Notification::assertSentTo([$sdTreasurer, $allLevelsTreasurer], PaymentSubmitted::class);
    Notification::assertNotSentTo($smpTreasurer, PaymentSubmitted::class);
});
