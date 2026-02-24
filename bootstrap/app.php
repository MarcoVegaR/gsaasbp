<?php

use App\Http\Middleware\ApplyPlatformHsts;
use App\Http\Middleware\EnsureFreshProfileProjection;
use App\Http\Middleware\EnsureRbacStepUp;
use App\Http\Middleware\EnsureTenantEntitlement;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetTenantTeamContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        using: function (): void {
            $centralDomains = config('tenancy.central_domains', []);

            if ($centralDomains === []) {
                $centralDomains = [parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost'];
            }

            foreach ($centralDomains as $index => $centralDomain) {
                $group = Route::middleware('web')
                    ->domain($centralDomain);

                if ($index === 0) {
                    $group->group(base_path('routes/central.php'));

                    continue;
                }

                $domainAlias = Str::of((string) $centralDomain)
                    ->lower()
                    ->replaceMatches('/[^a-z0-9]+/', '_')
                    ->trim('_')
                    ->value();

                $group->as('central_alias.'.($domainAlias !== '' ? "{$domainAlias}." : "{$index}."))
                    ->group(base_path('routes/central.php'));
            }
        },
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: [
            'appearance',
            'sidebar_state',
            (string) env('APP_LOCALE_COOKIE', 'locale'),
        ]);
        $middleware->validateCsrfTokens(except: [
            'sso/consume',
            'sso/redeem',
            'tenant/events/ingest',
            'tenant/billing/webhooks/*',
        ]);
        $middleware->alias([
            'phase4.profile.fresh' => EnsureFreshProfileProjection::class,
            'phase4.rbac.step-up' => EnsureRbacStepUp::class,
            'phase4.entitlement' => EnsureTenantEntitlement::class,
        ]);

        $middleware->trustHosts(at: static function (): array {
            $trustedCentralDomains = array_map(
                static fn (string $domain): string => '^'.preg_quote($domain, '/').'$',
                config('tenancy.central_domains', []),
            );

            $trustedTenantPatterns = [
                '^(.+\.)?localhost$',
                '^(.+\.)?test$',
            ];

            return array_values(array_unique([
                ...$trustedCentralDomains,
                ...$trustedTenantPatterns,
                '^127\.0\.0\.1$',
            ]));
        }, subdomains: false);

        $middleware->prependToPriorityList(SubstituteBindings::class, PreventAccessFromCentralDomains::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, InitializeTenancyByDomain::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, SetTenantTeamContext::class);

        $middleware->web(prepend: [
            SetTenantTeamContext::class,
        ], append: [
            SetLocale::class,
            ApplyPlatformHsts::class,
            HandleInertiaRequests::class,
            HandleAppearance::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
