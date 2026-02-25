<?php

namespace App\Providers;

use App\Broadcasting\Phase6Broadcaster;
use App\Events\Phase5\TenantStatusChanged;
use App\Http\Middleware\ValidatePhase6BroadcastOrigin;
use App\Listeners\Phase5\InvalidateTenantStatusCache;
use App\Listeners\Phase6\SyncRealtimeCircuitBreakerWithTenantStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\TenantPolicy;
use App\Support\Phase6\BroadcastChannelRegistry;
use App\Support\Phase5\PlatformContextStore;
use App\Support\Sso\S2sCaller;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Psr\Log\LoggerInterface;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

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
        $this->configurePhase6();
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
        Gate::define('platform.tenants.view', static fn (User $user): bool => false);
        Gate::define('platform.tenants.manage-status', static fn (User $user): bool => false);
        Gate::define('platform.tenants.hard-delete.approve', static fn (User $user): bool => false);
        Gate::define('platform.tenants.hard-delete.execute', static fn (User $user): bool => false);
        Gate::define('platform.tenants.hard-delete', static fn (User $user): bool => false);
        Gate::define('platform.telemetry.view', static fn (User $user): bool => false);
        Gate::define('platform.audit.view', static fn (User $user): bool => false);
        Gate::define('platform.audit.export', static fn (User $user): bool => false);
        Gate::define('platform.billing.view', static fn (User $user): bool => false);
        Gate::define('platform.billing.reconcile', static fn (User $user): bool => false);
        Gate::define('platform.impersonation.issue', static fn (User $user): bool => false);
        Gate::define('platform.impersonation.terminate', static fn (User $user): bool => false);

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

        RateLimiter::for('phase6-broadcast-auth', static function (Request $request): Limit {
            $hmacKey = (string) config('phase6.telemetry.fingerprint_hmac_key', 'phase6-fallback-key');
            $channelFingerprint = hash_hmac('sha256', trim((string) $request->input('channel_name', '')), $hmacKey);
            $sessionFingerprint = hash('sha256', (string) ($request->session()?->getId() ?? 'no-session'));
            $userFingerprint = (string) ($request->user()?->getAuthIdentifier() ?? 'guest');
            $ip = (string) ($request->ip() ?? 'unknown-ip');

            return Limit::perMinute(max(1, (int) config('phase6.auth.rate_limit_per_minute', 90)))
                ->by(implode('|', [
                    'phase6-broadcast-auth',
                    $userFingerprint,
                    Str::substr($sessionFingerprint, 0, 24),
                    Str::substr($channelFingerprint, 0, 24),
                    $ip,
                ]));
        });
    }

    protected function configurePhase5(): void
    {
        Event::listen(TenantStatusChanged::class, InvalidateTenantStatusCache::class);
    }

    protected function configurePhase6(): void
    {
        Broadcast::extend('phase6', function ($app): Phase6Broadcaster {
            return new Phase6Broadcaster($app->make(LoggerInterface::class));
        });

        Broadcast::routes([
            'middleware' => [
                'web',
                InitializeTenancyByDomain::class,
                PreventAccessFromCentralDomains::class,
                'auth',
                'phase5.tenant.active',
                ValidatePhase6BroadcastOrigin::class,
                'throttle:phase6-broadcast-auth',
            ],
        ]);

        app(BroadcastChannelRegistry::class)->register();

        Event::listen(TenantStatusChanged::class, SyncRealtimeCircuitBreakerWithTenantStatus::class);
    }
}
