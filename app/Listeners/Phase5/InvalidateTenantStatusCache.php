<?php

declare(strict_types=1);

namespace App\Listeners\Phase5;

use App\Events\Phase5\TenantStatusChanged;
use App\Support\Phase5\TenantStatusService;

final class InvalidateTenantStatusCache
{
    public function __construct(
        private readonly TenantStatusService $tenantStatus,
    ) {}

    public function handle(TenantStatusChanged $event): void
    {
        $this->tenantStatus->invalidate($event->tenantId);
    }
}
