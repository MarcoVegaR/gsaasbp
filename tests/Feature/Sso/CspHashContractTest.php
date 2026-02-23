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
        'sso.token_store' => 'array',
    ]);
});

test('auto submit page csp sha256 hash matches inline script', function () {
    $tenant = Tenant::create(['id' => (string) Str::uuid()]);
    $tenant->domains()->create(['domain' => 'tenant.localhost']);

    $user = User::factory()->create();

    TenantUser::query()->create([
        'tenant_id' => (string) $tenant->id,
        'user_id' => $user->id,
        'is_active' => true,
        'is_banned' => false,
    ]);

    $response = $this->actingAs($user)->post('http://localhost/sso/start', [
        'tenant_domain' => 'tenant.localhost',
        'redirect_path' => '/tenant/dashboard',
    ], [
        'Origin' => 'http://localhost',
        'Sec-Fetch-Site' => 'same-origin',
        'Sec-Fetch-Mode' => 'navigate',
        'Sec-Fetch-Dest' => 'document',
    ]);

    $response->assertOk();

    $csp = (string) $response->headers->get('Content-Security-Policy', '');
    $html = (string) $response->getContent();

    expect($csp)->not->toContain('unsafe-inline');

    preg_match('/<script>(.*?)<\/script>/s', $html, $matches);

    $script = $matches[1] ?? null;

    expect($script)->toBeString()->not->toBe('');

    $expectedHash = base64_encode(hash('sha256', (string) $script, true));

    expect($csp)->toContain("script-src 'sha256-{$expectedHash}'");
    expect($csp)->toContain("frame-ancestors 'none'");
});
