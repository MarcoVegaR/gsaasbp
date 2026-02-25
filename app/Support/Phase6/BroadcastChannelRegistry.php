<?php

declare(strict_types=1);

namespace App\Support\Phase6;

use App\Exceptions\TenantStatusBlockedException;
use App\Models\User;
use App\Support\Phase5\TenantStatusService;
use Illuminate\Support\Facades\Broadcast;

final class BroadcastChannelRegistry
{
    public function __construct(
        private readonly ChannelNameBuilder $channelNames,
        private readonly RealtimeAuthorizationEpochService $authorizationEpochs,
        private readonly RealtimeMembershipService $membership,
        private readonly RealtimeCircuitBreaker $circuitBreaker,
        private readonly TenantStatusService $tenantStatus,
    ) {}

    public function register(): void
    {
        Broadcast::channel(
            $this->channelNames->tenantUserEpochRoutePattern(),
            function (User $user, string $tenantId, string $userId, string $authzEpoch): bool {
                $tenantId = trim($tenantId);
                $targetUserId = (int) $userId;

                if (! $this->matchesTenant($tenantId) || ! $this->matchesUser($user, $targetUserId)) {
                    return false;
                }

                if ($this->circuitBreaker->isTenantBlocked($tenantId)) {
                    return false;
                }

                try {
                    $this->tenantStatus->ensureActive($tenantId);
                } catch (TenantStatusBlockedException $exception) {
                    $this->circuitBreaker->markTenantBlocked($tenantId, $exception->status());

                    return false;
                }

                if (! $this->membership->isActive($tenantId, $targetUserId)) {
                    return false;
                }

                $expectedEpoch = $this->authorizationEpochs->currentEpoch($tenantId, $targetUserId);

                return $expectedEpoch === max(1, (int) $authzEpoch);
            },
            ['guards' => ['web']],
        );
    }

    private function matchesTenant(string $tenantId): bool
    {
        if ($tenantId === '') {
            return false;
        }

        $activeTenantId = (string) tenant()?->getTenantKey();

        return $activeTenantId !== '' && $activeTenantId === $tenantId;
    }

    private function matchesUser(User $user, int $targetUserId): bool
    {
        return $targetUserId > 0 && (int) $user->getAuthIdentifier() === $targetUserId;
    }
}
