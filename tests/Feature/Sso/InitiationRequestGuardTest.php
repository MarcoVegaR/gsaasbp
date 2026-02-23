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
        'sso.mode' => 'backchannel',
    ]);
});

test('sso initiation rejects requests without origin referer and sec-fetch-site signals', function () {
    $tenant = Tenant::create(['id' => (string) Str::uuid()]);
    $tenant->domains()->create(['domain' => 'tenant.localhost']);

    $user = User::factory()->create();

    TenantUser::query()->create([
        'tenant_id' => (string) $tenant->id,
        'user_id' => $user->id,
        'is_active' => true,
        'is_banned' => false,
    ]);

    $this->actingAs($user)
        ->post('http://localhost/sso/start', [
            'tenant_domain' => 'tenant.localhost',
            'redirect_path' => '/tenant/dashboard',
        ])
        ->assertForbidden();
});

test('sso initiation accepts same-origin fetch metadata signals', function () {
    $tenant = Tenant::create(['id' => (string) Str::uuid()]);
    $tenant->domains()->create(['domain' => 'tenant.localhost']);

    $user = User::factory()->create();

    TenantUser::query()->create([
        'tenant_id' => (string) $tenant->id,
        'user_id' => $user->id,
        'is_active' => true,
        'is_banned' => false,
    ]);

    $this->actingAs($user)
        ->post('http://localhost/sso/start', [
            'tenant_domain' => 'tenant.localhost',
            'redirect_path' => '/tenant/dashboard',
        ], [
            'Sec-Fetch-Site' => 'same-origin',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Dest' => 'document',
        ])
        ->assertOk();
});
