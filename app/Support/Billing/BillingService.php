<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\BillingEventProcessed;
use App\Models\BillingIncident;
use App\Models\Tenant;
use App\Models\TenantEntitlement;
use App\Models\TenantSubscription;
use App\Support\Billing\Data\BillingWebhookEvent;
use App\Support\Phase4\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class BillingService
{
    public function __construct(
        private readonly BillingProviderRegistry $providers,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhook(string $provider, string $rawPayload, ?string $providedSignature, array $payload): string
    {
        $providerDriver = $this->providers->make($provider);

        if (! $providerDriver->verifyWebhookSignature($rawPayload, trim((string) $providedSignature))) {
            throw new AuthorizationException('Invalid billing webhook signature.');
        }

        return $this->processEvent($provider, $providerDriver->parseWebhookPayload($payload));
    }

    public function reconcileTenant(string $provider, string $tenantId): bool
    {
        $providerDriver = $this->providers->make($provider);
        $snapshot = $providerDriver->fetchSubscriptionSnapshot($tenantId);

        if ($snapshot === null) {
            return false;
        }

        $event = new BillingWebhookEvent(
            eventId: sprintf('reconcile:%s:%s:%d', $provider, $tenantId, $snapshot->providerObjectVersion),
            tenantId: $tenantId,
            status: $snapshot->status,
            providerObjectVersion: $snapshot->providerObjectVersion,
            providerCustomerId: $snapshot->providerCustomerId,
            providerSubscriptionId: $snapshot->providerSubscriptionId,
            currentPeriodEndsAt: $snapshot->currentPeriodEndsAt,
            entitlements: $snapshot->entitlements,
            rawPayload: [
                'source' => 'reconciliation',
            ],
        );

        $this->processEvent($provider, $event);

        return true;
    }

    public function reconcileAll(string $provider, ?string $tenantId = null): int
    {
        $tenantIds = $tenantId !== null
            ? [$tenantId]
            : Tenant::query()->pluck('id')->map(static fn (mixed $value): string => (string) $value)->all();

        $processed = 0;

        foreach ($tenantIds as $candidateTenantId) {
            if ($this->reconcileTenant($provider, $candidateTenantId)) {
                $processed++;
            }
        }

        return $processed;
    }

    public function processEvent(string $provider, BillingWebhookEvent $event): string
    {
        $outcomeHash = $this->outcomeHash($event);

        return DB::transaction(function () use ($provider, $event, $outcomeHash): string {
            $existing = BillingEventProcessed::query()->whereKey($event->eventId)->lockForUpdate()->first();

            if ($existing instanceof BillingEventProcessed) {
                if ($existing->outcome_hash !== $outcomeHash) {
                    BillingIncident::query()->create([
                        'tenant_id' => $event->tenantId,
                        'event_id' => $event->eventId,
                        'reason' => 'outcome_hash_divergence',
                        'expected_outcome_hash' => $existing->outcome_hash,
                        'actual_outcome_hash' => $outcomeHash,
                    ]);

                    $this->auditLogger->log(
                        event: 'billing.webhook.divergence_detected',
                        tenantId: $event->tenantId,
                        actorId: null,
                        properties: [
                            'event_id' => $event->eventId,
                            'provider' => $provider,
                        ],
                    );

                    return 'divergence';
                }

                return 'duplicate';
            }

            $subscription = TenantSubscription::query()
                ->where('tenant_id', $event->tenantId)
                ->where('provider', $provider)
                ->lockForUpdate()
                ->first();

            $status = 'processed';

            if ($subscription instanceof TenantSubscription
                && $event->providerObjectVersion < (int) $subscription->provider_object_version) {
                $status = 'out_of_order_ignored';
            } else {
                if (! $subscription instanceof TenantSubscription) {
                    $subscription = TenantSubscription::query()->create([
                        'tenant_id' => $event->tenantId,
                        'provider' => $provider,
                        'status' => 'none',
                        'provider_object_version' => 0,
                        'subscription_revision' => 0,
                    ]);
                }

                $subscription->forceFill([
                    'provider_customer_id' => $event->providerCustomerId,
                    'provider_subscription_id' => $event->providerSubscriptionId,
                    'status' => $event->status,
                    'provider_object_version' => $event->providerObjectVersion,
                    'subscription_revision' => (int) $subscription->subscription_revision + 1,
                    'current_period_ends_at' => $event->currentPeriodEndsAt,
                    'metadata' => [
                        'last_event_id' => $event->eventId,
                    ],
                ])->save();

                $this->syncEntitlements($event);
            }

            BillingEventProcessed::query()->create([
                'event_id' => $event->eventId,
                'tenant_id' => $event->tenantId,
                'provider' => $provider,
                'outcome_hash' => $outcomeHash,
                'provider_object_version' => $event->providerObjectVersion,
                'processed_at' => now(),
            ]);

            $this->auditLogger->log(
                event: 'billing.webhook.processed',
                tenantId: $event->tenantId,
                actorId: null,
                properties: [
                    'event_id' => $event->eventId,
                    'provider' => $provider,
                    'status' => $status,
                ],
            );

            return $status;
        });
    }

    private function outcomeHash(BillingWebhookEvent $event): string
    {
        return hash('sha256', (string) json_encode([
            'tenant_id' => $event->tenantId,
            'status' => $event->status,
            'provider_object_version' => $event->providerObjectVersion,
            'provider_customer_id' => $event->providerCustomerId,
            'provider_subscription_id' => $event->providerSubscriptionId,
            'current_period_ends_at' => $event->currentPeriodEndsAt?->toIso8601String(),
            'entitlements' => $event->entitlements,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function syncEntitlements(BillingWebhookEvent $event): void
    {
        foreach ($event->entitlements as $feature => $granted) {
            $cleanFeature = trim((string) $feature);

            if ($cleanFeature === '') {
                continue;
            }

            TenantEntitlement::query()->updateOrCreate(
                [
                    'tenant_id' => $event->tenantId,
                    'feature' => $cleanFeature,
                ],
                [
                    'granted' => (bool) $granted,
                    'source' => 'billing_webhook',
                    'updated_by_event_id' => $event->eventId,
                    'expires_at' => null,
                ],
            );
        }
    }
}
