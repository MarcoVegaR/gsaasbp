<?php

declare(strict_types=1);

namespace App\Support\Phase4\Authorization;

use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

final class TenantAuthorizationService
{
    /**
     * @return list<string>
     */
    public function roleNamesForUser(int $userId, string $tenantId): array
    {
        $tableNames = config('permission.table_names');
        $teamKey = (string) config('permission.column_names.team_foreign_key', 'tenant_id');
        $modelKey = (string) config('permission.column_names.model_morph_key', 'model_id');

        return Role::query()
            ->select($tableNames['roles'].'.name')
            ->join($tableNames['model_has_roles'].' as mhr', 'mhr.role_id', '=', $tableNames['roles'].'.id')
            ->where('mhr.model_type', User::class)
            ->where('mhr.'.$modelKey, $userId)
            ->where('mhr.'.$teamKey, $tenantId)
            ->pluck('name')
            ->map(static fn (mixed $value): string => (string) $value)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $roleNames
     * @return list<string>
     */
    public function permissionNamesForRoles(string $tenantId, array $roleNames): array
    {
        if ($roleNames === []) {
            return [];
        }

        $tableNames = config('permission.table_names');
        $teamKey = (string) config('permission.column_names.team_foreign_key', 'tenant_id');

        return Permission::query()
            ->select($tableNames['permissions'].'.name')
            ->join($tableNames['role_has_permissions'].' as rhp', 'rhp.permission_id', '=', $tableNames['permissions'].'.id')
            ->join($tableNames['roles'], $tableNames['roles'].'.id', '=', 'rhp.role_id')
            ->where($tableNames['roles'].'.'.$teamKey, $tenantId)
            ->whereIn($tableNames['roles'].'.name', $roleNames)
            ->pluck('name')
            ->map(static fn (mixed $value): string => (string) $value)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function assignablePermissionsForUser(int $userId, string $tenantId): array
    {
        $permissions = $this->permissionNamesForRoles($tenantId, $this->roleNamesForUser($userId, $tenantId));
        $nonDelegable = config('phase4.rbac.non_delegable_permissions', []);

        return array_values(array_diff($permissions, is_array($nonDelegable) ? $nonDelegable : []));
    }

    public function canManageInvites(int $userId, string $tenantId): bool
    {
        $roles = $this->roleNamesForUser($userId, $tenantId);

        return in_array('owner', $roles, true) || in_array('admin', $roles, true);
    }

    public function canManageRbac(int $userId, string $tenantId): bool
    {
        $roles = $this->roleNamesForUser($userId, $tenantId);

        return in_array('owner', $roles, true);
    }

    public function canViewAudit(int $userId, string $tenantId): bool
    {
        $roles = $this->roleNamesForUser($userId, $tenantId);

        if (in_array('owner', $roles, true)) {
            return true;
        }

        return in_array('audit.view', $this->permissionNamesForRoles($tenantId, $roles), true);
    }

    public function canManageBilling(int $userId, string $tenantId): bool
    {
        $roles = $this->roleNamesForUser($userId, $tenantId);

        if (in_array('owner', $roles, true)) {
            return true;
        }

        return in_array('billing.manage', $this->permissionNamesForRoles($tenantId, $roles), true);
    }
}
