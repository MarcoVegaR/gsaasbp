<?php

declare(strict_types=1);

namespace App\Support\Billing\Contracts;

use App\Support\Billing\Data\BillingSubscriptionSnapshot;
use App\Support\Billing\Data\BillingWebhookEvent;

interface SubscriptionProvider
{
    public function verifyWebhookSignature(string $rawPayload, string $providedSignature): bool;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function parseWebhookPayload(array $payload): BillingWebhookEvent;

    public function fetchSubscriptionSnapshot(string $tenantId): ?BillingSubscriptionSnapshot;
}
