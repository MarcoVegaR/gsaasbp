<?php

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tenancy.central_domains' => ['localhost']]);
});

test('central routes are reachable from central domains', function () {
    $response = $this->get('http://localhost/');

    $response->assertOk();
    $response->assertDontSee('This is your multi-tenant application');
});

test('tenant root is isolated from central route payload', function () {
    $tenant = Tenant::create(['id' => (string) Str::uuid()]);
    $tenant->domains()->create(['domain' => 'acme.localhost']);

    $response = $this->get('http://acme.localhost/');

    $response->assertOk();
    $response->assertSee((string) $tenant->id);
});
