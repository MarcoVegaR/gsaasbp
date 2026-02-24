<?php

declare(strict_types=1);

namespace App\Support\Phase5;

use App\Models\PlatformStepUpCapability;
use Illuminate\Support\Str;

final class StepUpCapabilityService
{
    public function issue(
        int $platformUserId,
        string $sessionId,
        string $deviceFingerprint,
        string $scope,
        ?string $ipAddress,
        ?int $ttlSeconds = null,
    ): PlatformStepUpCapability {
        $ttl = max(60, (int) ($ttlSeconds ?? config('phase5.step_up.default_ttl_seconds', 600)));

        /** @var PlatformStepUpCapability $capability */
        $capability = PlatformStepUpCapability::query()->create([
            'capability_id' => (string) Str::uuid(),
            'platform_user_id' => $platformUserId,
            'session_id' => trim($sessionId),
            'device_fingerprint' => trim($deviceFingerprint),
            'scope' => trim($scope),
            'ip_address' => $this->normalizeIp($ipAddress),
            'expires_at' => now()->addSeconds($ttl),
            'consumed_at' => null,
        ]);

        return $capability;
    }

    public function consume(
        string $capabilityId,
        int $platformUserId,
        string $sessionId,
        string $deviceFingerprint,
        string $scope,
        ?string $ipAddress,
        bool $strictIp = false,
    ): bool {
        $query = PlatformStepUpCapability::query()
            ->whereKey($capabilityId)
            ->where('platform_user_id', $platformUserId)
            ->where('session_id', trim($sessionId))
            ->where('device_fingerprint', trim($deviceFingerprint))
            ->where('scope', trim($scope))
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now());

        if ($strictIp) {
            $query->where('ip_address', $this->normalizeIp($ipAddress));
        }

        $affectedRows = $query->update([
            'consumed_at' => now(),
            'updated_at' => now(),
        ]);

        return $affectedRows === 1;
    }

    private function normalizeIp(?string $ipAddress): ?string
    {
        $value = trim((string) $ipAddress);

        return $value !== '' ? $value : null;
    }
}
