<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\TenantStatusBlockedException;
use App\Support\Billing\BillingService;
use App\Support\Phase4\Audit\AuditLogger;
use App\Support\Phase4\Entitlements\EntitlementService;
use App\Support\Phase5\JobAbortTelemetry;
use App\Support\Phase5\TenantStatusService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;

class ReconcileTenantBillingJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $provider,
        public readonly string $tenantId,
        public readonly ?int $actorId = null,
    ) {}

    public function handle(
        BillingService $billingService,
        AuditLogger $auditLogger,
        EntitlementService $entitlements,
        ?TenantStatusService $tenantStatus = null,
        ?JobAbortTelemetry $jobAbortTelemetry = null,
    ): void {
        $tenantStatus ??= app(TenantStatusService::class);
        $jobAbortTelemetry ??= app(JobAbortTelemetry::class);

        try {
            $tenantStatus->ensureActive($this->tenantId);
        } catch (TenantStatusBlockedException $exception) {
            $jobAbortTelemetry->recordTenantStatusAbort($exception->status());

            return;
        } catch (InvalidArgumentException) {
            // Unknown tenants are handled by downstream fail-closed contracts.
        }

        $entitlements->ensure($this->tenantId, 'tenant.billing.reconcile');

        $reconciled = $billingService->reconcileTenant($this->provider, $this->tenantId);

        try {
            $tenantStatus->ensureActive($this->tenantId);
        } catch (TenantStatusBlockedException $exception) {
            $jobAbortTelemetry->recordTenantStatusAbort($exception->status());

            return;
        } catch (InvalidArgumentException) {
            // Unknown tenants are handled by downstream fail-closed contracts.
        }

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
