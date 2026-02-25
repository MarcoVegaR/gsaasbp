<?php

declare(strict_types=1);

use App\Http\Middleware\ForcePlatformGuard;
use App\Models\ActivityLog;
use App\Models\PlatformImpersonationSession;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const PHASE7_TEST_USER_AGENT = 'Phase7TestAgent/1.0';
const PHASE7_TEST_ACCEPT_LANGUAGE = 'en-US';

beforeEach(function (): void {
    config([
        'tenancy.central_domains' => ['localhost', '127.0.0.1'],
        'phase5.superadmin.emails' => ['superadmin@example.test'],
        'phase5.step_up.allowed_scopes' => [
            'platform.tenants.hard-delete',
            'platform.audit.export',
            'platform.billing.reconcile',
            'platform.impersonation.issue',
        ],
        'phase7.telemetry.privacy_budget.cache_store' => 'array',
        'phase7.telemetry.privacy_budget.window_seconds' => 3600,
        'session.driver' => 'file',
    ]);
});

function phase7CreateTenant(string $domain, string $status = 'active'): Tenant
{
    $tenant = Tenant::create([
        'id' => (string) Str::uuid(),
        'status' => $status,
    ]);

    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function phase7CreateSuperadmin(string $email = 'superadmin@example.test'): User
{
    return User::factory()->create([
        'email' => $email,
        'email_verified_at' => now(),
    ]);
}

/**
 * @return array<string, string>
 */
function phase7AdminMutationHeaders(): array
{
    return [
        'Origin' => 'https://localhost',
        'User-Agent' => PHASE7_TEST_USER_AGENT,
        'Accept-Language' => PHASE7_TEST_ACCEPT_LANGUAGE,
    ];
}

function phase7InertiaVersionHeaderValue(): string
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

function phase7InertiaGet(
    string $host,
    string $path,
    User $user,
    string $guard = 'web',
    array $headers = [],
): TestResponse {
    $request = test()
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => phase7InertiaVersionHeaderValue(),
            ...$headers,
        ])
        ->actingAs($user, $guard);

    return $request->get("http://{$host}{$path}");
}

function phase7Fingerprint(
    string $userAgent = PHASE7_TEST_USER_AGENT,
    string $acceptLanguage = PHASE7_TEST_ACCEPT_LANGUAGE,
): string {
    return hash('sha256', implode('|', [$userAgent, $acceptLanguage]));
}

test('admin panel inertia payload exposes platform guard and frame protections', function () {
    $superadmin = phase7CreateSuperadmin();

    $response = phase7InertiaGet('localhost', '/admin/panel', $superadmin, 'platform');

    $response->assertOk();
    $response->assertHeader('X-Inertia', 'true');
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertJsonPath('component', 'admin/panel');
    $response->assertJsonPath('props.auth.guard', 'platform');

    $csp = (string) $response->headers->get('Content-Security-Policy', '');

    expect(str_contains(strtolower($csp), "frame-ancestors 'none'"))->toBeTrue();
});

test('admin middleware priority keeps platform guard before substitute bindings', function () {
    $adminPanelRoute = collect(app('router')->getRoutes()->getRoutes())
        ->first(static fn ($route): bool => $route->getName() === 'admin.panel');

    expect($adminPanelRoute)->not->toBeNull();

    $middlewares = $adminPanelRoute->gatherMiddleware();

    expect(
        in_array('phase5.platform.guard', $middlewares, true)
            || in_array(ForcePlatformGuard::class, $middlewares, true)
    )->toBeTrue();

    /** @var \Illuminate\Foundation\Http\Kernel $kernel */
    $kernel = app(HttpKernelContract::class);
    $priority = $kernel->getMiddlewarePriority();

    $forcePlatformGuardIndex = array_search(ForcePlatformGuard::class, $priority, true);
    $substituteBindingsIndex = array_search(SubstituteBindings::class, $priority, true);

    expect($forcePlatformGuardIndex)->toBeInt();
    expect($substituteBindingsIndex)->toBeInt();
    expect($forcePlatformGuardIndex)->toBeLessThan($substituteBindingsIndex);
});

test('tenant-authenticated users cannot access admin panel with tenant guard session', function () {
    $tenantUser = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $this->actingAs($tenantUser, 'web')
        ->get('http://localhost/admin/panel')
        ->assertStatus(302)
        ->assertRedirect('http://localhost/login');
});

test('admin query secrets are rejected and php session id query key is blocked', function () {
    $superadmin = phase7CreateSuperadmin();

    $this->actingAs($superadmin, 'platform')
        ->getJson('http://localhost/admin/panel?PHPSESSID=known')
        ->assertStatus(400)
        ->assertJsonPath('code', 'ADMIN_QUERY_REJECTED');
});

test('admin origin validation denies cross-origin mutation requests', function () {
    $superadmin = phase7CreateSuperadmin();

    $this->actingAs($superadmin, 'platform')
        ->withHeaders([
            'Origin' => 'https://evil.localhost',
            'User-Agent' => PHASE7_TEST_USER_AGENT,
            'Accept-Language' => PHASE7_TEST_ACCEPT_LANGUAGE,
        ])
        ->postJson('https://localhost/admin/step-up/capabilities', [
            'scope' => 'platform.audit.export',
        ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'INVALID_ORIGIN');
});

test('hard delete approval enforces tenant binding and single use semantics', function () {
    config([
        'phase5.superadmin.emails' => [
            'approver@example.test',
            'executor@example.test',
        ],
    ]);

    $approver = phase7CreateSuperadmin('approver@example.test');
    $executor = phase7CreateSuperadmin('executor@example.test');
    $approvalService = app(\App\Support\Phase7\HardDeleteApprovalService::class);

    $tenantA = phase7CreateTenant('alpha.localhost');
    $tenantB = phase7CreateTenant('beta.localhost');

    $approval = $approvalService->issue(
        tenantId: (string) $tenantA->id,
        requestedByPlatformUserId: (int) $approver->id + 100,
        approvedByPlatformUserId: (int) $approver->id,
        executorPlatformUserId: (int) $executor->id,
        reasonCode: 'retention_expired',
    );

    $wrongTenantConsume = $approvalService->consume(
        approvalId: (string) $approval->getKey(),
        tenantId: (string) $tenantB->id,
        executorPlatformUserId: (int) $executor->id,
        reasonCode: 'retention_expired',
    );

    $validConsume = $approvalService->consume(
        approvalId: (string) $approval->getKey(),
        tenantId: (string) $tenantA->id,
        executorPlatformUserId: (int) $executor->id,
        reasonCode: 'retention_expired',
    );

    $replayConsume = $approvalService->consume(
        approvalId: (string) $approval->getKey(),
        tenantId: (string) $tenantA->id,
        executorPlatformUserId: (int) $executor->id,
        reasonCode: 'retention_expired',
    );

    expect($wrongTenantConsume)->toBeFalse();
    expect($validConsume)->toBeTrue();
    expect($replayConsume)->toBeFalse();
});

test('forensics endpoints require bounded sargable windows', function () {
    $superadmin = phase7CreateSuperadmin();
    $explorer = app(\App\Support\Phase7\ForensicAuditExplorerService::class);

    $this->actingAs($superadmin, 'platform')
        ->getJson('http://localhost/admin/forensics/audit')
        ->assertStatus(422);

    $from = now()->subDays(31)->toIso8601String();
    $to = now()->toIso8601String();
    $fromQuery = rawurlencode($from);
    $toQuery = rawurlencode($to);

    $this->actingAs($superadmin, 'platform')
        ->getJson("http://localhost/admin/forensics/audit?from={$fromQuery}&to={$toQuery}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_AUDIT_WINDOW');

    expect(fn () => $explorer->exportRows(
        from: CarbonImmutable::parse($from),
        to: CarbonImmutable::parse($to),
        filters: [],
    ))->toThrow(InvalidArgumentException::class);
});

test('forensic export tokens are one-time and token querystrings are rejected', function () {
    $superadmin = phase7CreateSuperadmin();
    $tenant = phase7CreateTenant('forensics.localhost');
    $exportService = app(\App\Support\Phase7\ForensicExportService::class);

    ActivityLog::query()->create([
        'tenant_id' => (string) $tenant->id,
        'event' => 'phase7.forensics.export',
        'request_id' => 'req-phase7-export',
        'actor_id' => $superadmin->id,
        'hmac_kid' => 'kid-phase7',
        'properties' => ['source' => 'phase7-test'],
        'created_at' => now()->subMinutes(5),
    ]);

    $from = now()->subHour()->toIso8601String();
    $to = now()->toIso8601String();

    $export = $exportService->request(
        platformUserId: (int) $superadmin->id,
        reasonCode: 'export_contract_test',
        from: CarbonImmutable::parse($from),
        to: CarbonImmutable::parse($to),
        filters: [
            'tenant_id' => (string) $tenant->id,
        ],
    );

    $issuedToken = $exportService->issueDownloadToken((string) $export->getKey(), (int) $superadmin->id);

    expect($issuedToken)->not->toBeNull();

    $token = (string) ($issuedToken['token'] ?? '');

    $this->actingAs($superadmin, 'platform')
        ->withHeaders(phase7AdminMutationHeaders())
        ->post('https://localhost/admin/forensics/exports/download', [
            'token' => $token,
        ])
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json');

    $this->actingAs($superadmin, 'platform')
        ->withHeaders(phase7AdminMutationHeaders())
        ->post('https://localhost/admin/forensics/exports/download', [
            'token' => $token,
        ])
        ->assertStatus(410);

    $this->actingAs($superadmin, 'platform')
        ->withHeaders(phase7AdminMutationHeaders())
        ->postJson('https://localhost/admin/forensics/exports/download?token=leaked')
        ->assertStatus(400)
        ->assertJsonPath('code', 'ADMIN_QUERY_REJECTED');
});

test('telemetry privacy budget returns 429 after iterative requests', function () {
    config([
        'phase7.telemetry.privacy_budget.max_cost_per_window' => 7,
    ]);

    $superadmin = phase7CreateSuperadmin();

    $from = now()->subHour()->toIso8601String();
    $to = now()->toIso8601String();
    $fromQuery = rawurlencode($from);
    $toQuery = rawurlencode($to);

    $first = $this->actingAs($superadmin, 'platform')
        ->getJson("http://localhost/admin/telemetry/analytics?from={$fromQuery}&to={$toQuery}&event=telemetry.signal");

    $first->assertOk();

    $this->actingAs($superadmin, 'platform')
        ->getJson("http://localhost/admin/telemetry/analytics?from={$fromQuery}&to={$toQuery}&event=telemetry.signal")
        ->assertStatus(429)
        ->assertJsonPath('code', 'PRIVACY_BUDGET_EXHAUSTED');
});

test('admin can terminate impersonation and tenant resource server enforces revoked jti', function () {
    $superadmin = phase7CreateSuperadmin();
    $tenant = phase7CreateTenant('impersonation.localhost');

    $targetUser = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    TenantUser::query()->create([
        'tenant_id' => (string) $tenant->id,
        'user_id' => $targetUser->id,
        'is_active' => true,
        'is_banned' => false,
        'membership_status' => 'active',
        'membership_revoked_at' => null,
    ]);

    $jti = (string) Str::uuid();

    PlatformImpersonationSession::query()->create([
        'jti' => $jti,
        'platform_user_id' => $superadmin->id,
        'target_tenant_id' => (string) $tenant->id,
        'target_user_id' => $targetUser->id,
        'reason_code' => 'ticket-terminate',
        'fingerprint' => phase7Fingerprint(),
        'issued_at' => now(),
        'expires_at' => now()->addMinutes(5),
        'consumed_at' => now(),
        'revoked_at' => null,
    ]);

    $this->actingAs($superadmin, 'platform')
        ->withHeaders(phase7AdminMutationHeaders())
        ->postJson('https://localhost/admin/impersonation/terminate', [
            'jti' => $jti,
        ])
        ->assertOk()
        ->assertJsonPath('status', 'terminated');

    $this->actingAs($targetUser, 'web')
        ->withSession([
            'phase5.impersonation' => [
                'is_impersonating' => 'true',
                'actor_platform_user_id' => (string) $superadmin->id,
                'subject_user_id' => (string) $targetUser->id,
                'impersonation_ticket_id' => 'ticket-terminate',
                'jti' => $jti,
            ],
        ])
        ->withHeaders([
            'User-Agent' => PHASE7_TEST_USER_AGENT,
            'Accept-Language' => PHASE7_TEST_ACCEPT_LANGUAGE,
        ])
        ->getJson('http://impersonation.localhost/tenant/dashboard')
        ->assertStatus(403)
        ->assertJsonPath('code', 'IMPERSONATION_EXPIRED');
});

test('tenant break-glass banner endpoint terminates session and revokes jti server-side', function () {
    $tenant = phase7CreateTenant('tenant.localhost');

    $targetUser = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    TenantUser::query()->create([
        'tenant_id' => (string) $tenant->id,
        'user_id' => $targetUser->id,
        'is_active' => true,
        'is_banned' => false,
        'membership_status' => 'active',
        'membership_revoked_at' => null,
    ]);

    $jti = (string) Str::uuid();

    PlatformImpersonationSession::query()->create([
        'jti' => $jti,
        'platform_user_id' => 9001,
        'target_tenant_id' => (string) $tenant->id,
        'target_user_id' => $targetUser->id,
        'reason_code' => 'ticket-active',
        'fingerprint' => phase7Fingerprint(),
        'issued_at' => now(),
        'expires_at' => now()->addMinutes(5),
        'consumed_at' => now(),
        'revoked_at' => null,
    ]);

    $this->actingAs($targetUser, 'web')
        ->withSession([
            'phase5.impersonation' => [
                'is_impersonating' => 'true',
                'actor_platform_user_id' => '9001',
                'subject_user_id' => (string) $targetUser->id,
                'impersonation_ticket_id' => 'ticket-active',
                'jti' => $jti,
            ],
        ])
        ->withHeaders([
            'User-Agent' => PHASE7_TEST_USER_AGENT,
            'Accept-Language' => PHASE7_TEST_ACCEPT_LANGUAGE,
        ])
        ->postJson('http://tenant.localhost/tenant/impersonation/terminate')
        ->assertOk()
        ->assertJsonPath('status', 'terminated');

    $session = PlatformImpersonationSession::query()->find($jti);

    expect($session)->toBeInstanceOf(PlatformImpersonationSession::class);
    expect($session?->revoked_at)->not->toBeNull();
});

test('tenant dashboard shares impersonation context when active jti is valid', function () {
    $tenant = phase7CreateTenant('tenant.localhost');

    $targetUser = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    TenantUser::query()->create([
        'tenant_id' => (string) $tenant->id,
        'user_id' => $targetUser->id,
        'is_active' => true,
        'is_banned' => false,
        'membership_status' => 'active',
        'membership_revoked_at' => null,
    ]);

    $jti = (string) Str::uuid();

    PlatformImpersonationSession::query()->create([
        'jti' => $jti,
        'platform_user_id' => 9001,
        'target_tenant_id' => (string) $tenant->id,
        'target_user_id' => $targetUser->id,
        'reason_code' => 'ticket-active',
        'fingerprint' => phase7Fingerprint(),
        'issued_at' => now(),
        'expires_at' => now()->addMinutes(5),
        'consumed_at' => now(),
        'revoked_at' => null,
    ]);

    $response = $this
        ->actingAs($targetUser, 'web')
        ->withSession([
            'phase5.impersonation' => [
                'is_impersonating' => 'true',
                'actor_platform_user_id' => '9001',
                'subject_user_id' => (string) $targetUser->id,
                'impersonation_ticket_id' => 'ticket-active',
                'jti' => $jti,
            ],
        ])
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => phase7InertiaVersionHeaderValue(),
            'User-Agent' => PHASE7_TEST_USER_AGENT,
            'Accept-Language' => PHASE7_TEST_ACCEPT_LANGUAGE,
        ])
        ->get('http://tenant.localhost/tenant/dashboard');

    $response->assertOk();
    $response->assertJsonPath('props.impersonation.is_impersonating', 'true');
    $response->assertJsonPath('props.impersonation.actor_platform_user_id', '9001');
    $response->assertJsonPath('props.impersonation.impersonation_ticket_id', 'ticket-active');
    $response->assertJsonPath('props.impersonation.jti', $jti);
});
