<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const INERTIA_PAYLOAD_BUDGET_BYTES = 15 * 1024;

function containsKeyRecursive(array $data, string $needle): bool
{
    foreach ($data as $k => $v) {
        if ($k === $needle) {
            return true;
        }
        if (is_array($v) && containsKeyRecursive($v, $needle)) {
            return true;
        }
    }

    return false;
}

function ensureTenantDomainForPayload(string $host): Tenant
{
    $tenant = Tenant::create(['id' => (string) Str::uuid()]);
    $tenant->domains()->create(['domain' => $host]);

    return $tenant;
}

function inertiaVersionHeaderValue(): string
{
    $assetUrl = config('app.asset_url');

    if (is_string($assetUrl) && $assetUrl !== '') {
        return hash('xxh128', $assetUrl);
    }

    $viteManifest = public_path('build/manifest.json');

    if (is_file($viteManifest)) {
        return hash_file('xxh128', $viteManifest) ?: '';
    }

    $mixManifest = public_path('mix-manifest.json');

    if (is_file($mixManifest)) {
        return hash_file('xxh128', $mixManifest) ?: '';
    }

    return '';
}

function inertiaJsonGet(string $host, string $path, ?User $user = null): TestResponse
{
    $request = test()->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => inertiaVersionHeaderValue(),
    ]);

    if ($user !== null) {
        $request->actingAs($user);
    }

    return $request->get("http://{$host}{$path}");
}

function assertInertiaPayloadContract(TestResponse $response, array $forbiddenKeys = ['translationsAll', 'routesAll']): void
{
    $response->assertOk();

    $response->assertHeader('X-Inertia', 'true');

    $page = $response->json();

    expect($page)->toBeArray()
        ->and($page)->toHaveKey('props');

    // Aserción de protocolo: no estamos midiendo HTML
    expect($page)->toHaveKey('component')
        ->and($page)->toHaveKey('url');

    // Contrato: props.errors debe existir
    expect($page['props'])->toHaveKey('errors');

    // Contrato: coreDictionary presente y no vacío
    expect($page['props'])->toHaveKey('coreDictionary');
    expect($page['props']['coreDictionary'])->toBeArray()->not->toBeEmpty();

    foreach ($forbiddenKeys as $k) {
        if (containsKeyRecursive($page['props'], $k)) {
            expect(false)->toBeTrue("Key prohibida detectada en props: {$k}");
        }
    }

    $json = json_encode($page['props'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    expect($json)->not->toBeFalse();

    $bytes = strlen($json); // strlen() en PHP mide bytes del string.
    expect($bytes)->toBeLessThanOrEqual(INERTIA_PAYLOAD_BUDGET_BYTES, "Payload props excede budget: {$bytes} bytes");
}

test('inertia payload budget y protocolo en 3 rutas canónicas', function () {
    config(['tenancy.central_domains' => ['localhost']]);

    $centralHost = config('tenancy.central_domains', ['localhost'])[0] ?? 'localhost';
    $tenantHost = 'tenant.'.$centralHost;
    ensureTenantDomainForPayload($tenantHost);

    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $centralResponse = inertiaJsonGet($centralHost, '/');
    assertInertiaPayloadContract($centralResponse);

    $tenantDashboardResponse = inertiaJsonGet($tenantHost, '/tenant/dashboard', $user);
    assertInertiaPayloadContract($tenantDashboardResponse);

    $tenantSettingsResponse = inertiaJsonGet($tenantHost, '/tenant/settings', $user);
    assertInertiaPayloadContract($tenantSettingsResponse);
});
