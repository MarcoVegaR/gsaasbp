<?php

declare(strict_types=1);

namespace App\Support\Phase6;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

final class RealtimeCircuitBreaker
{
    public function isTenantBlocked(string $tenantId): bool
    {
        if ((bool) config('phase6.realtime.force_degraded', false)) {
            return true;
        }

        return $this->store()->has($this->key($tenantId));
    }

    public function markTenantBlocked(string $tenantId, string $status): void
    {
        $this->store()->put(
            $this->key($tenantId),
            $this->normalizeStatus($status),
            now()->addSeconds($this->ttlSeconds()),
        );
    }

    public function clearTenant(string $tenantId): void
    {
        $this->store()->forget($this->key($tenantId));
    }

    public function currentStatus(string $tenantId): ?string
    {
        $status = $this->store()->get($this->key($tenantId));

        return is_string($status) && $status !== '' ? $status : null;
    }

    private function store(): Repository
    {
        return Cache::store((string) config('phase6.realtime.cache_store', 'array'));
    }

    private function ttlSeconds(): int
    {
        return max(1, (int) config('phase6.realtime.degraded_ttl_seconds', 60));
    }

    private function key(string $tenantId): string
    {
        return 'phase6:realtime:tenant_blocked:'.trim($tenantId);
    }

    private function normalizeStatus(string $status): string
    {
        $value = trim(strtolower($status));

        return $value !== '' ? $value : 'unknown';
    }
}
