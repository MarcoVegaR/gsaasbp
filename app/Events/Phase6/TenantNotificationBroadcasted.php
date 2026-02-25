<?php

declare(strict_types=1);

namespace App\Events\Phase6;

use App\Support\Phase6\ChannelNameBuilder;
use App\Support\Phase6\RealtimeAuthorizationEpochService;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class TenantNotificationBroadcasted implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $tenantId,
        public readonly int $notifiableId,
        public readonly string $notificationId,
        public readonly string $eventType,
        public readonly int $version,
        public readonly int $sequence,
    ) {}

    public function broadcastOn(): array
    {
        /** @var ChannelNameBuilder $channels */
        $channels = app(ChannelNameBuilder::class);

        /** @var RealtimeAuthorizationEpochService $epochs */
        $epochs = app(RealtimeAuthorizationEpochService::class);

        $authzEpoch = $epochs->currentEpoch($this->tenantId, $this->notifiableId);

        return [
            new PrivateChannel($channels->tenantUserEpoch($this->tenantId, $this->notifiableId, $authzEpoch)),
        ];
    }

    public function broadcastAs(): string
    {
        return 'tenant.notification.pushed';
    }

    /**
     * @return array<string, int|string>
     */
    public function broadcastWith(): array
    {
        return [
            'notification_id' => $this->notificationId,
            'event_type' => $this->eventType,
            'version' => $this->version,
            'sequence' => $this->sequence,
        ];
    }
}
