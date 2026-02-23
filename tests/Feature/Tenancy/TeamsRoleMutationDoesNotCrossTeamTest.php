<?php

use App\Actions\Rbac\AssignRolesToMember;
use App\Actions\Rbac\RevokeRolesFromMember;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tenancy.central_domains' => ['localhost']]);
});

test('revoking a role in team A does not detach role assignments from team B', function () {
    $tenantA = Tenant::create(['id' => (string) Str::uuid()]);
    $tenantA->domains()->create(['domain' => 'team-a.localhost']);

    $tenantB = Tenant::create(['id' => (string) Str::uuid()]);
    $tenantB->domains()->create(['domain' => 'team-b.localhost']);

    $permissionRegistrar = app(PermissionRegistrar::class);
    $initialTeamId = $permissionRegistrar->getPermissionsTeamId();

    try {
        $permissionRegistrar->setPermissionsTeamId($tenantA->id);
        Role::create(['name' => 'manager', 'guard_name' => 'web']);

        $permissionRegistrar->setPermissionsTeamId($tenantB->id);
        Role::create(['name' => 'manager', 'guard_name' => 'web']);

        $member = User::factory()->create();

        app(AssignRolesToMember::class)->execute($member, ['manager'], (string) $tenantA->id);
        app(AssignRolesToMember::class)->execute($member, ['manager'], (string) $tenantB->id);

        app(RevokeRolesFromMember::class)->execute($member, ['manager'], (string) $tenantA->id);

        $permissionRegistrar->setPermissionsTeamId($tenantA->id);
        $member->unsetRelation('roles');
        $member->unsetRelation('permissions');
        expect($member->hasRole('manager'))->toBeFalse();

        $permissionRegistrar->setPermissionsTeamId($tenantB->id);
        $member->unsetRelation('roles');
        $member->unsetRelation('permissions');
        expect($member->hasRole('manager'))->toBeTrue();
    } finally {
        $permissionRegistrar->setPermissionsTeamId($initialTeamId);
        $permissionRegistrar->clearPermissionsCollection();
        $permissionRegistrar->initializeCache();
    }
});
