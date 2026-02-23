<?php

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;
use Tests\Support\EarlyIdentificationTenantProbe;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tenancy.central_domains' => ['localhost']]);
});

test('tenant context is initialized before route dependency resolution', function () {
    $tenant = Tenant::create(['id' => (string) Str::uuid()]);
    $tenant->domains()->create(['domain' => 'probe.localhost']);

    Route::middleware([
        'web',
        InitializeTenancyByDomain::class,
        PreventAccessFromCentralDomains::class,
    ])->get('/_test/early-identification', fn (EarlyIdentificationTenantProbe $probe) => response('ok'));

    $response = $this->get('http://probe.localhost/_test/early-identification');

    $response->assertOk();
    $response->assertSee('ok');
});
