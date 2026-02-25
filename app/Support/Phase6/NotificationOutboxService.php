<?php

declare(strict_types=1);

namespace App\Support\Phase6;

use App\Jobs\Phase6\ProcessTenantNotificationOutboxJob;
use App\Models\TenantNotificationOutbox;
use App\Models\TenantNotificationStreamSequence;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class NotificationOutboxService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function enqueue(
        string $tenantId,
        int $notifiableId,
        string $eventType,
        array $payload = [],
        ?string $eventId = null,
        ?CarbonInterface $occurredAt = null,
    ): TenantNotificationOutbox {
        $tenantId = trim($tenantId);
        $eventType = trim($eventType);
        $resolvedEventId = trim((string) ($eventId ?? Str::uuid()));

        if ($tenantId === '' || $notifiableId <= 0 || $eventType === '' || $resolvedEventId === '') {
            throw new InvalidArgumentException('Invalid outbox payload.');
        }

        $occurredAt = $occurredAt !== null
            ? CarbonImmutable::instance($occurredAt)
            : CarbonImmutable::now();

        /** @var TenantNotificationOutbox $outbox */
        $outbox = DB::transaction(function () use ($tenantId, $notifiableId, $eventType, $payload, $resolvedEventId, $occurredAt): TenantNotificationOutbox {
            /** @var TenantNotificationOutbox|null $existing */
            $existing = TenantNotificationOutbox::query()
                ->where('tenant_id', $tenantId)
                ->where('event_id', $resolvedEventId)
                ->first();

            if ($existing instanceof TenantNotificationOutbox) {
                return $existing;
            }

            $streamKey = $this->streamKey($tenantId, $notifiableId);
            $sequence = $this->nextSequence($tenantId, $notifiableId, $streamKey);

            /** @var TenantNotificationOutbox $created */
            $created = TenantNotificationOutbox::query()->create([
                'id' => (string) Str::uuid(),
                'event_id' => $resolvedEventId,
                'tenant_id' => $tenantId,
                'notifiable_id' => $notifiableId,
                'event_type' => $eventType,
                'version' => 1,
                'payload' => $payload,
                'stream_key' => $streamKey,
                'sequence' => $sequence,
                'occurred_at' => $occurredAt,
                'retry_count' => 0,
            ]);

            return $created;
        });

        ProcessTenantNotificationOutboxJob::dispatch((string) $outbox->getKey())
            ->onQueue((string) config('phase6.outbox.default_queue', 'default'))
            ->afterCommit();

        return $outbox;
    }

    private function streamKey(string $tenantId, int $notifiableId): string
    {
        return sprintf('notification_stream:%s:%d', $tenantId, $notifiableId);
    }

    private function nextSequence(string $tenantId, int $notifiableId, string $streamKey): int
    {
        /** @var TenantNotificationStreamSequence|null $sequence */
        $sequence = TenantNotificationStreamSequence::query()
            ->where('tenant_id', $tenantId)
            ->where('notifiable_id', $notifiableId)
            ->where('stream_key', $streamKey)
            ->lockForUpdate()
            ->first();

        if (! $sequence instanceof TenantNotificationStreamSequence) {
            $sequence = TenantNotificationStreamSequence::query()->create([
                'tenant_id' => $tenantId,
                'notifiable_id' => $notifiableId,
                'stream_key' => $streamKey,
                'last_sequence' => 1,
            ]);

            return 1;
        }

        $next = max(0, (int) $sequence->last_sequence) + 1;

        $sequence->forceFill([
            'last_sequence' => $next,
        ])->save();

        return $next;
    }
}
