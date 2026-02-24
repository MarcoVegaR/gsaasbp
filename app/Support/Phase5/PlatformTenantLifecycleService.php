<?php

declare(strict_types=1);

namespace App\Support\Phase5;

final class PlatformTenantLifecycleService
{
    public function __construct(
        private readonly PlatformContextStore $platformContextStore,
        private readonly TenantStatusService $tenantStatus,
    ) {}

    public function setTenantStatus(string $tenantId, string $status): void
    {
        $this->platformContextStore->require();
        $this->tenantStatus->setStatus($tenantId, $status);
    }
}
