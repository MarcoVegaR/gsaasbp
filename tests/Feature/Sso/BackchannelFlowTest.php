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
        'sso.token_store' => 'file',
    ]);
});

test('backchannel SSO uses post body and authenticates tenant session after consume', function () {
    $tenant = Tenant::create(['id' => (string) Str::uuid()]);
    $tenant->domains()->create(['domain' => 'tenant.localhost']);

    $user = User::factory()->create();

    TenantUser::query()->create([
        'tenant_id' => (string) $tenant->id,
        'user_id' => $user->id,
        'is_active' => true,
        'is_banned' => false,
    ]);

    $startResponse = $this->actingAs($user)->post('http://localhost/sso/start', [
        'tenant_domain' => 'tenant.localhost',
        'redirect_path' => '/tenant/dashboard',
    ], [
        'Origin' => 'http://localhost',
        'Sec-Fetch-Site' => 'same-origin',
        'Sec-Fetch-Mode' => 'navigate',
        'Sec-Fetch-Dest' => 'document',
    ]);

    $startResponse->assertOk();
    $startResponse->assertHeader('X-Frame-Options', 'DENY');
    $startResponse->assertHeader('Referrer-Policy', 'no-referrer');
    $cacheControl = strtolower((string) $startResponse->headers->get('Cache-Control', ''));
    expect($cacheControl)->toContain('no-store');
    expect($cacheControl)->toContain('no-cache');
    expect($cacheControl)->toContain('must-revalidate');
    $startResponse->assertSee('method="POST"', false);
    $startResponse->assertSee('name="code"', false);

    $html = $startResponse->getContent();

    expect($html)->toBeString();

    preg_match('/name="code" value="([^"]+)"/', (string) $html, $codeMatch);
    preg_match('/name="state" value="([^"]+)"/', (string) $html, $stateMatch);

    expect($codeMatch[1] ?? null)->toBeString()->not->toBe('');
    expect($stateMatch[1] ?? null)->toBeString()->not->toBe('');

    $consumeResponse = $this->post('http://tenant.localhost/sso/consume', [
        'code' => $codeMatch[1],
        'state' => $stateMatch[1],
    ]);

    $consumeResponse->assertRedirect('/tenant/dashboard');

    $this->get('http://tenant.localhost/tenant/dashboard')->assertOk();

    $membership = TenantUser::query()
        ->where('tenant_id', (string) $tenant->id)
        ->where('user_id', $user->id)
        ->first();

    expect($membership)->not->toBeNull();
    expect($membership?->last_sso_at)->not->toBeNull();
});
