<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;

final class SystemContext
{
    public static function execute(callable $callback, ?Model $actor = null): mixed
    {
        $tenancy = tenancy();
        $wasInitialized = $tenancy->initialized;
        $previousTenant = tenant();

        $permissionRegistrar = app(PermissionRegistrar::class);
        $previousTeamId = $permissionRegistrar->getPermissionsTeamId();

        Log::info('system_context.enter', [
            'actor_id' => $actor?->getKey(),
            'tenant_id' => $previousTenant?->getTenantKey(),
        ]);

        try {
            if ($tenancy->initialized) {
                $tenancy->end();
            }

            $permissionRegistrar->clearPermissionsCollection();
            $permissionRegistrar->setPermissionsTeamId(null);
            $permissionRegistrar->initializeCache();

            return $callback();
        } finally {
            if ($wasInitialized && $previousTenant !== null) {
                $tenancy->initialize($previousTenant);
            } elseif ($tenancy->initialized) {
                $tenancy->end();
            }

            $permissionRegistrar->clearPermissionsCollection();
            $permissionRegistrar->setPermissionsTeamId($previousTeamId);
            $permissionRegistrar->initializeCache();

            Log::info('system_context.exit', [
                'actor_id' => $actor?->getKey(),
                'tenant_id' => tenant()?->getTenantKey(),
            ]);
        }
    }
}
