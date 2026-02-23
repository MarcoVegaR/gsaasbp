<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'tenancy.central_domains' => ['localhost'],
        'sso.claims.cache_store' => 'array',
        'sso.claims.quota_store' => 'array',
    ]);
});

test('claims endpoint enforces daily and weekly anti-scraping quotas', function () {
    $tenant = Tenant::create(['id' => (string) Str::uuid()]);
    $tenant->domains()->create(['domain' => 'tenant.localhost']);
    $user = User::factory()->create();

    TenantUser::query()->create([
        'tenant_id' => (string) $tenant->id,
        'user_id' => $user->id,
        'is_active' => true,
        'is_banned' => false,
    ]);

    config([
        'sso.s2s.clients' => [
            'token-quota' => [
                'tenant_id' => (string) $tenant->id,
                'caller' => 'tenant-api',
            ],
        ],
        'sso.claims.daily_quota' => 2,
        'sso.claims.weekly_quota' => 2,
        'sso.claims.rate_limit_per_minute' => 20,
    ]);

    $headers = ['X-S2S-Key' => 'token-quota'];

    $this->getJson('http://localhost/idp/claims/'.$user->id, $headers)->assertOk();
    $this->getJson('http://localhost/idp/claims/'.$user->id, $headers)->assertOk();
    $this->getJson('http://localhost/idp/claims/'.$user->id, $headers)->assertStatus(429);
});

test('claims service raises hit and miss alarms under anomalous cache patterns', function () {
    Log::spy();

    $tenant = Tenant::create(['id' => (string) Str::uuid()]);
    $tenant->domains()->create(['domain' => 'tenant.localhost']);
    $user = User::factory()->create();

    TenantUser::query()->create([
        'tenant_id' => (string) $tenant->id,
        'user_id' => $user->id,
        'is_active' => true,
        'is_banned' => false,
    ]);

    config([
        'sso.s2s.clients' => [
            'token-alarm' => [
                'tenant_id' => (string) $tenant->id,
                'caller' => 'tenant-api',
            ],
        ],
        'sso.claims.daily_quota' => 100,
        'sso.claims.weekly_quota' => 100,
        'sso.claims.rate_limit_per_minute' => 100,
        'sso.claims.alarm_hit_ratio_threshold' => 0.5,
        'sso.claims.alarm_miss_spike_threshold' => 1,
    ]);

    $headers = ['X-S2S-Key' => 'token-alarm'];

    for ($i = 0; $i < 10; $i++) {
        $this->getJson('http://localhost/idp/claims/'.$user->id, $headers)->assertOk();
    }

    Log::shouldHaveReceived('warning')
        ->with('sso.claims.miss_spike_alarm', Mockery::type('array'))
        ->atLeast()
        ->once();

    Log::shouldHaveReceived('warning')
        ->with('sso.claims.hit_ratio_alarm', Mockery::type('array'))
        ->atLeast()
        ->once();
});
