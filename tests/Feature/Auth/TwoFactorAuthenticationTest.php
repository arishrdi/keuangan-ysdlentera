<?php

use App\Models\User;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

it('lets a staff member enable two-factor authentication with a valid code', function () {
    $user = User::factory()->treasurer()->create();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    $component = Livewire::test('pages::settings.two-factor')
        ->call('enable')
        ->assertSee('Kunci pengaturan');

    $secret = decrypt($user->refresh()->two_factor_secret);

    $component->set('code', '000000')
        ->call('confirm')
        ->assertHasErrors('code')
        ->set('code', app(Google2FA::class)->getCurrentOtp($secret))
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertSet('showRecoveryCodes', true);

    expect($user->refresh()->hasEnabledTwoFactorAuthentication())->toBeTrue()
        ->and($user->recoveryCodes())->toHaveCount(8);
});

it('disables two-factor authentication', function () {
    $user = User::factory()->treasurer()->create();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    $component = Livewire::test('pages::settings.two-factor')->call('enable');
    $component->set('code', app(Google2FA::class)->getCurrentOtp(decrypt($user->refresh()->two_factor_secret)))->call('confirm');

    $component->call('disable');

    expect($user->refresh()->two_factor_secret)->toBeNull()
        ->and($user->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

it('does not offer two-factor authentication to guardians', function () {
    $guardian = User::factory()->guardian()->create();

    $this->actingAs($guardian)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertDontSee('Verifikasi dua langkah');

    Livewire::test('pages::settings.two-factor')->assertForbidden();
});

it('shows the two-factor section to staff', function () {
    $this->actingAs(User::factory()->principal()->create())
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSee('Verifikasi dua langkah');
});

it('asks for the authenticator code after the password when enabled', function () {
    $provider = app(TwoFactorAuthenticationProvider::class);
    $secret = $provider->generateSecretKey();
    $user = User::factory()->admin()->create([
        'two_factor_secret' => encrypt($secret),
        'two_factor_recovery_codes' => encrypt(json_encode(['kode-pemulihan-1'])),
        'two_factor_confirmed_at' => now(),
    ]);

    $this->post(route('login.store'), ['login' => $user->username, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));

    $this->assertGuest();

    $this->get(route('two-factor.login'))->assertOk()->assertSee('Kode autentikasi');

    $this->post(route('two-factor.login.store'), ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

it('accepts a recovery code once', function () {
    $user = User::factory()->admin()->create([
        'two_factor_secret' => encrypt(app(TwoFactorAuthenticationProvider::class)->generateSecretKey()),
        'two_factor_recovery_codes' => encrypt(json_encode(['kode-pemulihan-1', 'kode-pemulihan-2'])),
        'two_factor_confirmed_at' => now(),
    ]);

    $this->post(route('login.store'), ['login' => $user->username, 'password' => 'password']);
    $this->post(route('two-factor.login.store'), ['recovery_code' => 'kode-pemulihan-1']);

    $this->assertAuthenticatedAs($user);
    expect($user->refresh()->recoveryCodes())->not->toContain('kode-pemulihan-1');
});
