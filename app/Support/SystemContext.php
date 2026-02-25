<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Spatie\Permission\PermissionRegistrar;

final class SystemContext
{
    public static function execute(
        callable $callback,
        ?Model $actor = null,
        ?string $purpose = null,
        ?string $targetTenantId = null,
    ): mixed
    {
        $tenancy = tenancy();
        $wasInitialized = $tenancy->initialized;
        $previousTenant = tenant();

        $resolvedPurpose = trim((string) ($purpose ?? ''));
        $resolvedTargetTenantId = trim((string) ($targetTenantId ?? ''));

        if ($resolvedPurpose !== '') {
            self::assertAllowedPurpose($resolvedPurpose);
        }

        $permissionRegistrar = app(PermissionRegistrar::class);
        $previousTeamId = $permissionRegistrar->getPermissionsTeamId();

        Log::info('system_context.enter', [
            'actor_id' => $actor?->getKey(),
            'tenant_id' => $previousTenant?->getTenantKey(),
            'target_tenant_id' => $resolvedTargetTenantId !== '' ? $resolvedTargetTenantId : null,
            'purpose' => $resolvedPurpose !== '' ? $resolvedPurpose : null,
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
                'target_tenant_id' => $resolvedTargetTenantId !== '' ? $resolvedTargetTenantId : null,
                'purpose' => $resolvedPurpose !== '' ? $resolvedPurpose : null,
            ]);
        }
    }

    private static function assertAllowedPurpose(string $purpose): void
    {
        $allowedPurposes = array_values(array_filter(array_map(
            static fn (string $candidate): string => trim($candidate),
            (array) config('phase7.system_context.allowed_purposes', []),
        ), static fn (string $candidate): bool => $candidate !== ''));

        if (! in_array($purpose, $allowedPurposes, true)) {
            throw new InvalidArgumentException('Unknown SystemContext purpose.');
        }
    }
}
