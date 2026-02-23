<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Support\Sso\SsoCodeStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'tenancy.central_domains' => ['localhost'],
        'sso.token_store' => 'array',
    ]);
});

test('tenant A cannot redeem a backchannel code issued for tenant B', function () {
    $tenantA = Tenant::create(['id' => (string) Str::uuid()]);
    $tenantA->domains()->create(['domain' => 'alpha.localhost']);

    $tenantB = Tenant::create(['id' => (string) Str::uuid()]);
    $tenantB->domains()->create(['domain' => 'beta.localhost']);

    $user = User::factory()->create();

    config([
        'sso.s2s.clients' => [
            'token-alpha' => ['tenant_id' => (string) $tenantA->id, 'caller' => 'alpha-api'],
            'token-beta' => ['tenant_id' => (string) $tenantB->id, 'caller' => 'beta-api'],
        ],
    ]);

    $code = app(SsoCodeStore::class)->issue(
        (string) $tenantB->id,
        $user->id,
        '/tenant/dashboard',
        'state-123',
        'nonce-123',
    );

    $this->postJson('http://localhost/sso/redeem', [
        'code' => $code,
        'state' => 'state-123',
    ], [
        'X-S2S-Key' => 'token-alpha',
    ])->assertForbidden();

    $this->postJson('http://localhost/sso/redeem', [
        'code' => $code,
        'state' => 'state-123',
    ], [
        'X-S2S-Key' => 'token-beta',
    ])->assertOk()->assertJson([
        'tenant_id' => (string) $tenantB->id,
        'user_id' => $user->id,
        'redirect_path' => '/tenant/dashboard',
        'state' => 'state-123',
    ]);
});
