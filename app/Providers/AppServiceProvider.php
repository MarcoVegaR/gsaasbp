<?php

namespace App\Providers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
            : null
        );
    }

    /**
     * Configure cross-cutting authorization behavior.
     */
    protected function configureAuthorization(): void
    {
        $superadminEmails = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SUPERADMIN_EMAILS', '')),
        )));

        Gate::before(static function (?User $user, string $ability) use ($superadminEmails): ?bool {
            if ($user === null) {
                return null;
            }

            if (in_array($ability, config('superadmin_denylist.abilities', []), true)) {
                return null;
            }

            return in_array($user->email, $superadminEmails, true) ? true : null;
        });
    }
}
