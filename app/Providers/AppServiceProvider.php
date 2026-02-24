<?php

namespace App\Providers;

use App\Events\Phase5\TenantStatusChanged;
use App\Listeners\Phase5\InvalidateTenantStatusCache;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\TenantPolicy;
use App\Support\Phase5\PlatformContextStore;
use App\Support\Sso\S2sCaller;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PlatformContextStore::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureRateLimiting();
        $this->configurePhase5();
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
        Gate::policy(Tenant::class, TenantPolicy::class);

        Gate::define('platform.admin.access', static fn (User $user): bool => false);
        Gate::define('platform.step-up.issue', static fn (User $user): bool => false);
        Gate::define('platform.tenants.manage-status', static fn (User $user): bool => false);
        Gate::define('platform.tenants.hard-delete', static fn (User $user): bool => false);
        Gate::define('platform.telemetry.view', static fn (User $user): bool => false);

        Gate::before(static function (?User $user, string $ability): ?bool {
            if ($user === null) {
                return null;
            }

            if (! str_starts_with($ability, 'platform.')) {
                return null;
            }

            if (in_array($ability, config('superadmin_denylist.abilities', []), true)) {
                return null;
            }

            $superadminEmails = array_values(array_filter(array_map(
                static fn (mixed $email): string => trim(strtolower((string) $email)),
                (array) config('phase5.superadmin.emails', []),
            ), static fn (string $email): bool => $email !== ''));

            return in_array(strtolower((string) $user->email), $superadminEmails, true) ? true : null;
        });
    }

    /**
     * Configure cross-cutting rate limiters.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('sso-consume', static function (Request $request): Limit {
            $tenantId = tenant()?->getTenantKey() ?? 'unknown-tenant';

            return Limit::perMinute(30)->by($tenantId.'|'.$request->ip());
        });

        RateLimiter::for('sso-claims', static function (Request $request): Limit {
            $caller = $request->attributes->get('sso_s2s_caller');

            if ($caller instanceof S2sCaller) {
                return Limit::perMinute(max(1, (int) config('sso.claims.rate_limit_per_minute', 60)))
                    ->by($caller->tenantId.'|'.$caller->caller);
            }

            return Limit::perMinute(max(1, (int) config('sso.claims.rate_limit_per_minute', 60)))
                ->by('unauthenticated|'.$request->ip());
        });

        RateLimiter::for('phase5-analytics', static function (Request $request): Limit {
            return Limit::perMinute(max(1, (int) config('phase5.telemetry.analytics.rate_limit_per_minute', 30)))
                ->by('phase5-analytics|'.$request->ip());
        });
    }

    protected function configurePhase5(): void
    {
        Event::listen(TenantStatusChanged::class, InvalidateTenantStatusCache::class);
    }
}
