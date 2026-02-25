<?php

declare(strict_types=1);

use App\Events\Phase6\TenantNotificationBroadcasted;
use App\Jobs\Phase6\ProcessTenantNotificationOutboxJob;
use App\Models\Tenant;
use App\Models\TenantNotification;
use App\Models\TenantNotificationOutbox;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\Phase5\TenantStatusService;
use App\Support\Phase6\BroadcastChannelRegistry;
use App\Support\Phase6\ChannelNameBuilder;
use App\Support\Phase6\NotificationOutboxService;
use App\Support\Phase6\RealtimeAuthorizationEpochService;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'tenancy.central_domains' => ['localhost', '127.0.0.1'],
        'broadcasting.default' => 'phase6',
        'broadcasting.connections.phase6.driver' => 'phase6',
        'phase5.tenant_status.cache_store' => 'array',
        'phase5.tenant_status.cache_ttl_seconds' => 15,
        'phase6.auth.rate_limit_per_minute' => 120,
        'phase6.allowed_origins' => [
            'http://tenant.localhost',
            'http://alpha.localhost',
            'http://beta.localhost',
            'http://epoch.localhost',
            'http://blocked.localhost',
            'http://notify.localhost',
            'http://outbox.localhost',
        ],
    ]);

    /** @var BroadcastManager $broadcast */
    $broadcast = app(BroadcastManager::class);
    $broadcast->setDefaultDriver('phase6');
    $broadcast->purge('phase6');

    app(BroadcastChannelRegistry::class)->register();
});

function phase6CreateTenant(string $domain, string $status = 'active'): Tenant
{
    $tenant = Tenant::create([
        'id' => (string) Str::uuid(),
        'status' => $status,
    ]);

    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function phase6AttachMembership(string $tenantId, int $userId): void
{
    TenantUser::query()->updateOrCreate(
        [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
        ],
        [
            'is_active' => true,
            'is_banned' => false,
            'membership_status' => 'active',
            'membership_revoked_at' => null,
        ],
    );
}

function phase6CreateNotification(string $tenantId, int $notifiableId, int $sequence, string $eventId): TenantNotification
{
    return TenantNotification::query()->create([
        'id' => (string) Str::uuid(),
        'tenant_id' => $tenantId,
        'notifiable_id' => $notifiableId,
        'event_id' => $eventId,
        'event_type' => 'tenant.notification.created',
        'version' => 1,
        'payload' => ['source' => 'test'],
        'stream_key' => sprintf('notification_stream:%s:%d', $tenantId, $notifiableId),
        'sequence' => $sequence,
        'is_read' => false,
        'read_at' => null,
        'occurred_at' => now(),
    ]);
}

function phase6BroadcastAuthPayload(string $channelName): array
{
    return [
        'channel_name' => $channelName,
        'socket_id' => '12345.67890',
    ];
}

test('broadcast auth denies requests with non allowlisted origin', function () {
    $tenant = phase6CreateTenant('tenant.localhost');
    $tenantId = (string) $tenant->id;

    $user = User::factory()->create(['email_verified_at' => now()]);
    phase6AttachMembership($tenantId, $user->id);

    $epoch = app(RealtimeAuthorizationEpochService::class)->currentEpoch($tenantId, $user->id);
    $channel = app(ChannelNameBuilder::class)->privateTenantUserEpoch($tenantId, $user->id, $epoch);

    $this->actingAs($user)
        ->withHeaders(['Origin' => 'http://evil.localhost'])
        ->post('http://tenant.localhost/broadcasting/auth', phase6BroadcastAuthPayload($channel))
        ->assertStatus(403)
        ->assertExactJson(['message' => 'Forbidden.']);
});

test('broadcast auth allows tenant channel and blocks cross tenant channel attempts', function () {
    $tenantA = phase6CreateTenant('alpha.localhost');
    $tenantB = phase6CreateTenant('beta.localhost');

    $tenantAId = (string) $tenantA->id;
    $tenantBId = (string) $tenantB->id;

    $user = User::factory()->create(['email_verified_at' => now()]);
    phase6AttachMembership($tenantAId, $user->id);

    $epochs = app(RealtimeAuthorizationEpochService::class);
    $channels = app(ChannelNameBuilder::class);

    $tenantAChannel = $channels->privateTenantUserEpoch(
        $tenantAId,
        $user->id,
        $epochs->currentEpoch($tenantAId, $user->id),
    );

    $tenantBChannel = $channels->privateTenantUserEpoch(
        $tenantBId,
        $user->id,
        $epochs->currentEpoch($tenantBId, $user->id),
    );

    $this->actingAs($user)
        ->withHeaders(['Origin' => 'http://alpha.localhost'])
        ->post('http://alpha.localhost/broadcasting/auth', phase6BroadcastAuthPayload($tenantAChannel))
        ->assertOk();

    $this->actingAs($user)
        ->withHeaders(['Origin' => 'http://alpha.localhost'])
        ->post('http://alpha.localhost/broadcasting/auth', phase6BroadcastAuthPayload($tenantBChannel))
        ->assertStatus(403);
});

test('broadcast auth invalidates stale authz epoch channels after revocation bump', function () {
    $tenant = phase6CreateTenant('epoch.localhost');
    $tenantId = (string) $tenant->id;

    $user = User::factory()->create(['email_verified_at' => now()]);
    phase6AttachMembership($tenantId, $user->id);

    $epochs = app(RealtimeAuthorizationEpochService::class);
    $channels = app(ChannelNameBuilder::class);

    $oldEpoch = $epochs->currentEpoch($tenantId, $user->id);
    $oldChannel = $channels->privateTenantUserEpoch($tenantId, $user->id, $oldEpoch);

    $this->actingAs($user)
        ->withHeaders(['Origin' => 'http://epoch.localhost'])
        ->post('http://epoch.localhost/broadcasting/auth', phase6BroadcastAuthPayload($oldChannel))
        ->assertOk();

    $newEpoch = $epochs->bumpEpoch($tenantId, $user->id);
    $newChannel = $channels->privateTenantUserEpoch($tenantId, $user->id, $newEpoch);

    $this->actingAs($user)
        ->withHeaders(['Origin' => 'http://epoch.localhost'])
        ->post('http://epoch.localhost/broadcasting/auth', phase6BroadcastAuthPayload($oldChannel))
        ->assertStatus(403);

    $this->actingAs($user)
        ->withHeaders(['Origin' => 'http://epoch.localhost'])
        ->post('http://epoch.localhost/broadcasting/auth', phase6BroadcastAuthPayload($newChannel))
        ->assertOk();
});

test('broadcast auth endpoint is blocked when tenant status is suspended', function () {
    $tenant = phase6CreateTenant('blocked.localhost');
    $tenantId = (string) $tenant->id;

    $user = User::factory()->create(['email_verified_at' => now()]);
    phase6AttachMembership($tenantId, $user->id);

    app(TenantStatusService::class)->setStatus($tenantId, 'suspended');

    $epoch = app(RealtimeAuthorizationEpochService::class)->currentEpoch($tenantId, $user->id);
    $channel = app(ChannelNameBuilder::class)->privateTenantUserEpoch($tenantId, $user->id, $epoch);

    $this->actingAs($user)
        ->withHeaders(['Origin' => 'http://blocked.localhost'])
        ->post('http://blocked.localhost/broadcasting/auth', phase6BroadcastAuthPayload($channel))
        ->assertStatus(423)
        ->assertJsonPath('code', 'TENANT_STATUS_BLOCKED')
        ->assertJsonPath('status', 'suspended');
});

test('tenant notification endpoints enforce anti idor ownership constraints', function () {
    $tenant = phase6CreateTenant('notify.localhost');
    $tenantId = (string) $tenant->id;

    $owner = User::factory()->create(['email_verified_at' => now()]);
    $otherUser = User::factory()->create(['email_verified_at' => now()]);

    phase6AttachMembership($tenantId, $owner->id);
    phase6AttachMembership($tenantId, $otherUser->id);

    $ownerNotification = phase6CreateNotification($tenantId, $owner->id, 1, 'evt-owner');
    $otherNotification = phase6CreateNotification($tenantId, $otherUser->id, 2, 'evt-other');

    $response = $this->actingAs($owner)
        ->getJson('http://notify.localhost/tenant/notifications');

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.id', (string) $ownerNotification->id);

    $this->actingAs($owner)
        ->patchJson("http://notify.localhost/tenant/notifications/{$ownerNotification->id}/read")
        ->assertOk()
        ->assertJsonPath('is_read', true);

    $this->actingAs($owner)
        ->patchJson("http://notify.localhost/tenant/notifications/{$otherNotification->id}/read")
        ->assertNotFound();

    $this->actingAs($owner)
        ->deleteJson("http://notify.localhost/tenant/notifications/{$otherNotification->id}")
        ->assertNotFound();

    $this->actingAs($owner)
        ->deleteJson("http://notify.localhost/tenant/notifications/{$ownerNotification->id}")
        ->assertStatus(204);

    $this->assertDatabaseMissing('notifications', [
        'id' => (string) $ownerNotification->id,
        'tenant_id' => $tenantId,
        'notifiable_id' => $owner->id,
    ]);
});

test('outbox flow is idempotent and processes each event into one notification', function () {
    Queue::fake();
    Event::fake([TenantNotificationBroadcasted::class]);

    $tenant = phase6CreateTenant('outbox.localhost');
    $tenantId = (string) $tenant->id;

    $user = User::factory()->create(['email_verified_at' => now()]);
    phase6AttachMembership($tenantId, $user->id);

    $service = app(NotificationOutboxService::class);
    $eventId = 'evt-'.Str::uuid()->toString();

    $first = $service->enqueue(
        tenantId: $tenantId,
        notifiableId: $user->id,
        eventType: 'tenant.note.created',
        payload: ['message' => 'Hello'],
        eventId: $eventId,
    );

    $second = $service->enqueue(
        tenantId: $tenantId,
        notifiableId: $user->id,
        eventType: 'tenant.note.created',
        payload: ['message' => 'Hello'],
        eventId: $eventId,
    );

    expect((string) $second->id)->toBe((string) $first->id);

    expect(TenantNotificationOutbox::query()
        ->where('tenant_id', $tenantId)
        ->where('event_id', $eventId)
        ->count())
        ->toBe(1);

    $job = new ProcessTenantNotificationOutboxJob((string) $first->id);
    $job->handle(app(TenantStatusService::class));

    $this->assertDatabaseHas('notifications', [
        'tenant_id' => $tenantId,
        'notifiable_id' => $user->id,
        'event_id' => $eventId,
        'event_type' => 'tenant.note.created',
    ]);

    $first->refresh();
    expect($first->processed_at)->not->toBeNull();

    Event::assertDispatched(TenantNotificationBroadcasted::class);

    $job->handle(app(TenantStatusService::class));

    expect(TenantNotification::query()
        ->where('tenant_id', $tenantId)
        ->where('notifiable_id', $user->id)
        ->where('event_id', $eventId)
        ->count())
        ->toBe(1);
});
