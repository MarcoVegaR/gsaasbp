<?php

declare(strict_types=1);

namespace App\Support\Phase6;

final class ChannelNameBuilder
{
    private const TENANT_USER_EPOCH_REGEX = '/^tenant\.([^\.]+)\.user\.([0-9]+)\.epoch\.([0-9]+)$/';

    public function tenantUserEpoch(int|string $tenantId, int|string $userId, int $authzEpoch): string
    {
        return sprintf(
            'tenant.%s.user.%s.epoch.%d',
            trim((string) $tenantId),
            trim((string) $userId),
            max(1, $authzEpoch),
        );
    }

    public function privateTenantUserEpoch(int|string $tenantId, int|string $userId, int $authzEpoch): string
    {
        return 'private-'.$this->tenantUserEpoch($tenantId, $userId, $authzEpoch);
    }

    public function tenantUserEpochRoutePattern(): string
    {
        return 'tenant.{tenantId}.user.{userId}.epoch.{authzEpoch}';
    }

    /**
     * @return array{tenant_id: string, user_id: int, authz_epoch: int}|null
     */
    public function parseTenantUserEpoch(string $channelName): ?array
    {
        $normalized = trim($channelName);

        foreach (['private-encrypted-', 'private-', 'presence-'] as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                $normalized = substr($normalized, strlen($prefix));
                break;
            }
        }

        if (! preg_match(self::TENANT_USER_EPOCH_REGEX, $normalized, $matches)) {
            return null;
        }

        return [
            'tenant_id' => (string) $matches[1],
            'user_id' => (int) $matches[2],
            'authz_epoch' => max(1, (int) $matches[3]),
        ];
    }

    public function echoPrivateChannelFromServerChannel(string $serverChannel): string
    {
        return str_starts_with($serverChannel, 'private-')
            ? substr($serverChannel, strlen('private-'))
            : $serverChannel;
    }
}
