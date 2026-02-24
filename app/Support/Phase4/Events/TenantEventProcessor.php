<?php

declare(strict_types=1);

namespace App\Support\Phase4\Events;

use App\Models\TenantEventDeduplication;
use App\Models\TenantEventDlq;
use App\Support\Phase4\Audit\AuditLogger;
use App\Support\Phase4\ProfileProjectionService;
use Throwable;

final class TenantEventProcessor
{
    public function __construct(
        private readonly ProfileProjectionService $projectionService,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function process(TenantEventEnvelope $envelope, bool $recordDlq = true): string
    {
        if (TenantEventDeduplication::query()->whereKey($envelope->eventId)->exists()) {
            $this->auditLogger->log(
                event: 's2s.event.duplicate_ignored',
                tenantId: $envelope->tenantId,
                actorId: null,
                properties: [
                    'event_id' => $envelope->eventId,
                    'event_name' => $envelope->eventName,
                ],
            );

            return 'duplicate';
        }

        try {
            $this->apply($envelope);

            TenantEventDeduplication::query()->create([
                'event_id' => $envelope->eventId,
                'tenant_id' => $envelope->tenantId,
                'event_name' => $envelope->eventName,
                'schema_version' => $envelope->schemaVersion,
                'retry_count' => $envelope->retryCount,
                'processed_at' => now(),
            ]);

            $this->auditLogger->log(
                event: 's2s.event.processed',
                tenantId: $envelope->tenantId,
                actorId: null,
                properties: [
                    'event_id' => $envelope->eventId,
                    'event_name' => $envelope->eventName,
                ],
            );

            return 'processed';
        } catch (Throwable $exception) {
            if ($recordDlq) {
                TenantEventDlq::query()->create([
                    'event_id' => $envelope->eventId,
                    'tenant_id' => $envelope->tenantId,
                    'event_name' => $envelope->eventName,
                    'schema_version' => $envelope->schemaVersion,
                    'retry_count' => $envelope->retryCount,
                    'payload' => $envelope->toArray(),
                    'failure_reason' => $exception->getMessage(),
                ]);
            }

            $this->auditLogger->log(
                event: 's2s.event.failed',
                tenantId: $envelope->tenantId,
                actorId: null,
                properties: [
                    'event_id' => $envelope->eventId,
                    'event_name' => $envelope->eventName,
                    'failure_reason' => $exception->getMessage(),
                ],
            );

            return 'failed';
        }
    }

    private function apply(TenantEventEnvelope $envelope): void
    {
        match ($envelope->eventName) {
            'ProfileProjectionUpserted' => $this->projectionService->upsert(
                tenantId: $envelope->tenantId,
                centralUserId: $envelope->subjectId,
                profile: $envelope->payload,
                occurredAt: $envelope->occurredAt,
            ),
            'TenantMembershipRevoked' => $this->projectionService->revokeMembership(
                tenantId: $envelope->tenantId,
                centralUserId: $envelope->subjectId,
            ),
            default => throw new \InvalidArgumentException('Unsupported event: '.$envelope->eventName),
        };
    }
}
