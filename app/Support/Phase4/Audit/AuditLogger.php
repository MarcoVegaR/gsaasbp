<?php

declare(strict_types=1);

namespace App\Support\Phase4\Audit;

use App\Models\ActivityLog;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

final class AuditLogger
{
    public function __construct(
        private readonly AuditSanitizer $sanitizer,
    ) {}

    /**
     * @param  array<string, mixed>  $properties
     */
    public function log(
        string $event,
        string $tenantId,
        int|string|null $actorId,
        array $properties = [],
        ?string $requestId = null,
        ?CarbonInterface $occurredAt = null,
    ): ActivityLog {
        $sanitized = $this->sanitizer->sanitize($properties);

        /** @var ActivityLog $entry */
        $entry = ActivityLog::query()->create([
            'tenant_id' => $tenantId,
            'event' => $event,
            'request_id' => $this->normalizeRequestId($requestId),
            'actor_id' => $actorId !== null ? (int) $actorId : null,
            'hmac_kid' => $sanitized['hmac_kid'],
            'properties' => $sanitized['properties'],
            'created_at' => $occurredAt,
        ]);

        return $entry;
    }

    private function normalizeRequestId(?string $requestId): string
    {
        $candidate = trim((string) ($requestId ?? request()?->headers->get('X-Request-Id', '')));

        return $candidate !== '' ? $candidate : (string) Str::uuid();
    }
}
