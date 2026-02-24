<?php

use App\Actions\Rbac\AssignRolesToMember;
use App\Jobs\ReconcileTenantBillingJob;
use App\Models\ActivityLog;
use App\Models\BillingIncident;
use App\Models\InviteToken;
use App\Models\Tenant;
use App\Models\TenantEntitlement;
use App\Models\TenantEventDeduplication;
use App\Models\TenantSubscription;
use App\Models\TenantUser;
use App\Models\TenantUserProfileProjection;
use App\Models\User;
use App\Support\Billing\BillingService;
use App\Support\Phase4\Audit\AuditLogger;
use App\Support\Phase4\Audit\ForensicAuditRepository;
use App\Support\Phase4\Entitlements\EntitlementService;
use App\Support\Phase4\Events\TenantEventEnvelope;
use App\Support\Phase4\Events\TenantEventProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'tenancy.central_domains' => ['localhost'],
        'phase4.entitlements.default_granted' => false,
    ]);
});

function phase4CreateTenant(string $domain = 'tenant.localhost', ?string $tenantId = null): Tenant
{
    $tenant = Tenant::create(['id' => $tenantId ?? (string) Str::uuid()]);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function phase4RegisterRole(string $tenantId, string $roleName): void
{
    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();

    try {
        $registrar->setPermissionsTeamId($tenantId);
        Role::create([
            'name' => $roleName,
            'guard_name' => 'web',
        ]);
    } finally {
        $registrar->setPermissionsTeamId($previousTeamId);
        $registrar->clearPermissionsCollection();
        $registrar->initializeCache();
    }
}

function phase4GrantEntitlement(string $tenantId, string $feature): void
{
    TenantEntitlement::query()->updateOrCreate(
        [
            'tenant_id' => $tenantId,
            'feature' => $feature,
        ],
        [
            'granted' => true,
            'source' => 'test',
            'updated_by_event_id' => 'test-event',
            'expires_at' => null,
        ],
    );
}

function phase4CreateProjection(string $tenantId, int $userId, CarbonImmutable $staleAfter): void
{
    TenantUserProfileProjection::query()->updateOrCreate(
        [
            'tenant_id' => $tenantId,
            'central_user_id' => $userId,
        ],
        [
            'display_name' => 'Test User',
            'avatar_url' => null,
            'mfa_status' => true,
            'profile_version' => 1,
            'last_synced_at' => CarbonImmutable::now(),
            'stale_after' => $staleAfter,
        ],
    );
}

test('server-side stale guard blocks sensitive endpoint with 409 and allows fresh projections', function () {
    $tenant = phase4CreateTenant();
    $tenantId = (string) $tenant->id;

    $owner = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    phase4RegisterRole($tenantId, 'owner');
    app(AssignRolesToMember::class)->execute($owner, ['owner'], $tenantId);

    phase4GrantEntitlement($tenantId, 'tenant.rbac');

    phase4CreateProjection($tenantId, $owner->id, CarbonImmutable::now()->subMinute());

    $this->actingAs($owner)
        ->get('http://tenant.localhost/tenant/rbac/members')
        ->assertStatus(409)
        ->assertJsonPath('code', 'PROFILE_PROJECTION_STALE');

    phase4CreateProjection($tenantId, $owner->id, CarbonImmutable::now()->addHour());

    $this->actingAs($owner)
        ->get('http://tenant.localhost/tenant/rbac/members')
        ->assertOk();
});

test('tenant event processor handles replay idempotently and ignores duplicates', function () {
    $tenantId = (string) Str::uuid();
    phase4CreateTenant('tenant-events.localhost', $tenantId);

    $user = User::factory()->create();

    $envelope = TenantEventEnvelope::fromValidated([
        'event_name' => 'ProfileProjectionUpserted',
        'event_id' => 'evt-profile-1',
        'occurred_at' => CarbonImmutable::now()->toIso8601String(),
        'tenant_id' => $tenantId,
        'subject_id' => $user->id,
        'schema_version' => 'v1',
        'signature' => 'sig',
        'retry_count' => 0,
        'payload' => [
            'display_name' => 'Projected User',
            'mfa_status' => true,
            'profile_version' => 7,
        ],
    ]);

    $processor = app(TenantEventProcessor::class);

    expect($processor->process($envelope))->toBe('processed');
    expect($processor->process($envelope))->toBe('duplicate');

    expect(TenantEventDeduplication::query()->whereKey('evt-profile-1')->count())->toBe(1);

    $projection = TenantUserProfileProjection::query()
        ->where('tenant_id', $tenantId)
        ->where('central_user_id', $user->id)
        ->first();

    expect($projection)->not->toBeNull();
    expect($projection?->display_name)->toBe('Projected User');
});

test('invites use soft throttling but always return 202 accepted', function () {
    config([
        'phase4.invites.soft_limit_per_minute' => 1,
    ]);

    $tenant = phase4CreateTenant();
    $tenantId = (string) $tenant->id;

    $owner = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    phase4RegisterRole($tenantId, 'owner');
    app(AssignRolesToMember::class)->execute($owner, ['owner'], $tenantId);

    phase4GrantEntitlement($tenantId, 'tenant.invites');
    phase4CreateProjection($tenantId, $owner->id, CarbonImmutable::now()->addHour());

    $first = $this->actingAs($owner)
        ->postJson('http://tenant.localhost/tenant/invites', [
            'email' => 'invitee1@example.test',
        ]);

    $second = $this->actingAs($owner)
        ->postJson('http://tenant.localhost/tenant/invites', [
            'email' => 'invitee2@example.test',
        ]);

    $first->assertStatus(202)->assertJsonPath('status', 'accepted');
    $second->assertStatus(202)->assertJsonPath('status', 'accepted');

    expect(InviteToken::query()->where('tenant_id', $tenantId)->count())->toBe(2);
    expect(ActivityLog::query()->where('tenant_id', $tenantId)->where('event', 'invite.soft_throttled')->count())
        ->toBeGreaterThanOrEqual(1);
});

test('rbac mutations require step-up auth and succeed with fresh confirmation', function () {
    $tenant = phase4CreateTenant();
    $tenantId = (string) $tenant->id;

    $owner = User::factory()->create([
        'email_verified_at' => now(),
    ]);
    $member = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    phase4RegisterRole($tenantId, 'owner');
    phase4RegisterRole($tenantId, 'manager');
    app(AssignRolesToMember::class)->execute($owner, ['owner'], $tenantId);

    TenantUser::query()->create([
        'tenant_id' => $tenantId,
        'user_id' => $member->id,
        'is_active' => true,
        'is_banned' => false,
        'membership_status' => 'active',
        'membership_revoked_at' => null,
    ]);

    phase4GrantEntitlement($tenantId, 'tenant.rbac');
    phase4CreateProjection($tenantId, $owner->id, CarbonImmutable::now()->addHour());

    $payload = [
        'roles' => ['manager'],
        'expected_acl_version' => 0,
    ];

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => time() - 7200])
        ->postJson("http://tenant.localhost/tenant/rbac/members/{$member->id}/roles", $payload)
        ->assertStatus(423)
        ->assertJsonPath('code', 'STEP_UP_REQUIRED');

    $okResponse = $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("http://tenant.localhost/tenant/rbac/members/{$member->id}/roles", $payload)
        ->assertOk()
        ->assertJsonPath('status', 'ok');

    expect($okResponse->json('acl_version'))->toBe(1);

    $registrar = app(PermissionRegistrar::class);
    $previousTeamId = $registrar->getPermissionsTeamId();

    try {
        $registrar->setPermissionsTeamId($tenantId);
        $member->unsetRelation('roles');
        $member->unsetRelation('permissions');

        expect($member->hasRole('manager'))->toBeTrue();
    } finally {
        $registrar->setPermissionsTeamId($previousTeamId);
        $registrar->clearPermissionsCollection();
        $registrar->initializeCache();
    }
});

test('audit redaction rotates hmac kid and never stores raw sensitive values', function () {
    config([
        'phase4.hmac.keys' => [
            'kid-old' => 'old-secret',
            'kid-new' => 'new-secret',
        ],
        'phase4.hmac.active_kid' => 'kid-old',
    ]);

    $logger = app(AuditLogger::class);
    $tenantId = (string) phase4CreateTenant('tenant-audit-hmac.localhost')->id;

    $first = $logger->log('audit.test.old', $tenantId, null, [
        'email' => 'user@example.test',
        'password' => 'super-secret',
        'token' => 'token-value',
    ]);

    config(['phase4.hmac.active_kid' => 'kid-new']);

    $second = $logger->log('audit.test.new', $tenantId, null, [
        'email' => 'user@example.test',
        'password' => 'super-secret',
        'token' => 'token-value',
    ]);

    expect($first->hmac_kid)->toBe('kid-old');
    expect($second->hmac_kid)->toBe('kid-new');

    expect(data_get($first->properties, 'password'))->toBe('[REDACTED]');
    expect(data_get($first->properties, 'token'))->toBe('[REDACTED]');
    expect(data_get($second->properties, 'password'))->toBe('[REDACTED]');

    $firstEmail = data_get($first->properties, 'email');
    $secondEmail = data_get($second->properties, 'email');

    expect($firstEmail)->toBeArray();
    expect($secondEmail)->toBeArray();
    expect(data_get($firstEmail, 'hmac'))->not->toBe('user@example.test');
    expect(data_get($secondEmail, 'hmac'))->not->toBe('user@example.test');
    expect(data_get($firstEmail, 'hmac'))->not->toBe(data_get($secondEmail, 'hmac'));
});

test('billing webhook enforces signature and detects divergence for same event id', function () {
    config([
        'billing.providers.local.webhook_secret' => 'test-webhook-secret',
    ]);

    $tenantId = (string) Str::uuid();
    phase4CreateTenant('tenant-billing-webhook.localhost', $tenantId);

    $service = app(BillingService::class);

    $invalidPayload = [
        'event_id' => 'evt-billing-1',
        'tenant_id' => $tenantId,
        'status' => 'active',
        'provider_object_version' => 1,
        'entitlements' => ['tenant.billing' => true],
    ];

    expect(fn () => $service->handleWebhook(
        provider: 'local',
        rawPayload: (string) json_encode($invalidPayload),
        providedSignature: 'invalid-signature',
        payload: $invalidPayload,
    ))->toThrow(AuthorizationException::class);

    $payloadA = [
        'event_id' => 'evt-billing-divergence',
        'tenant_id' => $tenantId,
        'status' => 'active',
        'provider_object_version' => 5,
        'entitlements' => ['tenant.billing' => true],
    ];

    $rawA = (string) json_encode($payloadA, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $sigA = hash_hmac('sha256', $rawA, 'test-webhook-secret');

    expect($service->handleWebhook('local', $rawA, $sigA, $payloadA))->toBe('processed');

    $payloadB = [
        'event_id' => 'evt-billing-divergence',
        'tenant_id' => $tenantId,
        'status' => 'past_due',
        'provider_object_version' => 5,
        'entitlements' => ['tenant.billing' => false],
    ];

    $rawB = (string) json_encode($payloadB, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $sigB = hash_hmac('sha256', $rawB, 'test-webhook-secret');

    expect($service->handleWebhook('local', $rawB, $sigB, $payloadB))->toBe('divergence');

    expect(BillingIncident::query()->where('tenant_id', $tenantId)->count())->toBe(1);
});

test('billing reconciliation restores subscription and entitlement state after missed webhook', function () {
    $tenantId = (string) Str::uuid();
    phase4CreateTenant('tenant-billing-reconcile.localhost', $tenantId);

    config([
        "billing.providers.local.reconciliation_snapshots.{$tenantId}" => [
            'status' => 'active',
            'provider_object_version' => 3,
            'provider_customer_id' => 'cus_local_1',
            'provider_subscription_id' => 'sub_local_1',
            'entitlements' => [
                'tenant.billing' => true,
                'tenant.audit' => true,
            ],
        ],
    ]);

    $service = app(BillingService::class);

    expect($service->reconcileTenant('local', $tenantId))->toBeTrue();

    $subscription = TenantSubscription::query()
        ->where('tenant_id', $tenantId)
        ->where('provider', 'local')
        ->first();

    expect($subscription)->not->toBeNull();
    expect($subscription?->status)->toBe('active');
    expect((int) $subscription?->provider_object_version)->toBe(3);

    $billingEntitlement = TenantEntitlement::query()
        ->where('tenant_id', $tenantId)
        ->where('feature', 'tenant.billing')
        ->first();

    expect($billingEntitlement)->not->toBeNull();
    expect($billingEntitlement?->granted)->toBeTrue();
});

test('forensic audit repository enforces sargable date range filtering', function () {
    $tenantId = (string) Str::uuid();
    phase4CreateTenant('tenant-forensic.localhost', $tenantId);

    $repository = app(ForensicAuditRepository::class);

    ActivityLog::query()->create([
        'tenant_id' => $tenantId,
        'event' => 'audit.target',
        'request_id' => 'req-in',
        'actor_id' => null,
        'hmac_kid' => 'kid-1',
        'properties' => ['k' => 'v'],
        'created_at' => CarbonImmutable::parse('2026-02-01 10:00:00'),
    ]);

    ActivityLog::query()->create([
        'tenant_id' => $tenantId,
        'event' => 'audit.target',
        'request_id' => 'req-out',
        'actor_id' => null,
        'hmac_kid' => 'kid-1',
        'properties' => ['k' => 'v'],
        'created_at' => CarbonImmutable::parse('2026-02-01 15:00:00'),
    ]);

    $rows = $repository->exportRows(
        tenantId: $tenantId,
        from: CarbonImmutable::parse('2026-02-01 09:00:00'),
        to: CarbonImmutable::parse('2026-02-01 12:00:00'),
        filters: ['event' => 'audit.target'],
    );

    expect($rows)->toHaveCount(1);
    expect(data_get($rows[0], 'request_id'))->toBe('req-in');
});

test('reconcile billing job fails closed when entitlement is missing', function () {
    config([
        'phase4.entitlements.default_granted' => false,
    ]);

    $tenantId = (string) Str::uuid();
    $job = new ReconcileTenantBillingJob('local', $tenantId, null);

    expect(fn () => $job->handle(
        app(BillingService::class),
        app(AuditLogger::class),
        app(EntitlementService::class),
    ))->toThrow(AuthorizationException::class);
});
