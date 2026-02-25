<?php

declare(strict_types=1);

namespace App\Jobs\Phase6;

use App\Events\Phase6\TenantNotificationBroadcasted;
use App\Exceptions\TenantStatusBlockedException;
use App\Models\TenantNotification;
use App\Models\TenantNotificationOutbox;
use App\Support\Phase5\TenantStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

final class ProcessTenantNotificationOutboxJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $outboxId,
    ) {}

    public function handle(TenantStatusService $tenantStatus): void
    {
        /** @var TenantNotificationOutbox|null $outbox */
        $outbox = TenantNotificationOutbox::query()->find($this->outboxId);

        if (! $outbox instanceof TenantNotificationOutbox) {
            return;
        }

        if ($outbox->processed_at !== null) {
            return;
        }

        try {
            $tenantStatus->ensureActive((string) $outbox->tenant_id);
        } catch (TenantStatusBlockedException) {
            $outbox->forceFill([
                'retry_count' => max(0, (int) $outbox->retry_count) + 1,
            ])->save();

            return;
        }

        /** @var TenantNotification $notification */
        $notification = TenantNotification::query()->firstOrNew([
            'tenant_id' => (string) $outbox->tenant_id,
            'notifiable_id' => (int) $outbox->notifiable_id,
            'event_id' => (string) $outbox->event_id,
        ]);

        if (! $notification->exists) {
            $notification->id = (string) Str::uuid();
        }

        $notification->forceFill([
            'event_type' => (string) $outbox->event_type,
            'version' => (int) $outbox->version,
            'payload' => $outbox->payload,
            'stream_key' => (string) $outbox->stream_key,
            'sequence' => (int) $outbox->sequence,
            'occurred_at' => $outbox->occurred_at,
            'is_read' => false,
            'read_at' => null,
        ])->save();

        $outbox->forceFill([
            'processed_at' => CarbonImmutable::now(),
        ])->save();

        event(new TenantNotificationBroadcasted(
            tenantId: (string) $notification->tenant_id,
            notifiableId: (int) $notification->notifiable_id,
            notificationId: (string) $notification->id,
            eventType: (string) $notification->event_type,
            version: (int) $notification->version,
            sequence: (int) $notification->sequence,
        ));
    }
}
