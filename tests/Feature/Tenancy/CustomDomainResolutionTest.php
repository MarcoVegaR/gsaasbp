<?php

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tenancy.central_domains' => ['localhost']]);
});

test('tenant is resolved by custom domain host', function () {
    $tenant = Tenant::create(['id' => (string) Str::uuid()]);
    $tenant->domains()->create(['domain' => 'acme.test']);

    $response = $this->get('http://acme.test/');

    $response->assertOk();
    $response->assertSee((string) $tenant->id);
});
