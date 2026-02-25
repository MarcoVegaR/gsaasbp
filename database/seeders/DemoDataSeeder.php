<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Rbac\AssignRolesToMember;
use App\Models\ActivityLog;
use App\Models\BillingEventProcessed;
use App\Models\BillingIncident;
use App\Models\InviteToken;
use App\Models\PlatformForensicExport;
use App\Models\PlatformHardDeleteApproval;
use App\Models\PlatformImpersonationSession;
use App\Models\PlatformStepUpCapability;
use App\Models\Tenant;
use App\Models\TenantAclVersion;
use App\Models\TenantEntitlement;
use App\Models\TenantEventDeduplication;
use App\Models\TenantEventDlq;
use App\Models\TenantNote;
use App\Models\TenantNotification;
use App\Models\TenantNotificationOutbox;
use App\Models\TenantNotificationStreamSequence;
use App\Models\TenantSubscription;
use App\Models\TenantUser;
use App\Models\TenantUserProfileProjection;
use App\Models\TenantUserRealtimeEpoch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class DemoDataSeeder extends Seeder
{
    private const TENANT_PRIMARY_ID = '00000000-0000-0000-0000-000000000001';

    private const TENANT_SECONDARY_ID = '00000000-0000-0000-0000-000000000002';

    public function run(): void
    {
        $now = CarbonImmutable::now();

        $superadmin = $this->upsertUser('Platform Superadmin', 'superadmin@example.test');
        $platformApprover = $this->upsertUser('Platform Approver', 'approver@example.test');
        $platformExecutor = $this->upsertUser('Platform Executor', 'executor@example.test');
        $tenantOwner = $this->upsertUser('Tenant Owner', 'owner@tenant.localhost');
        $tenantMember = $this->upsertUser('Tenant Member', 'member@tenant.localhost');
        $secondaryTenantSupport = $this->upsertUser('Tenant Support', 'support@acme.localhost');

        $primaryTenant = $this->upsertTenant(
            id: self::TENANT_PRIMARY_ID,
            domain: (string) env('PLAYWRIGHT_TENANT_DOMAIN', 'tenant.localhost'),
            displayName: 'Tenant Sandbox',
            status: 'active',
            now: $now,
        );

        $secondaryTenant = $this->upsertTenant(
            id: self::TENANT_SECONDARY_ID,
            domain: 'acme.localhost',
            displayName: 'Acme Corporation',
            status: 'active',
            now: $now,
        );

        $this->seedAuthorizationCatalog(
            primaryTenantId: (string) $primaryTenant->id,
            secondaryTenantId: (string) $secondaryTenant->id,
            tenantOwner: $tenantOwner,
            tenantMember: $tenantMember,
            secondaryTenantSupport: $secondaryTenantSupport,
        );

        $this->seedTenantMembershipsAndProfiles(
            primaryTenantId: (string) $primaryTenant->id,
            secondaryTenantId: (string) $secondaryTenant->id,
            tenantOwner: $tenantOwner,
            tenantMember: $tenantMember,
            secondaryTenantSupport: $secondaryTenantSupport,
            platformApprover: $platformApprover,
            now: $now,
        );

        $this->seedTenantBusinessTables(
            primaryTenantId: (string) $primaryTenant->id,
            secondaryTenantId: (string) $secondaryTenant->id,
            tenantOwner: $tenantOwner,
            tenantMember: $tenantMember,
            platformApprover: $platformApprover,
            now: $now,
        );

        $this->seedPlatformAdminTables(
            primaryTenantId: (string) $primaryTenant->id,
            superadmin: $superadmin,
            platformApprover: $platformApprover,
            platformExecutor: $platformExecutor,
            tenantOwner: $tenantOwner,
            now: $now,
        );
    }

    private function upsertUser(string $name, string $email): User
    {
        return User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'email_verified_at' => now(),
                'password' => Hash::make('password'),
            ],
        );
    }

    private function upsertTenant(
        string $id,
        string $domain,
        string $displayName,
        string $status,
        CarbonImmutable $now,
    ): Tenant {
        $tenant = Tenant::query()->firstOrCreate(
            ['id' => $id],
            [
                'db_connection' => null,
                'status' => $status,
                'status_changed_at' => $now,
                'data' => ['name' => $displayName],
            ],
        );

        $tenant->forceFill([
            'status' => $status,
            'status_changed_at' => $now,
            'data' => ['name' => $displayName],
        ])->save();

        $tenant->domains()->firstOrCreate([
            'domain' => $domain,
        ]);

        return $tenant;
    }

    private function seedAuthorizationCatalog(
        string $primaryTenantId,
        string $secondaryTenantId,
        User $tenantOwner,
        User $tenantMember,
        User $secondaryTenantSupport,
    ): void {
        $platformPermissions = [
            'platform.admin.access',
            'platform.step-up.issue',
            'platform.tenants.view',
            'platform.tenants.manage-status',
            'platform.tenants.hard-delete.approve',
            'platform.tenants.hard-delete.execute',
            'platform.tenants.hard-delete',
            'platform.telemetry.view',
            'platform.audit.view',
            'platform.audit.export',
            'platform.billing.view',
            'platform.billing.reconcile',
            'platform.impersonation.issue',
            'platform.impersonation.terminate',
        ];

        $tenantPermissions = [
            'tenant.dashboard.view',
            'tenant.settings.manage',
            'tenant.rbac.members.view',
            'tenant.billing.view',
            'tenant.audit.view',
            'tenant.notifications.read',
            'tenant.sample-entity.view',
            'tenant.sample-entity.create',
            'tenant.sample-entity.update',
            'tenant.sample-entity.delete',
        ];

        foreach ($platformPermissions as $permission) {
            Permission::findOrCreate($permission, 'platform');
        }

        foreach ($tenantPermissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $registrar = app(PermissionRegistrar::class);
        $assignRoles = app(AssignRolesToMember::class);
        $previousTeamId = $registrar->getPermissionsTeamId();

        try {
            $registrar->clearPermissionsCollection();

            $registrar->setPermissionsTeamId($primaryTenantId);
            $registrar->initializeCache();

            $ownerRole = Role::query()->firstOrCreate([
                'name' => 'owner',
                'guard_name' => 'web',
            ]);

            $memberRole = Role::query()->firstOrCreate([
                'name' => 'member',
                'guard_name' => 'web',
            ]);

            $ownerRole->syncPermissions($tenantPermissions);
            $memberRole->syncPermissions([
                'tenant.dashboard.view',
                'tenant.notifications.read',
            ]);

            $assignRoles->execute($tenantOwner, ['owner'], $primaryTenantId, 'web');
            $assignRoles->execute($tenantMember, ['member'], $primaryTenantId, 'web');

            $tenantMember->unsetRelation('permissions');
            $tenantMember->givePermissionTo('tenant.settings.manage');

            $registrar->setPermissionsTeamId($secondaryTenantId);
            $registrar->initializeCache();

            $supportRole = Role::query()->firstOrCreate([
                'name' => 'support',
                'guard_name' => 'web',
            ]);

            $supportRole->syncPermissions([
                'tenant.dashboard.view',
                'tenant.audit.view',
            ]);

            $assignRoles->execute($secondaryTenantSupport, ['support'], $secondaryTenantId, 'web');
        } finally {
            $registrar->clearPermissionsCollection();
            $registrar->setPermissionsTeamId($previousTeamId);
            $registrar->initializeCache();

            $tenantOwner->unsetRelation('roles');
            $tenantOwner->unsetRelation('permissions');
            $tenantMember->unsetRelation('roles');
            $tenantMember->unsetRelation('permissions');
            $secondaryTenantSupport->unsetRelation('roles');
            $secondaryTenantSupport->unsetRelation('permissions');
        }
    }

    private function seedTenantMembershipsAndProfiles(
        string $primaryTenantId,
        string $secondaryTenantId,
        User $tenantOwner,
        User $tenantMember,
        User $secondaryTenantSupport,
        User $platformApprover,
        CarbonImmutable $now,
    ): void {
        TenantUser::query()->updateOrCreate(
            ['tenant_id' => $primaryTenantId, 'user_id' => $tenantOwner->id],
            [
                'is_active' => true,
                'is_banned' => false,
                'membership_status' => 'active',
                'membership_revoked_at' => null,
                'last_sso_at' => $now->subMinutes(15),
            ],
        );

        TenantUser::query()->updateOrCreate(
            ['tenant_id' => $primaryTenantId, 'user_id' => $tenantMember->id],
            [
                'is_active' => true,
                'is_banned' => false,
                'membership_status' => 'active',
                'membership_revoked_at' => null,
                'last_sso_at' => $now->subMinutes(40),
            ],
        );

        TenantUser::query()->updateOrCreate(
            ['tenant_id' => $secondaryTenantId, 'user_id' => $secondaryTenantSupport->id],
            [
                'is_active' => true,
                'is_banned' => false,
                'membership_status' => 'active',
                'membership_revoked_at' => null,
                'last_sso_at' => $now->subMinutes(5),
            ],
        );

        TenantUserProfileProjection::query()->updateOrCreate(
            ['tenant_id' => $primaryTenantId, 'central_user_id' => $tenantOwner->id],
            [
                'display_name' => 'Tenant Owner',
                'avatar_url' => null,
                'mfa_status' => true,
                'profile_version' => 3,
                'last_synced_at' => $now->subMinutes(3),
                'stale_after' => $now->addHours(2),
            ],
        );

        TenantUserProfileProjection::query()->updateOrCreate(
            ['tenant_id' => $primaryTenantId, 'central_user_id' => $tenantMember->id],
            [
                'display_name' => 'Tenant Member',
                'avatar_url' => null,
                'mfa_status' => false,
                'profile_version' => 2,
                'last_synced_at' => $now->subMinutes(8),
                'stale_after' => $now->addHour(),
            ],
        );

        TenantUserProfileProjection::query()->updateOrCreate(
            ['tenant_id' => $secondaryTenantId, 'central_user_id' => $secondaryTenantSupport->id],
            [
                'display_name' => 'Acme Support',
                'avatar_url' => null,
                'mfa_status' => true,
                'profile_version' => 1,
                'last_synced_at' => $now->subMinutes(12),
                'stale_after' => $now->addHours(3),
            ],
        );

        TenantAclVersion::query()->updateOrCreate(
            ['tenant_id' => $primaryTenantId],
            [
                'acl_version' => 5,
                'updated_by' => $platformApprover->id,
            ],
        );

        TenantAclVersion::query()->updateOrCreate(
            ['tenant_id' => $secondaryTenantId],
            [
                'acl_version' => 2,
                'updated_by' => $platformApprover->id,
            ],
        );

        TenantUserRealtimeEpoch::query()->updateOrCreate(
            ['tenant_id' => $primaryTenantId, 'user_id' => $tenantOwner->id],
            [
                'authz_epoch' => 4,
                'last_bumped_at' => $now->subMinutes(11),
            ],
        );

        TenantUserRealtimeEpoch::query()->updateOrCreate(
            ['tenant_id' => $primaryTenantId, 'user_id' => $tenantMember->id],
            [
                'authz_epoch' => 1,
                'last_bumped_at' => $now->subMinutes(26),
            ],
        );

        TenantUserRealtimeEpoch::query()->updateOrCreate(
            ['tenant_id' => $secondaryTenantId, 'user_id' => $secondaryTenantSupport->id],
            [
                'authz_epoch' => 2,
                'last_bumped_at' => $now->subMinutes(4),
            ],
        );
    }

    private function seedTenantBusinessTables(
        string $primaryTenantId,
        string $secondaryTenantId,
        User $tenantOwner,
        User $tenantMember,
        User $platformApprover,
        CarbonImmutable $now,
    ): void {
        TenantNote::query()->updateOrCreate(
            ['tenant_id' => $primaryTenantId, 'title' => 'Primer checklist'],
            ['body' => 'Validar login tenant, settings, billing y auditoria.'],
        );

        TenantNote::query()->updateOrCreate(
            ['tenant_id' => $secondaryTenantId, 'title' => 'Seguimiento soporte'],
            ['body' => 'Revisar flujos de incidencias y notificaciones en tiempo real.'],
        );

        TenantEventDeduplication::query()->updateOrCreate(
            ['event_id' => 'evt-dedup-primary-1'],
            [
                'tenant_id' => $primaryTenantId,
                'event_name' => 'billing.subscription.updated',
                'schema_version' => 'v1',
                'retry_count' => 0,
                'processed_at' => $now->subMinutes(20),
            ],
        );

        TenantEventDeduplication::query()->updateOrCreate(
            ['event_id' => 'evt-dedup-secondary-1'],
            [
                'tenant_id' => $secondaryTenantId,
                'event_name' => 'tenant.user.invited',
                'schema_version' => 'v1',
                'retry_count' => 1,
                'processed_at' => $now->subMinutes(33),
            ],
        );

        TenantEventDlq::query()->updateOrCreate(
            ['tenant_id' => $primaryTenantId, 'event_id' => 'evt-dlq-primary-1'],
            [
                'event_name' => 'tenant.billing.webhook.failed',
                'schema_version' => 'v1',
                'retry_count' => 2,
                'payload' => [
                    'provider' => 'local',
                    'reason' => 'signature_mismatch',
                ],
                'failure_reason' => 'Demo DLQ event for local troubleshooting.',
            ],
        );

        InviteToken::query()->updateOrCreate(
            ['jti' => 'invite-primary-member-1'],
            [
                'tenant_id' => $primaryTenantId,
                'sub' => $tenantMember->email,
                'invited_by' => $tenantOwner->id,
                'central_user_id' => $tenantMember->id,
                'retry_count' => 0,
                'expires_at' => $now->addDays(7),
                'consumed_at' => null,
            ],
        );

        ActivityLog::query()->updateOrCreate(
            ['tenant_id' => $primaryTenantId, 'request_id' => 'req-seed-primary-1'],
            [
                'event' => 'tenant.seed.demo.created',
                'actor_id' => $platformApprover->id,
                'hmac_kid' => 'kid-seed-primary',
                'properties' => [
                    'source' => 'database-seeder',
                    'surface' => 'tenant',
                ],
                'created_at' => $now->subMinutes(21),
            ],
        );

        ActivityLog::query()->updateOrCreate(
            ['tenant_id' => $secondaryTenantId, 'request_id' => 'req-seed-secondary-1'],
            [
                'event' => 'tenant.seed.demo.updated',
                'actor_id' => $platformApprover->id,
                'hmac_kid' => 'kid-seed-secondary',
                'properties' => [
                    'source' => 'database-seeder',
                    'surface' => 'tenant',
                ],
                'created_at' => $now->subMinutes(18),
            ],
        );

        $provider = (string) config('billing.default_provider', 'local');

        TenantSubscription::query()->updateOrCreate(
            ['tenant_id' => $primaryTenantId, 'provider' => $provider],
            [
                'provider_customer_id' => 'cust_demo_primary',
                'provider_subscription_id' => 'sub_demo_primary',
                'status' => 'active',
                'provider_object_version' => 5,
                'subscription_revision' => 2,
                'current_period_ends_at' => $now->addMonth(),
                'metadata' => ['plan' => 'pro'],
            ],
        );

        TenantSubscription::query()->updateOrCreate(
            ['tenant_id' => $secondaryTenantId, 'provider' => $provider],
            [
                'provider_customer_id' => 'cust_demo_secondary',
                'provider_subscription_id' => 'sub_demo_secondary',
                'status' => 'trialing',
                'provider_object_version' => 1,
                'subscription_revision' => 1,
                'current_period_ends_at' => $now->addDays(14),
                'metadata' => ['plan' => 'starter'],
            ],
        );

        TenantEntitlement::query()->updateOrCreate(
            ['tenant_id' => $primaryTenantId, 'feature' => 'tenant.rbac'],
            [
                'granted' => true,
                'source' => 'billing',
                'updated_by_event_id' => 'evt-billing-primary-1',
                'expires_at' => null,
            ],
        );

        TenantEntitlement::query()->updateOrCreate(
            ['tenant_id' => $primaryTenantId, 'feature' => 'tenant.billing'],
            [
                'granted' => true,
                'source' => 'billing',
                'updated_by_event_id' => 'evt-billing-primary-1',
                'expires_at' => null,
            ],
        );

        TenantEntitlement::query()->updateOrCreate(
            ['tenant_id' => $secondaryTenantId, 'feature' => 'tenant.audit'],
            [
                'granted' => true,
                'source' => 'manual',
                'updated_by_event_id' => 'evt-billing-secondary-1',
                'expires_at' => $now->addDays(30),
            ],
        );

        BillingEventProcessed::query()->updateOrCreate(
            ['event_id' => 'evt-billing-primary-1'],
            [
                'tenant_id' => $primaryTenantId,
                'provider' => $provider,
                'outcome_hash' => hash('sha256', 'primary-outcome-hash'),
                'provider_object_version' => 5,
                'processed_at' => $now->subMinutes(25),
            ],
        );

        BillingEventProcessed::query()->updateOrCreate(
            ['event_id' => 'evt-billing-secondary-1'],
            [
                'tenant_id' => $secondaryTenantId,
                'provider' => $provider,
                'outcome_hash' => hash('sha256', 'secondary-outcome-hash'),
                'provider_object_version' => 2,
                'processed_at' => $now->subMinutes(47),
            ],
        );

        BillingIncident::query()->updateOrCreate(
            ['tenant_id' => $secondaryTenantId, 'event_id' => 'evt-billing-secondary-1'],
            [
                'reason' => 'outcome_hash_mismatch',
                'expected_outcome_hash' => hash('sha256', 'secondary-outcome-hash-expected'),
                'actual_outcome_hash' => hash('sha256', 'secondary-outcome-hash'),
            ],
        );

        TenantNotificationStreamSequence::query()->updateOrCreate(
            [
                'tenant_id' => $primaryTenantId,
                'notifiable_id' => $tenantOwner->id,
                'stream_key' => 'tenant.alerts',
            ],
            ['last_sequence' => 1],
        );

        TenantNotificationOutbox::query()->updateOrCreate(
            ['id' => 'outbox-primary-owner-1'],
            [
                'event_id' => 'evt-notification-primary-owner-1',
                'tenant_id' => $primaryTenantId,
                'notifiable_id' => $tenantOwner->id,
                'event_type' => 'tenant.notification.demo',
                'version' => 1,
                'payload' => [
                    'title' => 'Demo outbox notification',
                    'body' => 'Record inserted by DemoDataSeeder.',
                ],
                'stream_key' => 'tenant.alerts',
                'sequence' => 1,
                'occurred_at' => $now->subMinutes(2),
                'processed_at' => $now->subMinute(),
                'retry_count' => 0,
            ],
        );

        TenantNotification::query()->updateOrCreate(
            ['id' => 'notification-primary-owner-1'],
            [
                'tenant_id' => $primaryTenantId,
                'notifiable_id' => $tenantOwner->id,
                'event_id' => 'evt-notification-primary-owner-1',
                'event_type' => 'tenant.notification.demo',
                'version' => 1,
                'payload' => [
                    'title' => 'Demo inbox notification',
                    'body' => 'Use this to validate tenant realtime listing.',
                ],
                'stream_key' => 'tenant.alerts',
                'sequence' => 1,
                'is_read' => false,
                'read_at' => null,
                'occurred_at' => $now->subMinutes(2),
            ],
        );
    }

    private function seedPlatformAdminTables(
        string $primaryTenantId,
        User $superadmin,
        User $platformApprover,
        User $platformExecutor,
        User $tenantOwner,
        CarbonImmutable $now,
    ): void {
        PlatformStepUpCapability::query()->updateOrCreate(
            ['capability_id' => 'capability-demo-hard-delete-1'],
            [
                'platform_user_id' => $superadmin->id,
                'session_id' => 'session-demo-platform',
                'device_fingerprint' => 'fingerprint-demo-platform',
                'scope' => 'platform.tenants.hard-delete',
                'ip_address' => '127.0.0.1',
                'expires_at' => $now->addMinutes(20),
                'consumed_at' => null,
            ],
        );

        PlatformHardDeleteApproval::query()->updateOrCreate(
            ['approval_id' => 'approval-demo-tenant-primary-1'],
            [
                'tenant_id' => $primaryTenantId,
                'requested_by_platform_user_id' => $superadmin->id,
                'approved_by_platform_user_id' => $platformApprover->id,
                'executor_platform_user_id' => $platformExecutor->id,
                'reason_code' => 'demo_cleanup',
                'signature' => hash_hmac('sha256', 'approval-demo-tenant-primary-1', (string) config('phase7.hard_delete.signature_key')),
                'expires_at' => $now->addMinutes(15),
                'consumed_at' => null,
            ],
        );

        $downloadTokenPlain = 'demo-download-token-primary';

        PlatformForensicExport::query()->updateOrCreate(
            ['export_id' => 'forensic-export-demo-primary-1'],
            [
                'platform_user_id' => $superadmin->id,
                'reason_code' => 'demo_forensic_review',
                'filters' => [
                    'tenant_id' => $primaryTenantId,
                    'from' => $now->subHours(2)->toIso8601String(),
                    'to' => $now->toIso8601String(),
                ],
                'storage_disk' => (string) config('phase7.forensics.export_disk', 'local'),
                'storage_path' => 'phase7/demo/forensic-export-demo-primary-1.json',
                'row_count' => 2,
                'download_token_hash' => hash('sha256', $downloadTokenPlain),
                'download_token_expires_at' => $now->addMinutes(10),
                'downloaded_at' => null,
            ],
        );

        PlatformImpersonationSession::query()->updateOrCreate(
            ['jti' => 'impersonation-demo-primary-1'],
            [
                'platform_user_id' => $superadmin->id,
                'target_tenant_id' => $primaryTenantId,
                'target_user_id' => $tenantOwner->id,
                'reason_code' => 'demo_support_ticket',
                'fingerprint' => hash('sha256', 'impersonation-demo-primary-fingerprint'),
                'issued_at' => $now->subMinutes(4),
                'expires_at' => $now->addMinutes(4),
                'consumed_at' => $now->subMinutes(3),
                'revoked_at' => null,
            ],
        );
    }
}
