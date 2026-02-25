<?php

declare(strict_types=1);

namespace App\Support\Phase5;

use App\Support\SystemContext;

final class PlatformTenantLifecycleService
{
    public function __construct(
        private readonly PlatformContextStore $platformContextStore,
        private readonly TenantStatusService $tenantStatus,
    ) {}

    public function setTenantStatus(string $tenantId, string $status): void
    {
        $this->platformContextStore->require();

        SystemContext::execute(
            fn (): bool => tap(true, fn () => $this->tenantStatus->setStatus($tenantId, $status)),
            purpose: 'admin.tenant.status.update',
            targetTenantId: $tenantId,
        );
    }

    public function hardDeleteTenant(string $tenantId): void
    {
        $this->platformContextStore->require();

        SystemContext::execute(
            fn (): bool => tap(true, fn () => $this->tenantStatus->setStatus($tenantId, 'hard_deleted')),
            purpose: 'admin.tenant.hard-delete.execute',
            targetTenantId: $tenantId,
        );
    }
}
