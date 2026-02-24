<?php

declare(strict_types=1);

namespace App\Http\Controllers\Phase4;

use App\Actions\Rbac\AssignRolesToMember;
use App\Actions\Rbac\RevokeRolesFromMember;
use App\Http\Controllers\Controller;
use App\Models\ModelHasRole;
use App\Models\TenantAclVersion;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\Phase4\Audit\AuditLogger;
use App\Support\Phase4\Authorization\TenantAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class TenantRbacController extends Controller
{
    public function __construct(
        private readonly TenantAuthorizationService $authorization,
        private readonly AssignRolesToMember $assignRoles,
        private readonly RevokeRolesFromMember $revokeRoles,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = tenant();
        abort_if($tenant === null, 403, 'Forbidden.');

        $actor = $request->user();
        abort_unless($actor instanceof User, 401, 'Unauthorized.');

        $this->authorize('manageRbac', $tenant);

        $tenantId = (string) $tenant->getTenantKey();
        $actorId = (int) $actor->getAuthIdentifier();
        $teamKey = (string) config('permission.column_names.team_foreign_key', 'tenant_id');

        $memberships = TenantUser::query()
            ->where('tenant_id', $tenantId)
            ->with('user:id,name,email')
            ->orderBy('id')
            ->limit(100)
            ->get();

        $members = $memberships->map(function (TenantUser $membership) use ($tenantId): array {
            return [
                'user_id' => (int) $membership->user_id,
                'name' => (string) ($membership->user?->name ?? ''),
                'email' => (string) ($membership->user?->email ?? ''),
                'membership_status' => (string) $membership->membership_status,
                'roles' => $this->authorization->roleNamesForUser((int) $membership->user_id, $tenantId),
            ];
        })->values()->all();

        $roles = Role::query()
            ->where($teamKey, $tenantId)
            ->orderBy('name')
            ->pluck('name')
            ->map(static fn (mixed $value): string => (string) $value)
            ->values()
            ->all();

        $aclVersion = (int) (TenantAclVersion::query()->whereKey($tenantId)->value('acl_version') ?? 0);

        return response()->json([
            'acl_version' => $aclVersion,
            'roles' => $roles,
            'members' => $members,
            'assignable_permissions' => $this->authorization->assignablePermissionsForUser($actorId, $tenantId),
        ]);
    }

    public function update(Request $request, User $member): JsonResponse
    {
        $tenant = tenant();
        abort_if($tenant === null, 403, 'Forbidden.');

        $actor = $request->user();
        abort_unless($actor instanceof User, 401, 'Unauthorized.');

        $this->authorize('manageRbac', $tenant);

        $validated = $request->validate([
            'roles' => ['required', 'array', 'max:25'],
            'roles.*' => ['string', 'max:100'],
            'expected_acl_version' => ['required', 'integer', 'min:0'],
        ]);

        $requestedRoles = collect($validated['roles'])
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter(static fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();

        $tenantId = (string) $tenant->getTenantKey();
        $actorId = (int) $actor->getAuthIdentifier();
        $memberId = (int) $member->getAuthIdentifier();
        $expectedAclVersion = (int) $validated['expected_acl_version'];

        $membershipExists = TenantUser::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $memberId)
            ->exists();

        if (! $membershipExists) {
            return response()->json([
                'code' => 'RBAC_MEMBER_NOT_IN_TENANT',
                'message' => 'Target member is not part of this tenant.',
            ], 404);
        }

        $teamKey = (string) config('permission.column_names.team_foreign_key', 'tenant_id');

        $knownRoles = Role::query()
            ->where($teamKey, $tenantId)
            ->whereIn('name', $requestedRoles)
            ->pluck('name')
            ->map(static fn (mixed $value): string => (string) $value)
            ->values()
            ->all();

        if (count($knownRoles) !== count($requestedRoles)) {
            return response()->json([
                'code' => 'RBAC_ROLE_NOT_FOUND',
                'message' => 'One or more roles are not available in this tenant.',
            ], 422);
        }

        $requestedPermissions = $this->authorization->permissionNamesForRoles($tenantId, $requestedRoles);
        $assignablePermissions = $this->authorization->assignablePermissionsForUser($actorId, $tenantId);
        $forbiddenPermissions = array_values(array_diff($requestedPermissions, $assignablePermissions));

        if ($forbiddenPermissions !== []) {
            return response()->json([
                'code' => 'RBAC_ESCALATION_BLOCKED',
                'message' => 'Requested role assignment exceeds actor privileges.',
                'forbidden_permissions' => $forbiddenPermissions,
            ], 403);
        }

        $result = DB::transaction(function () use (
            $tenantId,
            $member,
            $memberId,
            $requestedRoles,
            $expectedAclVersion,
            $actorId,
            $actor,
            $teamKey,
            $request
        ): array {
            /** @var TenantAclVersion|null $aclVersion */
            $aclVersion = TenantAclVersion::query()->whereKey($tenantId)->lockForUpdate()->first();

            if (! $aclVersion instanceof TenantAclVersion) {
                TenantAclVersion::query()->create([
                    'tenant_id' => $tenantId,
                    'acl_version' => 0,
                    'updated_by' => $actorId,
                ]);

                /** @var TenantAclVersion|null $aclVersion */
                $aclVersion = TenantAclVersion::query()->whereKey($tenantId)->lockForUpdate()->first();
            }

            if (! $aclVersion instanceof TenantAclVersion) {
                return [
                    'error' => 'ACL_VERSION_NOT_AVAILABLE',
                    'status' => 409,
                ];
            }

            if ((int) $aclVersion->acl_version !== $expectedAclVersion) {
                return [
                    'error' => 'ACL_VERSION_CONFLICT',
                    'status' => 409,
                    'current_acl_version' => (int) $aclVersion->acl_version,
                ];
            }

            if ($this->violatesLastOwnerGuard($tenantId, $memberId, $requestedRoles, $teamKey)) {
                return [
                    'error' => 'LAST_OWNER_GUARD_VIOLATION',
                    'status' => 409,
                ];
            }

            $currentRoles = $this->authorization->roleNamesForUser($memberId, $tenantId);
            $toAssign = array_values(array_diff($requestedRoles, $currentRoles));
            $toRevoke = array_values(array_diff($currentRoles, $requestedRoles));

            $guardName = (string) config('auth.defaults.guard', 'web');

            if ($toAssign !== []) {
                $this->assignRoles->execute($member, $toAssign, $tenantId, $guardName);
            }

            if ($toRevoke !== []) {
                $this->revokeRoles->execute($member, $toRevoke, $tenantId, $guardName);
            }

            $aclVersion->forceFill([
                'acl_version' => (int) $aclVersion->acl_version + 1,
                'updated_by' => $actorId,
            ])->save();

            $updatedRoles = $this->authorization->roleNamesForUser($memberId, $tenantId);

            $this->auditLogger->log(
                event: 'rbac.member.roles_updated',
                tenantId: $tenantId,
                actorId: $actorId,
                properties: [
                    'member_id' => $memberId,
                    'assigned_roles' => $toAssign,
                    'revoked_roles' => $toRevoke,
                    'final_roles' => $updatedRoles,
                    'acl_version' => (int) $aclVersion->acl_version,
                    'request_path' => (string) $request->path(),
                    'actor_email' => (string) $actor->email,
                ],
            );

            return [
                'status' => 200,
                'acl_version' => (int) $aclVersion->acl_version,
                'roles' => $updatedRoles,
            ];
        });

        if (isset($result['error'])) {
            return response()->json([
                'code' => $result['error'],
                'current_acl_version' => $result['current_acl_version'] ?? null,
            ], (int) $result['status']);
        }

        return response()->json([
            'status' => 'ok',
            'acl_version' => $result['acl_version'],
            'roles' => $result['roles'],
        ]);
    }

    /**
     * @param  list<string>  $requestedRoles
     */
    private function violatesLastOwnerGuard(string $tenantId, int $memberId, array $requestedRoles, string $teamKey): bool
    {
        $ownerRoleName = (string) config('phase4.rbac.owner_role', 'owner');

        /** @var Role|null $ownerRole */
        $ownerRole = Role::query()
            ->where($teamKey, $tenantId)
            ->where('name', $ownerRoleName)
            ->first();

        if (! $ownerRole instanceof Role) {
            return false;
        }

        $ownerAssignments = ModelHasRole::query()
            ->where('tenant_id', $tenantId)
            ->where('role_id', (int) $ownerRole->getKey())
            ->where('model_type', User::class)
            ->lockForUpdate()
            ->get(['model_id']);

        $ownerCount = $ownerAssignments->count();
        $memberHadOwnerRole = $ownerAssignments->contains(static fn (ModelHasRole $assignment): bool => (int) $assignment->model_id === $memberId);
        $memberWillHaveOwnerRole = in_array($ownerRoleName, $requestedRoles, true);

        return $memberHadOwnerRole && ! $memberWillHaveOwnerRole && $ownerCount <= 1;
    }
}
