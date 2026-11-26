<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\Level;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureFormatting();
    }

    /**
     * Define the abilities shared across the application.
     */
    protected function configureAuthorization(): void
    {
        Gate::define('manage-master-data', fn (User $user): bool => $user->hasRole(Role::Admin));

        Gate::define('view-school-data', fn (User $user): bool => $user->hasRole(Role::Admin, Role::Foundation));

        Gate::define('view-activity-log', fn (User $user): bool => $user->hasRole(Role::Admin, Role::Foundation));

        Gate::define('manage-payment-settings', fn (User $user): bool => $user->hasRole(Role::Admin));

        Gate::define('manage-billing', fn (User $user): bool => $user->hasRole(Role::Treasurer));

        Gate::define('manage-cash', fn (User $user): bool => $user->hasRole(Role::Treasurer));

        Gate::define('manage-budgets', fn (User $user): bool => $user->hasRole(Role::Treasurer));

        Gate::define('manage-savings', fn (User $user): bool => $user->hasRole(Role::Foundation));

        Gate::define('view-savings', fn (User $user): bool => $user->hasRole(Role::Principal, Role::Foundation));

        Gate::define('view-expenses', fn (User $user): bool => $user->hasRole(Role::Foundation));

        Gate::define('record-cash-payments', fn (User $user): bool => $user->hasRole(Role::Teller));

        Gate::define('send-payment-reminders', fn (User $user): bool => $user->hasRole(Role::Admin));

        Gate::define('view-finance', fn (User $user): bool => $user->hasRole(Role::Treasurer, Role::Principal, Role::Foundation));

        Gate::define('access-level', fn (User $user, Level|int|null $level): bool => $user->canAccessLevel($level));

        Gate::define('view-student-billing', fn (User $user, Student $student): bool => ($user->hasRole(Role::Treasurer, Role::Principal, Role::Foundation, Role::Teller) && $user->canAccessLevel($student->level_id))
            || ($user->hasRole(Role::Guardian) && $student->isGuardedBy($user)));
    }

    /**
     * Register formatting helpers for money.
     */
    protected function configureFormatting(): void
    {
        Number::macro('rupiah', fn (int|float|null $amount): string => ($amount < 0 ? '-' : '').'Rp'.number_format(abs((float) $amount), 0, ',', '.'));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
