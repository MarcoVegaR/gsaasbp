<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'tenancy.central_domains' => ['localhost'],
        'sso.s2s.clients' => [
            'token-claims' => [
                'tenant_id' => 'placeholder',
                'caller' => 'tenant-api',
            ],
        ],
    ]);
});

test('claims endpoint returns the strict versioned dto allowlist', function () {
    $tenant = Tenant::create(['id' => (string) Str::uuid()]);
    $tenant->domains()->create(['domain' => 'tenant.localhost']);

    $user = User::factory()->withTwoFactor()->create([
        'email_verified_at' => now(),
    ]);

    TenantUser::query()->create([
        'tenant_id' => (string) $tenant->id,
        'user_id' => $user->id,
        'is_active' => true,
        'is_banned' => false,
    ]);

    config([
        'sso.s2s.clients' => [
            'token-claims' => [
                'tenant_id' => (string) $tenant->id,
                'caller' => 'tenant-api',
            ],
        ],
    ]);

    $response = $this->getJson(
        'http://localhost/idp/claims/'.$user->id,
        ['X-S2S-Key' => 'token-claims'],
    );

    $response->assertOk();
    $response->assertExactJson([
        'version' => (string) config('sso.claims.version', 'v1'),
        'tenant_id' => (string) $tenant->id,
        'user_id' => (string) $user->id,
        'mfa_enabled' => true,
        'email_verified' => true,
    ]);
});
