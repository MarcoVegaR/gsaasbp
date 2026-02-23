<?php

declare(strict_types=1);

namespace App\Actions\Rbac;

use App\Exceptions\TenantContextMismatchException;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AssignRolesToMember
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

            $roles = collect($roleNames)
                ->filter(static fn (string $role): bool => $role !== '')
                ->unique()
                ->map(static fn (string $role): Role => Role::findByName($role, $guardName))
                ->all();

            if ($roles !== []) {
                $member->assignRole($roles);
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
