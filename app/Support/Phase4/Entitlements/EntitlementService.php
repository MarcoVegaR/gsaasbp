<?php

declare(strict_types=1);

namespace App\Support\Phase4\Entitlements;

use App\Models\TenantEntitlement;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

final class EntitlementService
{
    public function isGranted(string $tenantId, string $feature): bool
    {
        $entitlement = TenantEntitlement::query()
            ->where('tenant_id', $tenantId)
            ->where('feature', $feature)
            ->first();

        if (! $entitlement instanceof TenantEntitlement) {
            return (bool) config('phase4.entitlements.default_granted', false);
        }

        if (! $entitlement->granted) {
            return false;
        }

        if ($entitlement->expires_at === null) {
            return true;
        }

        return CarbonImmutable::instance($entitlement->expires_at)->isFuture();
    }

    public function ensure(string $tenantId, string $feature): void
    {
        if (! $this->isGranted($tenantId, $feature)) {
            throw new AuthorizationException('BILLING_REQUIRED');
        }
    }
}
