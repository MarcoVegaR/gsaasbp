<?php

use App\Models\Tenant;
use App\Models\TenantNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tenancy.central_domains' => ['localhost']]);
});

test('database tenancy bootstrapper is disabled in single database mode', function () {
    expect(config('tenancy.bootstrappers'))->not->toContain(DatabaseTenancyBootstrapper::class);
});

test('records from multiple tenants are persisted in the same central database', function () {
    $tenantA = Tenant::create(['id' => (string) Str::uuid()]);
    $tenantA->domains()->create(['domain' => 'alpha.localhost']);

    $tenantB = Tenant::create(['id' => (string) Str::uuid()]);
    $tenantB->domains()->create(['domain' => 'beta.localhost']);

    $tenantA->run(fn () => TenantNote::factory()->create(['title' => 'note-a']));
    $tenantB->run(fn () => TenantNote::factory()->create(['title' => 'note-b']));

    expect(DB::table('tenant_notes')->count())->toBe(2);
    expect(DB::table('tenant_notes')->pluck('title')->all())
        ->toContain('note-a')
        ->toContain('note-b');
});
