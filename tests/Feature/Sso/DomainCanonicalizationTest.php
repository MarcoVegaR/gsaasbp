<?php

declare(strict_types=1);

use App\Models\Domain;
use App\Models\Tenant;
use App\Support\Sso\DomainCanonicalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('domain canonicalizer stores tenant domains in canonical ascii form', function () {
    $tenant = Tenant::create(['id' => (string) Str::uuid()]);

    $domain = Domain::query()->create([
        'domain' => 'Tenant.LocalHost.',
        'tenant_id' => (string) $tenant->id,
    ]);

    expect($domain->fresh()?->domain)->toBe('tenant.localhost');
});

test('domain canonicalizer supports idn tr46 conversion when intl is available', function () {
    if (! function_exists('idn_to_ascii')) {
        $this->markTestSkipped('intl extension is not available on this environment.');
    }

    expect(DomainCanonicalizer::canonicalize('bücher.example'))->toBe('xn--bcher-kva.example');
});
