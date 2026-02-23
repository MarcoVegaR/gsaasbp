<?php

use App\Actions\Rbac\AssignRolesToMember;
use App\Http\Middleware\SetTenantTeamContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tenancy.central_domains' => ['localhost']]);
});

test('permission registrar state remains isolated across tenant switches', function () {
    $tenantA = Tenant::create(['id' => (string) Str::uuid()]);
    $tenantA->domains()->create(['domain' => 'alpha.localhost']);

    $tenantB = Tenant::create(['id' => (string) Str::uuid()]);
    $tenantB->domains()->create(['domain' => 'beta.localhost']);

    $permissionRegistrar = app(PermissionRegistrar::class);
    $initialTeamId = $permissionRegistrar->getPermissionsTeamId();

    try {
        $permissionRegistrar->setPermissionsTeamId($tenantA->id);
        Role::create(['name' => 'alpha-role', 'guard_name' => 'web']);

        $permissionRegistrar->setPermissionsTeamId($tenantB->id);
        Role::create(['name' => 'beta-role', 'guard_name' => 'web']);

        $user = User::factory()->create();

        app(AssignRolesToMember::class)->execute($user, ['alpha-role'], (string) $tenantA->id);
        app(AssignRolesToMember::class)->execute($user, ['beta-role'], (string) $tenantB->id);

        $permissionRegistrar->setPermissionsTeamId('stale-team-id');
        $permissionRegistrar->initializeCache();

        Route::middleware([
            'web',
            InitializeTenancyByDomain::class,
            PreventAccessFromCentralDomains::class,
            SetTenantTeamContext::class,
        ])->get('/_test/current-roles-cache-isolation', function (Request $request) {
            return response()->json($request->user()->roles->pluck('name')->values()->all());
        });

        $this->actingAs($user)
            ->get('http://alpha.localhost/_test/current-roles-cache-isolation')
            ->assertOk()
            ->assertExactJson(['alpha-role']);

        $this->actingAs($user)
            ->get('http://beta.localhost/_test/current-roles-cache-isolation')
            ->assertOk()
            ->assertExactJson(['beta-role']);
    } finally {
        $permissionRegistrar->setPermissionsTeamId($initialTeamId);
        $permissionRegistrar->clearPermissionsCollection();
        $permissionRegistrar->initializeCache();
    }
});
