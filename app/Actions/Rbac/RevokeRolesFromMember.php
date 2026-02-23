<?php

declare(strict_types=1);

namespace App\Actions\Rbac;

use App\Exceptions\TenantContextMismatchException;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RevokeRolesFromMember
{
    /**
     * @param  list<string>  $roleNames
     */
    public function execute(User $member, array $roleNames, string $tenantId, ?string $guardName = null): void
    {
        $this->assertTenantContextMatches($tenantId);

        $guardName ??= config('auth.defaults.guard', 'web');
        $permissionRegistrar = app(PermissionRegistrar::class);
        $previousTeamId = $permissionRegistrar->getPermissionsTeamId();

        try {
            $permissionRegistrar->clearPermissionsCollection();
            $permissionRegistrar->setPermissionsTeamId($tenantId);
            $permissionRegistrar->initializeCache();

            $member->unsetRelation('roles');
            $member->unsetRelation('permissions');

            $roleIds = collect($roleNames)
                ->filter(static fn (string $role): bool => $role !== '')
                ->unique()
                ->map(static fn (string $role): int => Role::findByName($role, $guardName)->getKey())
                ->all();

            if ($roleIds !== []) {
                $member->roles()
                    ->newPivotQuery()
                    ->whereIn($permissionRegistrar->pivotRole, $roleIds)
                    ->delete();
            }
        } finally {
            $permissionRegistrar->clearPermissionsCollection();
            $permissionRegistrar->setPermissionsTeamId($previousTeamId);
            $permissionRegistrar->initializeCache();

            $member->unsetRelation('roles');
            $member->unsetRelation('permissions');
        }
    }

    private function assertTenantContextMatches(string $tenantId): void
    {
        $currentTenantId = tenant()?->getTenantKey();

        if ($currentTenantId !== null && $currentTenantId !== $tenantId) {
            throw TenantContextMismatchException::forTenant($tenantId, $currentTenantId);
        }
    }
}
