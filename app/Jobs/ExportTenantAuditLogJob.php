<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\TenantStatusBlockedException;
use App\Support\Phase4\Audit\AuditLogger;
use App\Support\Phase4\Audit\ForensicAuditRepository;
use App\Support\Phase4\Entitlements\EntitlementService;
use App\Support\Phase5\JobAbortTelemetry;
use App\Support\Phase5\TenantStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class ExportTenantAuditLogJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly int $actorId,
        public readonly string $fromIso8601,
        public readonly string $toIso8601,
        public readonly array $filters = [],
    ) {}

    public function handle(
        ForensicAuditRepository $repository,
        EntitlementService $entitlements,
        AuditLogger $auditLogger,
        TenantStatusService $tenantStatus,
        JobAbortTelemetry $jobAbortTelemetry,
    ): void {
        try {
            $tenantStatus->ensureActive($this->tenantId);
        } catch (TenantStatusBlockedException $exception) {
            $jobAbortTelemetry->recordTenantStatusAbort($exception->status());

            return;
        }

        $entitlements->ensure($this->tenantId, 'tenant.audit.export');

        $from = CarbonImmutable::parse($this->fromIso8601);
        $to = CarbonImmutable::parse($this->toIso8601);

        $rows = $repository->exportRows(
            tenantId: $this->tenantId,
            from: $from,
            to: $to,
            filters: $this->filters,
            limit: 5000,
        );

        try {
            $tenantStatus->ensureActive($this->tenantId);
        } catch (TenantStatusBlockedException $exception) {
            $jobAbortTelemetry->recordTenantStatusAbort($exception->status());

            return;
        }

        $disk = (string) config('phase4.audit.export_disk', 'local');
        $path = sprintf('phase4/audit/%s-%s.json', $this->tenantId, now()->format('YmdHis'));
        Storage::disk($disk)->put($path, (string) json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $auditLogger->log(
            event: 'audit.export.completed',
            tenantId: $this->tenantId,
            actorId: $this->actorId,
            properties: [
                'disk' => $disk,
                'path' => $path,
                'rows' => count($rows),
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
        );
    }
}
