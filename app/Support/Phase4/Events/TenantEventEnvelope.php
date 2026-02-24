<?php

declare(strict_types=1);

namespace App\Support\Phase4\Events;

use Carbon\CarbonImmutable;

final class TenantEventEnvelope
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $eventName,
        public readonly string $eventId,
        public readonly CarbonImmutable $occurredAt,
        public readonly string $tenantId,
        public readonly int $subjectId,
        public readonly string $schemaVersion,
        public readonly string $signature,
        public readonly int $retryCount,
        public readonly array $payload,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            eventName: (string) $validated['event_name'],
            eventId: (string) $validated['event_id'],
            occurredAt: CarbonImmutable::parse((string) $validated['occurred_at']),
            tenantId: (string) $validated['tenant_id'],
            subjectId: (int) $validated['subject_id'],
            schemaVersion: (string) $validated['schema_version'],
            signature: (string) $validated['signature'],
            retryCount: (int) $validated['retry_count'],
            payload: is_array($validated['payload'] ?? null) ? $validated['payload'] : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function signedPayload(): array
    {
        return [
            'event_name' => $this->eventName,
            'event_id' => $this->eventId,
            'occurred_at' => $this->occurredAt->toIso8601String(),
            'tenant_id' => $this->tenantId,
            'subject_id' => $this->subjectId,
            'schema_version' => $this->schemaVersion,
            'retry_count' => $this->retryCount,
            'payload' => $this->payload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...$this->signedPayload(),
            'signature' => $this->signature,
        ];
    }
}
