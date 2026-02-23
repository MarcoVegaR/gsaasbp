<?php

use App\Exceptions\MissingTenantContextException;
use App\Models\Tenant;
use App\Models\TenantNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tenancy.central_domains' => ['localhost']]);
});

test('tenant note queries only return data for the active tenant', function () {
    $tenantA = Tenant::create(['id' => (string) Str::uuid()]);
    $tenantA->domains()->create(['domain' => 'tenant-a.localhost']);

    $tenantB = Tenant::create(['id' => (string) Str::uuid()]);
    $tenantB->domains()->create(['domain' => 'tenant-b.localhost']);

    $tenantA->run(fn () => TenantNote::factory()->create(['title' => 'A note']));
    $tenantB->run(fn () => TenantNote::factory()->create(['title' => 'B note']));

    $tenantA->run(function (): void {
        expect(TenantNote::query()->pluck('title')->all())->toBe(['A note']);
    });

    $tenantB->run(function (): void {
        expect(TenantNote::query()->pluck('title')->all())->toBe(['B note']);
    });
});

test('tenant business model access fails closed when tenant is missing on non-central host', function () {
    Route::middleware('web')->get('/_test/fail-closed-scope', function () {
        return (string) TenantNote::query()->count();
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->get('http://unidentified.localhost/_test/fail-closed-scope'))
        ->toThrow(MissingTenantContextException::class);
});
