<?php

declare(strict_types=1);

use App\Events\Phase5\TenantStatusChanged;
use App\Http\Middleware\ForcePlatformGuard;
use App\Jobs\Phase5\LongRunningTenantMutationJob;
use App\Jobs\Phase5\SetTenantStatusJob;
use App\Models\ActivityLog;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\Phase5\Impersonation\ForensicImpersonationContextResolver;
use App\Support\Phase5\Impersonation\ImpersonationClaimValidator;
use App\Support\Phase5\JobAbortTelemetry;
use App\Support\Phase5\LongRunningJobProbe;
use App\Support\Phase5\PlatformContext;
use App\Support\Phase5\PlatformContextStore;
use App\Support\Phase5\PlatformTenantLifecycleService;
use App\Support\Phase5\StepUpCapabilityService;
use App\Support\Phase5\Telemetry\AnalyticsAggregateService;
use App\Support\Phase5\Telemetry\CollectorPayloadSanitizer;
use App\Support\Phase5\TenantStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'tenancy.central_domains' => ['localhost'],
        'phase5.superadmin.emails' => ['superadmin@example.test'],
        'phase5.platform.session_cookie' => '__Host-platform_session',
        'phase5.platform.session_same_site' => 'lax',
        'phase5.step_up.allowed_scopes' => ['platform.tenants.hard-delete'],
        'phase5.tenant_status.cache_store' => 'array',
        'phase5.tenant_status.cache_ttl_seconds' => 15,
        'phase5.telemetry.analytics.cache_store' => 'array',
        'phase5.telemetry.analytics.cache_ttl_seconds' => 120,
        'phase5.telemetry.analytics.k_anonymity' => 2,
        'phase5.telemetry.analytics.bucket_seconds' => 3600,
        'phase5.telemetry.analytics.contribution_cap_per_tenant' => 2,
        'phase5.telemetry.analytics.rounding_quantum' => 5,
        'phase5.telemetry.collector.resource_allowlist' => ['service.name', 'tenant_id'],
        'phase5.telemetry.collector.log_attribute_allowlist' => ['log.level', 'tenant_id'],
        'phase5.telemetry.collector.redacted_keys' => ['tenant_id', 'user_id'],
        'phase5.impersonation.allowed_actor_issuers' => ['trusted-issuer'],
        'sso.mode' => 'backchannel',
        'sso.token_store' => 'array',
        'session.driver' => 'file',
    ]);
});

function phase5CreateTenant(string $domain = 'tenant.localhost', ?string $status = null): Tenant
{
    $attributes = ['id' => (string) Str::uuid()];

    if (is_string($status) && $status !== '') {
        $attributes['status'] = $status;
    }

    $tenant = Tenant::create($attributes);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function phase5CreateSuperadmin(): User
{
    return User::factory()->create([
        'email' => 'superadmin@example.test',
        'email_verified_at' => now(),
    ]);
}

test('admin route coverage includes platform guard middleware on all admin routes', function () {
    $adminRoutes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(static fn ($route): bool => str_starts_with((string) $route->uri(), 'admin/'))
        ->values();

    expect($adminRoutes)->not->toBeEmpty();

    foreach ($adminRoutes as $route) {
        $middlewares = $route->gatherMiddleware();

        $hasPlatformGuard = in_array('phase5.platform.guard', $middlewares, true)
            || in_array(ForcePlatformGuard::class, $middlewares, true);

        expect($hasPlatformGuard)
            ->toBeTrue('Missing platform guard middleware on route '.$route->uri());
    }
});

test('admin dashboard runs with platform guard and emits strict host-only session cookie in https', function () {
    $superadmin = phase5CreateSuperadmin();

    $response = $this
        ->actingAs($superadmin, 'platform')
        ->get('https://localhost/admin/dashboard');

    $response->assertOk();
    $response->assertJsonPath('guard', 'platform');

    $cookieName = (string) config('phase5.platform.session_cookie');

    expect($cookieName)->toStartWith('__Host-');

    $cookie = collect($response->headers->getCookies())
        ->first(static fn (Cookie $candidate): bool => $candidate->getName() === $cookieName);

    expect($cookie)->toBeInstanceOf(Cookie::class);

    /** @var Cookie $cookie */
    expect($cookie->getPath())->toBe('/');
    expect($cookie->getDomain())->toBeNull();
    expect($cookie->isSecure())->toBeTrue();
    expect($cookie->isHttpOnly())->toBeTrue();
    expect(strtolower((string) $cookie->getSameSite()))->toBe('lax');

    $headerLine = collect((array) $response->headers->all('set-cookie'))
        ->first(static fn (string $line): bool => str_starts_with($line, $cookieName.'='));

    expect($headerLine)->toBeString();
    expect((string) $headerLine)->not->toContain('Domain=');
});

test('non-http platform entrypoint requires explicit PlatformContext', function () {
    $tenant = phase5CreateTenant();

    $jobWithoutContext = new SetTenantStatusJob((string) $tenant->id, 'suspended', null);

    expect(fn () => $jobWithoutContext->handle(
        app(PlatformContextStore::class),
        app(PlatformTenantLifecycleService::class),
    ))->toThrow(\RuntimeException::class, 'PLATFORM_CONTEXT_REQUIRED');

    $jobWithContext = new SetTenantStatusJob(
        tenantId: (string) $tenant->id,
        status: 'suspended',
        platformContext: new PlatformContext(platformUserId: 1, source: 'test-job', ticket: 'INC-001'),
    );

    $jobWithContext->handle(
        app(PlatformContextStore::class),
        app(PlatformTenantLifecycleService::class),
    );

    expect(Tenant::query()->find((string) $tenant->id)?->getAttribute('status'))->toBe('suspended');
});

test('step-up capability consumes atomically and only once', function () {
    $service = app(StepUpCapabilityService::class);
    $platformUser = User::factory()->create();

    $capability = $service->issue(
        platformUserId: (int) $platformUser->getAuthIdentifier(),
        sessionId: 'session-atomic',
        deviceFingerprint: 'device-atomic',
        scope: 'platform.tenants.hard-delete',
        ipAddress: '127.0.0.1',
        ttlSeconds: 300,
    );

    $firstConsume = $service->consume(
        capabilityId: (string) $capability->getKey(),
        platformUserId: (int) $platformUser->getAuthIdentifier(),
        sessionId: 'session-atomic',
        deviceFingerprint: 'device-atomic',
        scope: 'platform.tenants.hard-delete',
        ipAddress: '127.0.0.1',
        strictIp: true,
    );

    $secondConsume = $service->consume(
        capabilityId: (string) $capability->getKey(),
        platformUserId: (int) $platformUser->getAuthIdentifier(),
        sessionId: 'session-atomic',
        deviceFingerprint: 'device-atomic',
        scope: 'platform.tenants.hard-delete',
        ipAddress: '127.0.0.1',
        strictIp: true,
    );

    expect($firstConsume)->toBeTrue();
    expect($secondConsume)->toBeFalse();
});

test('denylist platform ability is never escalated and tenant policy is not auto-approved by platform superadmin', function () {
    $superadmin = phase5CreateSuperadmin();
    $tenant = phase5CreateTenant();

    $gate = Gate::forUser($superadmin);

    expect($gate->allows('platform.telemetry.view'))->toBeTrue();
    expect($gate->allows('platform.tenants.hard-delete'))->toBeFalse();
    expect($gate->allows('manageRbac', $tenant))->toBeFalse();
});

test('long running tenant job aborts before irreversible side effect after suspension', function () {
    $tenant = phase5CreateTenant(status: 'active');
    $tenantId = (string) $tenant->id;

    $job = new LongRunningTenantMutationJob($tenantId);

    $probe = new class(app(TenantStatusService::class)) implements LongRunningJobProbe
    {
        public function __construct(
            private readonly TenantStatusService $tenantStatus,
        ) {}

        public function beforeIrreversibleSideEffect(string $tenantId): void
        {
            $this->tenantStatus->setStatus($tenantId, 'suspended');
        }
    };

    $job->handle(
        app(TenantStatusService::class),
        app(JobAbortTelemetry::class),
        Cache::store('array'),
        $probe,
    );

    expect(Cache::store('array')->get('phase5:long-job:side-effect:'.$tenantId))->toBeNull();
    expect(Tenant::query()->find($tenantId)?->getAttribute('status'))->toBe('suspended');
});

test('job abort telemetry keeps low-cardinality labels only', function () {
    Log::spy();

    app(JobAbortTelemetry::class)->recordTenantStatusAbort('suspended');

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(static function (string $message, array $context): bool {
            if ($message !== 'job_aborted_due_to_tenant_status') {
                return false;
            }

            $required = ['metric', 'status', 'environment'];

            foreach ($required as $key) {
                if (! array_key_exists($key, $context)) {
                    return false;
                }
            }

            return ! array_key_exists('tenant_id', $context)
                && ! array_key_exists('job_class', $context)
                && ! array_key_exists('error_detail', $context);
        });
});

test('impersonation validator rejects untrusted act issuer and nested act claims', function () {
    $validator = app(ImpersonationClaimValidator::class);

    $untrustedIssuerClaims = [
        'sub' => 'subject-1',
        'aud' => 'tenant-a',
        'jti' => 'jti-1',
        'act' => [
            'sub' => 'actor-1',
            'iss' => 'evil-issuer',
            'ticket' => 'T-1',
        ],
    ];

    expect(fn () => $validator->validate($untrustedIssuerClaims, 'tenant-a'))
        ->toThrow(\InvalidArgumentException::class);

    $nestedActClaims = [
        'sub' => 'subject-1',
        'aud' => 'tenant-a',
        'jti' => 'jti-2',
        'act' => [
            'sub' => 'actor-1',
            'iss' => 'trusted-issuer',
            'act' => ['sub' => 'nested'],
        ],
    ];

    expect(fn () => $validator->validate($nestedActClaims, 'tenant-a'))
        ->toThrow(\InvalidArgumentException::class);
});

test('forensic impersonation context is derived from trusted claims and ignores payload spoofing', function () {
    $resolver = app(ForensicImpersonationContextResolver::class);

    $resolved = $resolver->resolve(
        trustedClaims: [
            'sub' => 'subject-safe',
            'act' => [
                'sub' => 'actor-safe',
                'iss' => 'trusted-issuer',
                'ticket' => 'INC-9000',
            ],
        ],
        requestPayload: [
            'actor_platform_user_id' => 'actor-spoof',
            'subject_user_id' => 'subject-spoof',
            'impersonation_ticket_id' => 'ticket-spoof',
        ],
    );

    expect($resolved['actor_platform_user_id'])->toBe('actor-safe');
    expect($resolved['subject_user_id'])->toBe('subject-safe');
    expect($resolved['impersonation_ticket_id'])->toBe('INC-9000');
    expect($resolved['is_impersonating'])->toBe('true');
});

test('collector sanitizer filters and redacts resource and log attributes before export', function () {
    $sanitizer = app(CollectorPayloadSanitizer::class);

    $sanitized = $sanitizer->sanitize([
        'resource_attributes' => [
            'service.name' => 'gsaasbp-app',
            'tenant_id' => 'tenant-sensitive',
            'email' => 'secret@example.test',
        ],
        'spans' => [],
        'metrics' => [],
        'logs' => [
            [
                'attributes' => [
                    'log.level' => 'info',
                    'tenant_id' => 'tenant-sensitive',
                    'user_id' => '999',
                ],
            ],
        ],
    ]);

    $resourceAttributes = (array) ($sanitized['resource_attributes'] ?? []);
    $logAttributes = (array) data_get($sanitized, 'logs.0.attributes', []);

    expect($resourceAttributes['service.name'] ?? null)->toBe('gsaasbp-app');
    expect($resourceAttributes['tenant_id'] ?? null)->toBe('[REDACTED]');
    expect(array_key_exists('email', $resourceAttributes))->toBeFalse();

    expect($logAttributes['log.level'] ?? null)->toBe('info');
    expect($logAttributes['tenant_id'] ?? null)->toBe('[REDACTED]');
    expect(array_key_exists('user_id', $logAttributes))->toBeFalse();
});

test('analytics anti-differencing keeps stable suppression and quantized rounded output under cache', function () {
    $tenantA = phase5CreateTenant('tenant-a.localhost');
    $tenantB = phase5CreateTenant('tenant-b.localhost');

    $from = CarbonImmutable::parse('2026-03-01 00:00:00');
    $to = CarbonImmutable::parse('2026-03-01 02:00:00');

    ActivityLog::query()->create([
        'tenant_id' => (string) $tenantA->id,
        'event' => 'telemetry.signal',
        'request_id' => 'req-1',
        'actor_id' => null,
        'hmac_kid' => 'kid-1',
        'properties' => [],
        'created_at' => CarbonImmutable::parse('2026-03-01 00:15:00'),
    ]);

    foreach (['01:05:00', '01:06:00'] as $index => $time) {
        ActivityLog::query()->create([
            'tenant_id' => (string) $tenantA->id,
            'event' => 'telemetry.signal',
            'request_id' => 'req-a-'.$index,
            'actor_id' => null,
            'hmac_kid' => 'kid-1',
            'properties' => [],
            'created_at' => CarbonImmutable::parse('2026-03-01 '.$time),
        ]);
    }

    foreach (['01:07:00', '01:08:00', '01:09:00'] as $index => $time) {
        ActivityLog::query()->create([
            'tenant_id' => (string) $tenantB->id,
            'event' => 'telemetry.signal',
            'request_id' => 'req-b-'.$index,
            'actor_id' => null,
            'hmac_kid' => 'kid-1',
            'properties' => [],
            'created_at' => CarbonImmutable::parse('2026-03-01 '.$time),
        ]);
    }

    $aggregator = app(AnalyticsAggregateService::class);

    $first = $aggregator->aggregate($from, $to, 'telemetry.signal');
    $second = $aggregator->aggregate($from, $to, 'telemetry.signal');

    expect($first)->toEqual($second);

    $series = collect($first['series'])->keyBy('bucket_start');

    $suppressedBucket = (string) CarbonImmutable::parse('2026-03-01 00:00:00')->toIso8601String();
    $visibleBucket = (string) CarbonImmutable::parse('2026-03-01 01:00:00')->toIso8601String();

    expect($series->get($suppressedBucket)['suppressed'])->toBeTrue();
    expect($series->get($suppressedBucket)['value'])->toBeNull();
    expect($series->get($suppressedBucket)['suppression_key'])->toBeString()->not->toBe('');

    expect($series->get($visibleBucket)['suppressed'])->toBeFalse();
    expect($series->get($visibleBucket)['value'])->toBe(5);
    expect($series->get($visibleBucket)['value'] % 5)->toBe(0);
});

test('sso start flow is blocked when tenant status circuit breaker is active', function () {
    $tenant = phase5CreateTenant(status: 'suspended');

    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    TenantUser::query()->create([
        'tenant_id' => (string) $tenant->id,
        'user_id' => $user->id,
        'is_active' => true,
        'is_banned' => false,
    ]);

    $response = $this->actingAs($user)->post('http://localhost/sso/start', [
        'tenant_domain' => 'tenant.localhost',
        'redirect_path' => '/tenant/dashboard',
    ], [
        'Origin' => 'http://localhost',
        'Sec-Fetch-Site' => 'same-origin',
        'Sec-Fetch-Mode' => 'navigate',
        'Sec-Fetch-Dest' => 'document',
    ]);

    $response->assertStatus(423);
});

test('tenant status cache is invalidated by tenant status changed event', function () {
    $tenant = phase5CreateTenant(status: 'active');
    $tenantId = (string) $tenant->id;

    $service = app(TenantStatusService::class);

    expect($service->status($tenantId))->toBe('active');

    Cache::store('array')->put('phase5:tenant_status:'.$tenantId, 'suspended', now()->addMinutes(5));

    event(new TenantStatusChanged($tenantId, 'active'));

    expect(Cache::store('array')->get('phase5:tenant_status:'.$tenantId))->toBeNull();
});
