<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Support\Phase8\ModuleCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class Phase8ServiceProvider extends ServiceProvider
{
    public function boot(ModuleCatalog $moduleCatalog): void
    {
        $this->registerTenantResourceMacro();
        $this->registerGeneratedModulePoliciesAndAbilities($moduleCatalog);
    }

    private function registerTenantResourceMacro(): void
    {
        if (Route::hasMacro('tenantResource')) {
            return;
        }

        Route::macro(
            'tenantResource',
            /**
             * @param  class-string<Model>  $modelClass
             * @param  list<string>  $middleware
             */
            function (
                string $uri,
                string $controller,
                string $modelClass,
                string $parameter,
                string $namePrefix,
                string $abilityPrefix,
                array $middleware = [],
            ): void {
                Route::bind($parameter, static function (string|int $value) use ($modelClass): Model {
                    $tenantId = tenant()?->getTenantKey();

                    if (! is_string($tenantId) || $tenantId === '') {
                        throw (new ModelNotFoundException)->setModel($modelClass, [(string) $value]);
                    }

                    /** @var Model|null $resource */
                    $resource = $modelClass::query()
                        ->whereKey((string) $value)
                        ->where('tenant_id', $tenantId)
                        ->first();

                    if ($resource === null) {
                        throw (new ModelNotFoundException)->setModel($modelClass, [(string) $value]);
                    }

                    return $resource;
                });

                Route::prefix($uri)
                    ->name($namePrefix.'.')
                    ->middleware($middleware)
                    ->group(static function () use ($controller, $parameter, $abilityPrefix): void {
                        Route::get('/', [$controller, 'index'])
                            ->name('index')
                            ->middleware('can:'.$abilityPrefix.'.view');

                        Route::get('/create', [$controller, 'create'])
                            ->name('create')
                            ->middleware('can:'.$abilityPrefix.'.create');

                        Route::post('/', [$controller, 'store'])
                            ->name('store')
                            ->middleware('can:'.$abilityPrefix.'.create');

                        Route::get('/{'.$parameter.'}', [$controller, 'show'])
                            ->name('show')
                            ->middleware('can:'.$abilityPrefix.'.view,'.$parameter)
                            ->missing(static fn () => abort(404));

                        Route::get('/{'.$parameter.'}/edit', [$controller, 'edit'])
                            ->name('edit')
                            ->middleware('can:'.$abilityPrefix.'.update,'.$parameter)
                            ->missing(static fn () => abort(404));

                        Route::match(['put', 'patch'], '/{'.$parameter.'}', [$controller, 'update'])
                            ->name('update')
                            ->middleware('can:'.$abilityPrefix.'.update,'.$parameter)
                            ->missing(static fn () => abort(404));

                        Route::delete('/{'.$parameter.'}', [$controller, 'destroy'])
                            ->name('destroy')
                            ->middleware('can:'.$abilityPrefix.'.delete,'.$parameter)
                            ->missing(static fn () => abort(404));
                    });
            },
        );
    }

    private function registerGeneratedModulePoliciesAndAbilities(ModuleCatalog $moduleCatalog): void
    {
        foreach ($moduleCatalog->modules() as $module) {
            $modelClass = $module['model_class'];
            $policyClass = $module['policy_class'];
            $abilityPrefix = $module['ability_prefix'];

            if (class_exists($modelClass) && class_exists($policyClass)) {
                Gate::policy($modelClass, $policyClass);
            }

            $this->registerAbility($abilityPrefix.'.view', $policyClass, $modelClass, 'view', 'viewAny');
            $this->registerAbility($abilityPrefix.'.create', $policyClass, $modelClass, 'create', 'create');
            $this->registerAbility($abilityPrefix.'.update', $policyClass, $modelClass, 'update', 'update');
            $this->registerAbility($abilityPrefix.'.delete', $policyClass, $modelClass, 'delete', 'delete');
        }

        Gate::before(static function (?User $user, string $ability): ?bool {
            if ($user === null || ! str_starts_with($ability, 'tenant.')) {
                return null;
            }

            if (in_array($ability, config('superadmin_denylist.abilities', []), true)) {
                return null;
            }

            $superadminEmails = array_values(array_filter(array_map(
                static fn (mixed $email): string => trim(strtolower((string) $email)),
                (array) config('phase5.superadmin.emails', []),
            ), static fn (string $email): bool => $email !== ''));

            return in_array(strtolower((string) $user->getAttribute('email')), $superadminEmails, true) ? true : null;
        });
    }

    /**
     * @param  class-string  $policyClass
     * @param  class-string<Model>  $modelClass
     */
    private function registerAbility(
        string $ability,
        string $policyClass,
        string $modelClass,
        string $resourceMethod,
        string $fallbackMethod,
    ): void {
        if (Gate::has($ability)) {
            return;
        }

        Gate::define($ability, static function (User $user, mixed ...$arguments) use ($policyClass, $modelClass, $resourceMethod, $fallbackMethod): bool {
            if (! class_exists($policyClass)) {
                return false;
            }

            $policy = app($policyClass);

            $resource = $arguments[0] ?? null;

            if ($resource instanceof $modelClass && method_exists($policy, $resourceMethod)) {
                return (bool) $policy->{$resourceMethod}($user, $resource);
            }

            if (method_exists($policy, $fallbackMethod)) {
                return (bool) $policy->{$fallbackMethod}($user);
            }

            return false;
        });
    }
}
