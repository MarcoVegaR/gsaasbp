<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Support\Billing\BillingService;
use App\Support\Phase4\Audit\AuditLogger;
use App\Support\Phase4\Entitlements\EntitlementService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconcileTenantBillingJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $provider,
        public readonly string $tenantId,
        public readonly ?int $actorId = null,
    ) {}

    public function handle(BillingService $billingService, AuditLogger $auditLogger, EntitlementService $entitlements): void
    {
        $entitlements->ensure($this->tenantId, 'tenant.billing.reconcile');

        $reconciled = $billingService->reconcileTenant($this->provider, $this->tenantId);

        $auditLogger->log(
            event: 'billing.reconciliation.completed',
            tenantId: $this->tenantId,
            actorId: $this->actorId,
            properties: [
                'provider' => $this->provider,
                'reconciled' => $reconciled,
            ],
        );
    }
}
