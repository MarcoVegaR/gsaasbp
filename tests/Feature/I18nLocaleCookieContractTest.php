<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

uses(RefreshDatabase::class);

function findCookieByName(array $cookies, string $name): ?Cookie
{
    foreach ($cookies as $c) {
        if ($c instanceof Cookie && $c->getName() === $name) {
            return $c;
        }
    }

    return null;
}

function ensureTenantDomain(string $host): Tenant
{
    $tenant = Tenant::create(['id' => (string) Str::uuid()]);
    $tenant->domains()->create(['domain' => $host]);

    return $tenant;
}

test('cookie locale es host-only y tiene atributos Path/SameSite/Secure correctos', function () {
    config(['tenancy.central_domains' => ['localhost']]);
    config(['app.supported_locales' => ['en', 'es']]);

    $tenantHost = 'tenant.'.(config('tenancy.central_domains', ['localhost'])[0] ?? 'localhost');
    $cookieName = config('app.locale_cookie', 'locale');
    ensureTenantDomain($tenantHost);

    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $tenantDashboardUrl = "http://{$tenantHost}/tenant/dashboard";

    // HTTP (no Secure)
    $resp = test()->actingAs($user)->get("{$tenantDashboardUrl}?lang=es");

    $resp->assertOk();

    $cookies = $resp->headers->getCookies();
    $cookie = findCookieByName($cookies, $cookieName);

    expect($cookie)->not->toBeNull();
    /** @var Cookie $cookie */

    // Host-only: Domain debe ser null y header NO debe contener "Domain="
    expect($cookie->getDomain())->toBeNull();

    $setCookieHeaders = (array) $resp->headers->all('set-cookie');
    $joined = implode("\n", $setCookieHeaders);
    expect(stripos($joined, 'Domain=') === false)->toBeTrue('Set-Cookie contiene Domain= (no host-only)');

    // Path y SameSite
    expect($cookie->getPath())->toBe('/');
    expect(strtolower((string) $cookie->getSameSite()))->toBe('lax');

    // En HTTP no debe ser Secure
    expect($cookie->isSecure())->toBeFalse();

    // HTTPS (Secure requerido)
    $resp = test()->actingAs($user)->get("https://{$tenantHost}/tenant/dashboard?lang=es");

    $resp->assertStatus(200);
    $cookie = findCookieByName($resp->headers->getCookies(), $cookieName);
    expect($cookie)->not->toBeNull();
    expect($cookie->isSecure())->toBeTrue();
});

test('lang inválido es rechazado y no setea cookie', function () {
    config(['tenancy.central_domains' => ['localhost']]);
    config(['app.supported_locales' => ['en', 'es']]);

    $tenantHost = 'tenant.'.(config('tenancy.central_domains', ['localhost'])[0] ?? 'localhost');
    $cookieName = config('app.locale_cookie', 'locale');
    ensureTenantDomain($tenantHost);

    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $resp = test()
        ->actingAs($user)
        ->get("http://{$tenantHost}/tenant/dashboard?lang=__INVALID__");

    $resp->assertStatus(422);

    $cookie = findCookieByName($resp->headers->getCookies(), $cookieName);
    expect($cookie)->toBeNull("No debe setearse cookie {$cookieName} en lang inválido.");
});
