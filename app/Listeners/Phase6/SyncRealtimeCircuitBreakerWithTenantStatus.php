<?php

declare(strict_types=1);

namespace App\Listeners\Phase6;

use App\Events\Phase5\TenantStatusChanged;
use App\Support\Phase6\RealtimeCircuitBreaker;

final class SyncRealtimeCircuitBreakerWithTenantStatus
{
    public function __construct(
        private readonly RealtimeCircuitBreaker $circuitBreaker,
    ) {}

    public function handle(TenantStatusChanged $event): void
    {
        $status = trim(strtolower($event->status));

        if (in_array($status, $this->blockedStatuses(), true)) {
            $this->circuitBreaker->markTenantBlocked($event->tenantId, $status);

            return;
        }

        $this->circuitBreaker->clearTenant($event->tenantId);
    }

    /**
     * @return list<string>
     */
    private function blockedStatuses(): array
    {
        $blockedStatuses = array_values(array_filter(array_map(
            static fn (string $value): string => trim(strtolower($value)),
            (array) config('phase5.tenant_status.blocked_statuses', ['suspended', 'hard_deleted']),
        ), static fn (string $value): bool => $value !== ''));

        return $blockedStatuses;
    }
}
