<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

class SetTenantTeamContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $permissionRegistrar = app(PermissionRegistrar::class);
        $previousTeamId = $permissionRegistrar->getPermissionsTeamId();

        try {
            $tenant = tenant();

            $permissionRegistrar->setPermissionsTeamId($tenant?->getTenantKey());
            $permissionRegistrar->initializeCache();

            if ($request->user() !== null) {
                $request->user()->unsetRelation('roles');
                $request->user()->unsetRelation('permissions');
            }

            return $next($request);
        } finally {
            $permissionRegistrar->setPermissionsTeamId($previousTeamId);

            if ($request->user() !== null) {
                $request->user()->unsetRelation('roles');
                $request->user()->unsetRelation('permissions');
            }
        }
    }
}
