<?php

declare(strict_types=1);

namespace App\Support\Billing\Providers;

use App\Support\Billing\Contracts\SubscriptionProvider;
use App\Support\Billing\Data\BillingSubscriptionSnapshot;
use App\Support\Billing\Data\BillingWebhookEvent;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class ConfigBillingProvider implements SubscriptionProvider
{
    public function __construct(
        private readonly string $provider = 'local',
    ) {}

    public function verifyWebhookSignature(string $rawPayload, string $providedSignature): bool
    {
        $secret = (string) config("billing.providers.{$this->provider}.webhook_secret", '');

        if ($secret === '' || trim($providedSignature) === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawPayload, $secret);

        return hash_equals($expected, trim($providedSignature));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function parseWebhookPayload(array $payload): BillingWebhookEvent
    {
        $eventId = trim((string) ($payload['event_id'] ?? ''));
        $tenantId = trim((string) ($payload['tenant_id'] ?? ''));

        if ($eventId === '' || $tenantId === '') {
            throw new InvalidArgumentException('Invalid billing payload.');
        }

        return new BillingWebhookEvent(
            eventId: $eventId,
            tenantId: $tenantId,
            status: trim((string) ($payload['status'] ?? 'none')),
            providerObjectVersion: max(0, (int) ($payload['provider_object_version'] ?? 0)),
            providerCustomerId: isset($payload['provider_customer_id']) ? (string) $payload['provider_customer_id'] : null,
            providerSubscriptionId: isset($payload['provider_subscription_id']) ? (string) $payload['provider_subscription_id'] : null,
            currentPeriodEndsAt: isset($payload['current_period_ends_at'])
                ? CarbonImmutable::parse((string) $payload['current_period_ends_at'])
                : null,
            entitlements: $this->normalizeEntitlements($payload['entitlements'] ?? []),
            rawPayload: $payload,
        );
    }

    public function fetchSubscriptionSnapshot(string $tenantId): ?BillingSubscriptionSnapshot
    {
        $snapshot = config("billing.providers.{$this->provider}.reconciliation_snapshots.{$tenantId}");

        if (! is_array($snapshot)) {
            return null;
        }

        return new BillingSubscriptionSnapshot(
            tenantId: $tenantId,
            status: trim((string) ($snapshot['status'] ?? 'none')),
            providerObjectVersion: max(0, (int) ($snapshot['provider_object_version'] ?? 0)),
            providerCustomerId: isset($snapshot['provider_customer_id']) ? (string) $snapshot['provider_customer_id'] : null,
            providerSubscriptionId: isset($snapshot['provider_subscription_id']) ? (string) $snapshot['provider_subscription_id'] : null,
            currentPeriodEndsAt: isset($snapshot['current_period_ends_at'])
                ? CarbonImmutable::parse((string) $snapshot['current_period_ends_at'])
                : null,
            entitlements: $this->normalizeEntitlements($snapshot['entitlements'] ?? []),
        );
    }

    /**
     * @return array<string, bool>
     */
    private function normalizeEntitlements(mixed $entitlements): array
    {
        if (! is_array($entitlements)) {
            return [];
        }

        $normalized = [];

        foreach ($entitlements as $key => $value) {
            if (is_int($key)) {
                $feature = trim((string) $value);

                if ($feature !== '') {
                    $normalized[$feature] = true;
                }

                continue;
            }

            $feature = trim((string) $key);

            if ($feature === '') {
                continue;
            }

            $normalized[$feature] = (bool) $value;
        }

        return $normalized;
    }
}
