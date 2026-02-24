<?php

declare(strict_types=1);

namespace App\Support\Billing\Data;

use Carbon\CarbonImmutable;

final class BillingSubscriptionSnapshot
{
    /**
     * @param  array<string, bool>  $entitlements
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $status,
        public readonly int $providerObjectVersion,
        public readonly ?string $providerCustomerId,
        public readonly ?string $providerSubscriptionId,
        public readonly ?CarbonImmutable $currentPeriodEndsAt,
        public readonly array $entitlements,
    ) {}
}
